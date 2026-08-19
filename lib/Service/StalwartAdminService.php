<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP/JMAP client for the Stalwart 0.16+ management surface.
 *
 * Stalwart 0.16 dropped the legacy REST admin API in favour of JMAP objects
 * exposed under `<api_url>/jmap`. The capability namespace is
 * `urn:stalwart:jmap` and Stalwart-specific objects (AppPassword, Account,
 * Domain, ApiKey, …) are addressed as `x:<ObjectType>/<function>` — verified
 * against upstream `crates/jmap-proto/src/request/method.rs` line 238–240
 * (`format!("x:{}/{}", obj.as_str(), method.as_str())`).
 *
 * The base URL is read from the system-config key
 * `souvera_central.stalwart_api_url` — that key is populated by the parallel-
 * installed Souvera Central app and matches the URL Stalwart is reachable
 * on from inside the Nextcloud pod (typically `http://stalwart:8080`).
 *
 * Authentication is per-call:
 *  - User-scoped operations (list / create / revoke own AppPasswords) pass
 *    a Bearer JWT issued by H2CK/oidc via {@see OidcProviderService}.
 *  - Admin-scoped operations would use HTTP Basic with the credentials in
 *    `souvera_central.stalwart_admin_user` / `…_password`, but Stalwart 0.16
 *    explicitly forbids admins from *creating* AppPasswords on behalf of a
 *    user (only view + revoke), so souvera_mail uses user JWTs exclusively.
 */
class StalwartAdminService
{
    public const SYSTEM_CONFIG_API_URL = 'souvera_central.stalwart_api_url';
    public const SYSTEM_CONFIG_ADMIN_USER = 'souvera_central.stalwart_admin_user';
    public const SYSTEM_CONFIG_ADMIN_PASSWORD = 'souvera_central.stalwart_admin_password';
    public const JMAP_PATH = '/jmap';
    public const SESSION_PATH = '/jmap/session';
    public const CAPABILITY = 'urn:stalwart:jmap';

    private const HTTP_TIMEOUT_SECONDS = 8;

    /**
     * Alphabet Stalwart uses to encode/decode JMAP `Id` values as strings
     * (e.g. the `accountId` in `/jmap/session` `primaryAccounts`, or any
     * `ids` argument to a `/get` method) — verified against upstream
     * `crates/types/src/id.rs` (`BASE32_ALPHABET`) and
     * `crates/utils/src/codec/base32_custom.rs` (`stalwartlabs/stalwart`
     * @ main, checked 2026-07-21).
     *
     * Decoding (`Id::from_str`) is a plain MSB-first base-32 read —
     * `id = (id << 5) | digit` per character, no special-casing. Stalwart's
     * own encoder (`Id::as_string`) additionally strips leading zero-value
     * digits for a minimal/canonical string, but since decode does not
     * care about that, a plain divmod-32 encode below round-trips to the
     * exact same numeric value Stalwart would decode it to.
     */
    private const JMAP_ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz792013';

