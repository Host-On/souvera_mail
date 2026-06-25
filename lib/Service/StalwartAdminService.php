<?php

declare(strict_types=1);

namespace OCA\Smail\Service;

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
 *    user (only view + revoke), so smail uses user JWTs exclusively.
 */
class StalwartAdminService
{
    public const SYSTEM_CONFIG_API_URL = 'souvera_central.stalwart_api_url';
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
     * Performs a JMAP request envelope with the supplied method calls.
     *
     * @param non-empty-string $bearerToken RFC 9068 JWT acquired via H2CK/oidc
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $methodCalls
     * @return array<string, mixed> Decoded JMAP response
     * @throws \RuntimeException on HTTP / JMAP / connectivity failure
     */
    public function jmapCall(string $bearerToken, array $methodCalls): array
    {
        $url = $this->getApiUrl();
        if ($url === null) {
            throw new \RuntimeException('Stalwart API URL not configured (souvera_central.stalwart_api_url)');
        }

        $envelope = [
            'using' => ['urn:ietf:params:jmap:core', self::CAPABILITY],
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
                ['app' => 'smail', 'exception' => $e]
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
}
