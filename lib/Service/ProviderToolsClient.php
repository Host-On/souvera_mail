<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for the provider.tools IMAP migration endpoints
 * (see docs at https://provider.tools/api-docs, PDF anchored in the
 * SEG-2026-02 handoff bundle).
 *
 * Scope
 * -----
 * We consume EXACTLY four endpoints of provider.tools v1:
 *   POST /imap/test-connection   → pre-flight source-cred check (bool ok)
 *   POST /imap/list-folders      → pre-flight source-folder inventory
 *   POST /imap/migrate           → start migration, returns migrationId
 *   GET  /imap/migrate/{id}      → poll status/progress
 *
 * There is deliberately NO cancel endpoint at provider.tools. Once a
 * job is queued the user cannot abort it — we surface that fact in
 * the wizard UX (§step-3 confirm-splash) and let the job run to a
 * terminal `completed` or `failed` state.
 *
 * Authentication
 * --------------
 * `Authorization: Bearer <token>` — the token itself is retrieved from
 * souvera_central via {@see \OCA\SouveraCentral\Service\ProviderTokenService::getToken()}.
 * Central owns the token (single source of truth across Souvera apps)
 * and hands us a decrypted string per call. If Central is not enabled
 * or the token is not set we throw {@see ProviderToolsUnavailable}
 * BEFORE touching the network.
 *
 * Timeouts
 * --------
 * - test-connection: 15s (some source servers are slow to greet)
 * - list-folders:    30s (LIST on a huge mailbox can take a while)
 * - migrate (start): 30s (queue insert only, not actual copy)
 * - status:          10s (should be near-instant)
 *
 * All calls set `http_errors = false` so the caller inspects the
 * response status, and pin `verify = true` (never accept invalid TLS
 * on a Bearer-token endpoint).
 */
class ProviderToolsClient
{
    /** Provider.tools API base URL, pinned to v1. */
    private const BASE_URL = 'https://provider.tools/api/v1';

    /** Souvera-Central FQN kept as a string so this class stays loadable
     *  even in an install where the central app has not (yet) been
     *  enabled. Same pattern as StalwartUserContext. */
    private const TOKEN_SERVICE_FQN = 'OCA\\SouveraCentral\\Service\\ProviderTokenService';