    public function __construct(
        private IConfig $config,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return non-empty-string|null
     */
    public function getApiUrl(): ?string
    {
        $url = (string) $this->config->getSystemValue(self::SYSTEM_CONFIG_API_URL, '');
        $url = \rtrim(\trim($url), '/');
        return $url !== '' ? $url : null;
    }

    public function isConfigured(): bool
    {
        return $this->getApiUrl() !== null;
    }

    /**
     * Basic-auth credentials for the Stalwart admin API. Populated by the
     * `souvera_central` app when it provisions the mail server. Returns null
     * when either half is missing — callers must treat that as "admin ops
     * are unavailable" and NOT fall back to guessing defaults.
     *
     * @return array{0: non-empty-string, 1: non-empty-string}|null
     */
    public function getAdminCredentials(): ?array
    {
        $user = \trim((string) $this->config->getSystemValue(self::SYSTEM_CONFIG_ADMIN_USER, ''));
        $pw = (string) $this->config->getSystemValue(self::SYSTEM_CONFIG_ADMIN_PASSWORD, '');
        if ($user === '' || $pw === '') {
            return null;
        }
        return [$user, $pw];
    }

    /**
     * Performs a JMAP request envelope with the supplied method calls.
     *
     * @param non-empty-string $bearerToken RFC 9068 JWT acquired via H2CK/oidc
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $methodCalls
     * @param list<string> $extraCapabilities Additional capability URIs to include
     *     in the `using` array — required for methods outside the default core +
     *     Stalwart extension scope (e.g. `urn:ietf:params:jmap:submission` for
     *     `Identity/get`, `urn:ietf:params:jmap:mail` for `Mailbox/get`).
     * @return array<string, mixed> Decoded JMAP response
     * @throws \RuntimeException on HTTP / JMAP / connectivity failure
     */
    public function jmapCall(string $bearerToken, array $methodCalls, array $extraCapabilities = []): array
    {
        $url = $this->getApiUrl();
        if ($url === null) {
            throw new \RuntimeException('Stalwart API URL not configured (souvera_central.stalwart_api_url)');
        }

        $using = ['urn:ietf:params:jmap:core', self::CAPABILITY];
        foreach ($extraCapabilities as $cap) {
            if (\is_string($cap) && $cap !== '' && !\in_array($cap, $using, true)) {
                $using[] = $cap;
            }
        }

        $envelope = [
            'using' => $using,
            'methodCalls' => $methodCalls,
        ];

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($url . self::JMAP_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearerToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => (string) \json_encode($envelope, JSON_UNESCAPED_SLASHES),
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: Stalwart JMAP call failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw new \RuntimeException('Stalwart JMAP request failed: ' . $e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        $decoded = \json_decode($body, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Stalwart JMAP response is not JSON: ' . \substr($body, 0, 200));
        }
        return $decoded;
    }

    /**
     * Performs a JMAP request using Basic-auth admin credentials from
     * `souvera_central.stalwart_admin_user` + `…_password`. Used ONLY by
     * privileged flows that user JWTs cannot cover — currently the
     * `souvera_mail:warmup-oidc` command which forces Stalwart to re-fetch
     * its cached OIDC discovery + JWKS after a fresh deploy.
     *
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $methodCalls
     * @param list<string> $extraCapabilities
     * @return array<string, mixed> Decoded JMAP response
     * @throws \RuntimeException on missing admin creds / HTTP / JMAP failure
     */
    public function jmapCallAsAdmin(array $methodCalls, array $extraCapabilities = []): array
    {
        $url = $this->getApiUrl();
        if ($url === null) {
            throw new \RuntimeException('Stalwart API URL not configured (souvera_central.stalwart_api_url)');
        }
        $creds = $this->getAdminCredentials();
        if ($creds === null) {
            throw new \RuntimeException(
                'Stalwart admin credentials not configured — set the system-config keys '
                . self::SYSTEM_CONFIG_ADMIN_USER . ' + ' . self::SYSTEM_CONFIG_ADMIN_PASSWORD
                . ' (normally provisioned by the souvera_central app).'
            );
        }

        $using = ['urn:ietf:params:jmap:core', self::CAPABILITY];
        foreach ($extraCapabilities as $cap) {
            if (\is_string($cap) && $cap !== '' && !\in_array($cap, $using, true)) {
                $using[] = $cap;
            }
        }

        $envelope = [
            'using' => $using,
            'methodCalls' => $methodCalls,
        ];

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($url . self::JMAP_PATH, [
                'headers' => [
                    'Authorization' => 'Basic ' . \base64_encode($creds[0] . ':' . $creds[1]),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => (string) \json_encode($envelope, JSON_UNESCAPED_SLASHES),
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: Stalwart admin JMAP call failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw new \RuntimeException('Stalwart admin JMAP request failed: ' . $e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        $decoded = \json_decode($body, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Stalwart admin JMAP response is not JSON: ' . \substr($body, 0, 200));
        }
        return $decoded;
    }

    /**
     * Probes `GET /jmap/session` with a Bearer OIDC JWT and returns the HTTP
     * status code. Used by the warmup command to detect whether Stalwart is
     * currently able to validate H2CK/oidc-issued JWTs. Does NOT parse the
     * body — a 200 means auth worked, anything else (401 typically) means it
     * did not.
     *
     * @param non-empty-string $bearerToken
     */
    public function probeSessionAsUser(string $bearerToken): int
    {
        return $this->fetchSessionAsUser($bearerToken)['status'];
    }

    /**
     * Full version of {@see probeSessionAsUser} that ALSO returns the decoded
     * JMAP session body. Used by the mailbox-access guard to compare the
     * account Stalwart maps the JWT to against the email Souvera Mail
     * *thinks* it should be opening.
     *
     * The session response (JMAP RFC 8620 §2) carries:
     *   - `username`: the authenticated principal name
     *   - `primaryAccounts`: map of capability URI → default accountId
     *   - `accounts`: map of accountId → account descriptor (with `name`)
     *
     * @param non-empty-string $bearerToken
     * @return array{status: int, body: array<string, mixed>}
     */
    public function fetchSessionAsUser(string $bearerToken): array
    {
        $url = $this->getApiUrl();
        if ($url === null) {
            throw new \RuntimeException('Stalwart API URL not configured (souvera_central.stalwart_api_url)');
        }

        try {
            $client = $this->clientService->newClient();
            $response = $client->get($url . self::SESSION_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearerToken,
                    'Accept' => 'application/json',
                ],
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
                'http_errors' => false,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\OCP\Http\Client\LocalServerException $e) {
            throw new \RuntimeException(
                'Stalwart API URL is a local/private address blocked by Nextcloud outbound policy — '
                . 'set `allow_local_remote_servers = true` in config.php or use a routable hostname: '
                . $e->getMessage(),
                0,
                $e
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('Stalwart /jmap/session fetch failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = [];
        if ($status === 200) {
            $decoded = \json_decode((string) $response->getBody(), true);
            if (\is_array($decoded)) {
                $body = $decoded;
            }
        }
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Encodes a Stalwart-internal numeric id (e.g. the `accountId` carried
     * by the `message-ingest.*` webhook event — see
     * {@see \OCA\SouveraMail\Controller\StalwartWebhookController}) into
     * the base32 string form Stalwart's JMAP wire protocol expects for any
     * `ids` argument. See {@see self::JMAP_ID_ALPHABET} for the verified
     * source-of-truth reference.
     */
    public static function encodeJmapId(int $numericId): string
    {
        if ($numericId <= 0) {
            return self::JMAP_ID_ALPHABET[0];
        }
        $encoded = '';
        while ($numericId > 0) {
            $encoded = self::JMAP_ID_ALPHABET[$numericId & 0x1F] . $encoded;
            $numericId >>= 5;
        }
        return $encoded;
    }

    /**
     * Resolves a Stalwart-internal numeric accountId — as carried by the
     * `message-ingest.*` webhook event, see
     * {@see \OCA\SouveraMail\Controller\StalwartWebhookController} — to
     * the principal's e-mail/login, via the admin-only `Principal/get`
     * JMAP method.
     *
     * `Principal/get` is the ONE JMAP method in Stalwart's whole surface
     * that does not validate/scope its `accountId` request field against
     * the caller — verified against upstream `crates/jmap/src/api/request.rs`
     * (no `assert_has_access`/`assert_is_member` call for
     * `GetRequestMethod::Principal`, unlike every other `/get` method) and
     * `crates/jmap/src/principal/get.rs` (looks `ids` up directly against
     * the global principal registry, `request.account_id` is only echoed
     * back in the response). That means Basic-auth ADMIN credentials alone
     * are sufficient here — no per-user OIDC bearer is needed (unlike
     * {@see \OCA\SouveraMail\Service\StalwartUserContext::resolveAccountId()},
     * which needs a bearer for a user we don't know yet at this point).
     *
     * @return non-empty-string|null null if the principal does not exist,
     *     admin credentials/API URL are not configured, or on any failure
     */
    public function lookupPrincipalEmailByAccountId(int $accountId): ?string
    {
        if (!$this->isConfigured() || $this->getAdminCredentials() === null) {
            return null;
        }

        try {
            $response = $this->jmapCallAsAdmin(
                [
                    ['Principal/get', [
                        'ids' => [self::encodeJmapId($accountId)],
                        'properties' => ['email', 'name'],
                    ], 'p0'],
                ],
                ['urn:ietf:params:jmap:principals'],
            );
            $result = $this->extractMethodResponse($response, 'Principal/get');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: Stalwart Principal/get lookup failed for accountId '
                . $accountId . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return null;
        }

        $principal = $result['list'][0] ?? null;
        if (!\is_array($principal)) {
            return null;
        }
        $email = $principal['email'] ?? $principal['name'] ?? null;
        return \is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Extracts the response body of a specific method call from a full JMAP
     * response envelope. Throws on JMAP-level errors or missing method.
     *
     * @param array<string, mixed> $jmapResponse
     * @return array<string, mixed>
     */
    public function extractMethodResponse(array $jmapResponse, string $expectedMethod): array
    {
        $calls = $jmapResponse['methodResponses'] ?? [];
        if (!\is_array($calls)) {
            throw new \RuntimeException('Stalwart JMAP envelope missing methodResponses');
        }
        foreach ($calls as $call) {
            if (!\is_array($call) || \count($call) < 2) {
                continue;
            }
            $name = (string) ($call[0] ?? '');
            if ($name === 'error') {
                $err = $call[1] ?? [];
                throw new \RuntimeException(
                    'Stalwart JMAP error: ' . \json_encode($err, JSON_UNESCAPED_SLASHES)
                );
            }
            if ($name === $expectedMethod && \is_array($call[1])) {
                return $call[1];
            }
        }
        throw new \RuntimeException("Stalwart JMAP response did not include {$expectedMethod}");
    }
}
