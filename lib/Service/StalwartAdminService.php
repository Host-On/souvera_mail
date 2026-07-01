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
                // Do NOT throw on 4xx/5xx — we WANT the status code.
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
            throw new \RuntimeException('Stalwart /jmap/session probe failed: ' . $e->getMessage(), 0, $e);
        }
        return $response->getStatusCode();
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