    public function __construct(
        private IClientService $httpClientService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * True if souvera_central is enabled AND has a provider.tools token
     * set. Called by the controller BEFORE offering the wizard so users
     * on a mis-configured tenant get a friendly "Der Import ist auf
     * dieser Instanz nicht aktiviert" screen instead of a 500.
     */
    public function isAvailable(): bool
    {
        return $this->getToken() !== null;
    }

    /**
     * @param array{host: string, port: int, user: string, password: string, secure: bool} $source
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $source): array
    {
        $resp = $this->post('/imap/test-connection', $source, timeout: 15);
        $body = $this->decode($resp);
        return [
            'success' => (bool) ($body['success'] ?? false),
            // Success responses carry "message", failures carry "error".
            'message' => (string) ($body['message'] ?? $body['error'] ?? ''),
        ];
    }

    /**
     * @param array{host: string, port: int, user: string, password: string, secure: bool} $source
     *
     * @return array{
     *   success: bool,
     *   totalFolders: int,
     *   totalMessages: int,
     *   folders: list<array{path: string, messages: int}>,
     *   message?: string
     * }
     */
    public function listFolders(array $source): array
    {
        $resp = $this->post('/imap/list-folders', $source, timeout: 30);
        $body = $this->decode($resp);
        // Current API nests the result in "data" (see provider.tools API
        // docs §7.2). Older responses carried folders at top level —
        // tolerate both so a provider.tools change can never empty the
        // folder mapping again.
        $data = \is_array($body['data'] ?? null) ? $body['data'] : $body;
        $folders = [];
        foreach ($data['folders'] ?? [] as $f) {
            if (!\is_array($f)) {
                continue;
            }
            $folders[] = [
                'path' => (string) ($f['path'] ?? ''),
                'messages' => (int) ($f['messages'] ?? 0),
            ];
        }
        return [
            'success' => (bool) ($body['success'] ?? false),
            'totalFolders' => (int) ($data['totalFolders'] ?? \count($folders)),
            'totalMessages' => (int) ($data['totalMessages'] ?? 0),
            'folders' => $folders,
            'message' => isset($body['message']) ? (string) $body['message'] : (string) ($body['error'] ?? ''),
        ];
    }

    /**
     * @param array{host: string, port: int, user: string, password: string, secure: bool} $source
     * @param array{host: string, port: int, user: string, password: string, secure: bool} $destination
     * @param list<string> $folders  Empty list = ALL folders (provider.tools default).
     * @param string $destPrefix     Empty = migrate into root of destination.
     *
     * @return array{
     *   success: bool,
     *   migrationId: string,
     *   queue?: array{position: int, totalInQueue: int},
     *   message?: string
     * }
     */
    public function startMigration(
        array $source,
        array $destination,
        array $folders = [],
        string $destPrefix = '',
    ): array {
        $payload = ['source' => $source, 'destination' => $destination];
        if ($folders !== []) {
            $payload['folders'] = $folders;
        }
        if ($destPrefix !== '') {
            $payload['destPrefix'] = $destPrefix;
        }
        $resp = $this->post('/imap/migrate', $payload, timeout: 30);
        $body = $this->decode($resp);
        return [
            'success' => (bool) ($body['success'] ?? false),
            'migrationId' => (string) ($body['migrationId'] ?? ''),
            'queue' => isset($body['queue']) && \is_array($body['queue']) ? [
                'position' => (int) ($body['queue']['position'] ?? 0),
                'totalInQueue' => (int) ($body['queue']['totalInQueue'] ?? 0),
            ] : ['position' => 0, 'totalInQueue' => 0],
            'message' => (string) ($body['message'] ?? $body['error'] ?? ''),
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   progress: array{
     *     foldersDone: int, foldersTotal: int,
     *     messagesDone: int, messagesTotal: int
     *   },
     *   queue: ?array{position: int, totalInQueue: int},
     *   error?: string,
     *   raw: array<string, mixed>
     * }
     */
    public function getStatus(string $migrationId): array
    {
        if ($migrationId === '') {
            throw new \InvalidArgumentException('migrationId must not be empty');
        }
        $resp = $this->get('/imap/migrate/' . \rawurlencode($migrationId), timeout: 10);
        $body = $this->decode($resp);
        $progress = $body['progress'] ?? [];
        $queue = $body['queue'] ?? null;
        return [
            'status' => (string) ($body['status'] ?? 'pending'),
            'progress' => [
                'foldersDone'    => (int) ($progress['foldersDone']    ?? 0),
                'foldersTotal'   => (int) ($progress['foldersTotal']   ?? 0),
                'messagesDone'   => (int) ($progress['messagesDone']   ?? 0),
                'messagesTotal'  => (int) ($progress['messagesTotal']  ?? 0),
            ],
            'queue' => \is_array($queue) ? [
                'position' => (int) ($queue['position'] ?? 0),
                'totalInQueue' => (int) ($queue['totalInQueue'] ?? 0),
            ] : null,
            'error' => isset($body['error']) ? (string) $body['error'] : '',
            'raw' => \is_array($body) ? $body : [],
        ];
    }

    /**
     * Resolve the provider.tools API token from souvera_central. Read-only
     * consumer of ProviderTokenService per the SHARED_PROVIDER_TOKEN.md
     * contract — we NEVER set/clear from here.
     *
     * @return ?string  null if central is not enabled OR no token is set
     *                  OR decryption failed (e.g. NC secret was rotated
     *                  after the token was stored — operator has to re-set).
     */
    private function getToken(): ?string
    {
        if (!\class_exists(self::TOKEN_SERVICE_FQN, true)) {
            return null;
        }
        try {
            $svc = \OCP\Server::get(self::TOKEN_SERVICE_FQN);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Souvera Mail: ProviderTokenService not resolvable: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return null;
        }
        if (!\is_object($svc) || !\method_exists($svc, 'getToken')) {
            return null;
        }
        try {
            /** @var mixed $token */
            $token = $svc->getToken();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: ProviderTokenService::getToken() threw: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return null;
        }
        if (!\is_string($token) || $token === '') {
            return null;
        }
        return $token;
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, int $timeout): IResponse
    {
        return $this->request('POST', $path, $body, $timeout);
    }

    private function get(string $path, int $timeout): IResponse
    {
        return $this->request('GET', $path, null, $timeout);
    }

    /**
     * @param ?array<string, mixed> $body
     *
     * @throws ProviderToolsUnavailable
     */
    private function request(string $method, string $path, ?array $body, int $timeout): IResponse
    {
        $token = $this->getToken();
        if ($token === null) {
            throw new ProviderToolsUnavailable(
                'provider.tools API token is not configured in Souvera Central. '
                . 'Ask your operator to run: '
                . 'occ souvera:provider-token:set --stdin'
            );
        }
        $client = $this->httpClientService->newClient();
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'timeout' => $timeout,
            'connect_timeout' => 10,
            'verify' => true,
            'http_errors' => false,
            // Best-practice for a stateless REST client: bypass any
            // upstream cache — status responses MUST reflect the current
            // provider.tools state, and TLS libs occasionally cache 401s.
            'nextcloud' => ['allow_local_address' => false],
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $url = self::BASE_URL . $path;
        try {
            return match ($method) {
                'GET'  => $client->get($url, $options),
                'POST' => $client->post($url, $options),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };
        } catch (\Throwable $e) {
            throw new ProviderToolsUnavailable(
                "provider.tools {$method} {$path} failed: " . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(IResponse $resp): array
    {
        $status = $resp->getStatusCode();
        $raw = (string) $resp->getBody();
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            $decoded = [];
        }
        if ($status >= 400) {
            $msg = 'provider.tools HTTP ' . $status;
            if (isset($decoded['error'])) {
                $msg .= ': ' . (string) $decoded['error'];
            } elseif (isset($decoded['message'])) {
                $msg .= ': ' . (string) $decoded['message'];
            } elseif ($raw !== '') {
                // Trim large HTML error pages down so we don't blow up
                // nextcloud.log on a 502 from a WAF in front of provider.tools.
                $msg .= ': ' . \mb_substr(\strip_tags($raw), 0, 200);
            }
            throw new ProviderToolsUnavailable($msg);
        }
        return $decoded;
    }
}
