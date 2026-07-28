<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Db\DeviceTokenMapper;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Thin sender for Firebase Cloud Messaging HTTP v1 push notifications,
 * targeting the native Android client (package `eu.souvera.workspace`,
 * Firebase project `souvera-apps`).
 *
 * Auth
 * ----
 * FCM HTTP v1 requires a Google OAuth2 access token minted from a service
 * account, NOT the legacy server-key scheme. We read the service account
 * from the system-config key {@see SYSTEM_CONFIG_SERVICE_ACCOUNT} — the
 * value may be EITHER the raw JSON key content OR a filesystem path to
 * the JSON key file (whichever is easier for the CloudManager to inject
 * via config.php / a mounted secret file). We build a signed RS256 JWT
 * (`iss`/`sub` = client_email, `aud` = token_uri, `scope` =
 * `https://www.googleapis.com/auth/firebase.messaging`) and exchange it
 * for an access token at `token_uri` (default Google's
 * `https://oauth2.googleapis.com/token`) using the
 * `urn:ietf:params:oauth:grant-type:jwt-bearer` grant (RFC 7523).
 *
 * The resulting access token is valid ~1h; we cache it in-process for the
 * remainder of the request AND in {@see IAppConfig} (capped at 55 minutes)
 * so a busy instance does not mint a fresh token on every webhook call.
 *
 * Graceful degradation
 * --------------------
 * If the service account is absent/blank, {@see isConfigured()} returns
 * false and {@see send()} is a no-op that logs at debug level — the whole
 * push feature degrades gracefully until the CloudManager provisions the
 * key via `souvera_mail.fcm_service_account_json`.
 */
class FcmClient
{
    public const SYSTEM_CONFIG_SERVICE_ACCOUNT = 'souvera_mail.fcm_service_account_json';
    public const SYSTEM_CONFIG_PROJECT_ID = 'souvera_mail.fcm_project_id';

    private const DEFAULT_PROJECT_ID = 'souvera-apps';
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const SEND_URL_TEMPLATE = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const BATCH_URL = 'https://fcm.googleapis.com/batch';

    private const APP_CONFIG_TOKEN_KEY = 'fcm_access_token';
    private const APP_CONFIG_TOKEN_EXPIRY_KEY = 'fcm_access_token_expires_at';

    private const TOKEN_CACHE_SECONDS = 3300;
    private const HTTP_TIMEOUT_SECONDS = 10;

    /** Maximum sub-requests in a single batch call — Google's batch endpoint
     *  is documented to support up to 1000, but we cap conservatively. */
    private const BATCH_MAX_SIZE = 100;

    /** Send-path for each sub-request: the relative path inside the batch
     *  multipart body (no host, no query). */
    private const FCM_SEND_PATH = '/v1/projects/%s/messages:send';

    /** @var array{client_email: string, private_key: string, token_uri: string}|false|null */
    private array|false|null $serviceAccount = null;

    private ?string $accessTokenMemo = null;
    private int $accessTokenMemoExpiresAt = 0;

    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
        private IClientService $httpClientService,
        private DeviceTokenMapper $tokens,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->getServiceAccount() !== null;
    }

    public function getProjectId(): string
    {
        $id = \trim((string) $this->config->getSystemValue(self::SYSTEM_CONFIG_PROJECT_ID, ''));
        return $id !== '' ? $id : self::DEFAULT_PROJECT_ID;
    }

    /**
     * Sends a data-only push to every FCM registration token in a single
     * Google API batch request.  Tokens that the batch response reports as
     * unregistered/invalid are deleted from the database as a side effect.
     *
     * @param list<string> $fcmTokens
     * @param array<string, string> $data
     */
    public function send(array $fcmTokens, string $title, string $body, array $data = []): void
    {
        $fcmTokens = \array_values(\array_filter($fcmTokens, static fn ($t) => \is_string($t) && $t !== ''));
        if ($fcmTokens === []) {
            return;
        }
        if (!$this->isConfigured()) {
            $this->logger->debug(
                'Souvera Mail: FcmClient::send() skipped — no FCM service account configured ('
                . self::SYSTEM_CONFIG_SERVICE_ACCOUNT . ')',
                ['app' => 'souvera_mail']
            );
            return;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            $this->logger->warning(
                'Souvera Mail: FcmClient::send() skipped — could not obtain a Google OAuth2 access token',
                ['app' => 'souvera_mail']
            );
            return;
        }

        $projectId = $this->getProjectId();
        $messageData = \array_map('strval', $data) + ['title' => $title, 'body' => $body];
        $sendPath = \sprintf(self::FCM_SEND_PATH, $projectId);

        // Chunk tokens so we stay under Google's batch size limit.
        foreach (\array_chunk($fcmTokens, self::BATCH_MAX_SIZE) as $chunk) {
            $this->sendBatch($accessToken, $sendPath, $messageData, $chunk);
        }
    }

    /**
     * Builds a multipart/mixed batch body and POSTs it to
     * `https://fcm.googleapis.com/batch`. Each sub-request targets
     * `messages:send` with one device token.
     *
     * @param list<string> $tokens
     */
    private function sendBatch(
        string $accessToken,
        string $sendPath,
        array $messageData,
        array $tokens
    ): void {
        $boundary = 'fcm_batch_' . \bin2hex(\random_bytes(8));
        $body = $this->buildBatchBody($boundary, $sendPath, $messageData, $tokens);

        try {
            $client = $this->httpClientService->newClient();
            $response = $client->post(self::BATCH_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'multipart/mixed; boundary=' . $boundary,
                    'Accept' => 'multipart/mixed',
                ],
                'body' => $body,
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: FCM batch request failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return;
        }

        $this->handleBatchResponse($response, $tokens, $boundary);
    }

    /**
     * Constructs the multipart/mixed body.  Each part is a self-contained
     * HTTP sub-request (method, path, headers, empty line, JSON body).
     */
    private function buildBatchBody(
        string $boundary,
        string $sendPath,
        array $messageData,
        array $tokens
    ): string {
        $parts = [];
        $idx = 0;
        foreach ($tokens as $token) {
            $idx++;
            $payload = \json_encode([
                'message' => [
                    'token' => $token,
                    'data' => $messageData,
                    'android' => [
                        'priority' => 'high',
                        'ttl' => '3600s',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES);

            // `Content-ID` carries the token so we can match sub-responses
            // back to their originating token in the batch response parser
            // without relying on ordering alone (Google does preserve
            // ordering, but explicit matching is safer).
            $parts[] = \implode("\r\n", [
                '--' . $boundary,
                'Content-Type: application/http',
                'Content-Transfer-Encoding: binary',
                'Content-ID: <item' . $idx . ':' . $token . '>',
                '',
                'POST ' . $sendPath . ' HTTP/1.1',
                'Content-Type: application/json',
                'accept: application/json',
                '',
                $payload,
            ]);
        }
        $parts[] = '--' . $boundary . '--';
        return \implode("\r\n", $parts);
    }

    /**
     * Parses a multipart batch response and processes each sub-response
     * individually (delete dead tokens, invalidate cached access token
     * on 401/403, etc.).
     *
     * @param list<string> $originalTokens the tokens in the order they were
     *     sent (used as a fallback when Content-ID matching fails).
     */
    private function handleBatchResponse(
        IResponse $response,
        array $originalTokens,
        string $boundary
    ): void {
        $contentType = $response->getHeader('Content-Type');
        // Google may return the batch with a slightly different boundary.
        $actualBoundary = $boundary;
        if (\is_string($contentType) && \preg_match('/boundary=([^\s;]+)/', $contentType, $m)) {
            $actualBoundary = \trim($m[1], '"\'');
        }
        $parts = \explode('--' . $actualBoundary, (string) $response->getBody());

        // Map Content-ID → token for sub-response matching.
        $tokenMap = [];
        $idx = 0;
        foreach ($originalTokens as $t) {
            $idx++;
            $tokenMap['item' . $idx] = $t;
        }

        $fallbackMap = \array_values($originalTokens);
        $fallbackIdx = 0;
        $any401 = false;

        foreach ($parts as $part) {
            $part = \trim($part);
            if ($part === '' || $part === '--' || \str_starts_with($part, '--')) {
                continue;
            }

            // Split the sub-response into headers and body at the first
            // blank line ("\r\n\r\n" or "\n\n").
            $headerEnd = \strpos($part, "\r\n\r\n");
            if ($headerEnd === false) {
                $headerEnd = \strpos($part, "\n\n");
            }
            if ($headerEnd === false) {
                continue;
            }

            $headerBlock = \substr($part, 0, $headerEnd);
            $sepLen = \str_contains($part, "\r\n\r\n") ? 4 : 2;
            $bodyBlock = \trim(\substr($part, $headerEnd + $sepLen));

            // Extract HTTP status from the first line of the sub-response.
            $firstLine = \strtok($headerBlock, "\r\n");
            if ($firstLine === false) {
                $firstLine = \strtok($headerBlock, "\n");
            }
            $status = 0;
            if (\preg_match('/^HTTP\/[\d.]+\s+(\d{3})/', (string) $firstLine, $m)) {
                $status = (int) $m[1];
            }

            // Extract Content-ID for token matching.
            $contentId = '';
            if (\preg_match('/Content-ID:\s*<([^>]+)>/i', $headerBlock, $m)) {
                $contentId = $m[1];
            }
            $parts2 = \explode(':', $contentId, 2);
            $cidKey = $parts2[0] ?? '';
            $cidToken = $parts2[1] ?? '';
            $token = $cidToken !== '' ? $cidToken : ($tokenMap[$cidKey] ?? ($fallbackMap[$fallbackIdx] ?? null));
            if ($token !== null) {
                $fallbackIdx++;
            }

            $this->handleSubResponse($status, $bodyBlock, $token, $any401);
        }

        if ($any401) {
            $this->invalidateCachedAccessToken();
        }
    }

    /**
     * Handles the status of a single sub-response inside the batch.
     *
     * @param-out bool $any401 set to true if the access token was rejected
     */
    private function handleSubResponse(int $status, string $body, ?string $token, bool &$any401): void
    {
        if ($token === null) {
            return;
        }
        if ($status >= 200 && $status < 300) {
            return;
        }

        $decoded = \json_decode($body, true);
        $error = \is_array($decoded) && \is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $errorCode = '';
        foreach ((\is_array($error['details'] ?? null) ? $error['details'] : []) as $detail) {
            if (\is_array($detail) && isset($detail['errorCode'])) {
                $errorCode = (string) $detail['errorCode'];
                break;
            }
        }

        if ($status === 404 || $status === 400) {
            $this->tokens->deleteByToken($token);
            $this->logger->info(
                'Souvera Mail: FCM token rejected (HTTP ' . $status . ', errorCode=' . $errorCode
                . ') — removed from oc_souvera_mail_devicetoken',
                ['app' => 'souvera_mail']
            );
            return;
        }

        if ($status === 401 || $status === 403) {
            $any401 = true;
        }

        $this->logger->warning(
            'Souvera Mail: FCM sub-request failed HTTP ' . $status . ': '
            . (string) ($error['message'] ?? $body),
            ['app' => 'souvera_mail']
        );
    }

    private function invalidateCachedAccessToken(): void
    {
        $this->accessTokenMemo = null;
        $this->accessTokenMemoExpiresAt = 0;
        $this->appConfig->setValueString('souvera_mail', self::APP_CONFIG_TOKEN_KEY, '');
        $this->appConfig->setValueInt('souvera_mail', self::APP_CONFIG_TOKEN_EXPIRY_KEY, 0);
    }

    /**
     * @return non-empty-string|null
     */
    private function getAccessToken(): ?string
    {
        $now = \time();
        if ($this->accessTokenMemo !== null && $now < $this->accessTokenMemoExpiresAt) {
            return $this->accessTokenMemo;
        }

        $cachedToken = $this->appConfig->getValueString('souvera_mail', self::APP_CONFIG_TOKEN_KEY, '');
        $cachedExpiry = $this->appConfig->getValueInt('souvera_mail', self::APP_CONFIG_TOKEN_EXPIRY_KEY, 0);
        if ($cachedToken !== '' && $now < $cachedExpiry) {
            $this->accessTokenMemo = $cachedToken;
            $this->accessTokenMemoExpiresAt = $cachedExpiry;
            return $cachedToken;
        }

        $serviceAccount = $this->getServiceAccount();
        if ($serviceAccount === null) {
            return null;
        }

        try {
            $jwt = $this->buildSignedJwt($serviceAccount, $now);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: failed to sign FCM service-account JWT: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return null;
        }

        try {
            $client = $this->httpClientService->newClient();
            $response = $client->post($serviceAccount['token_uri'], [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: Google OAuth2 token exchange failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->warning(
                'Souvera Mail: Google OAuth2 token exchange returned HTTP ' . $response->getStatusCode()
                . ': ' . \mb_substr((string) $response->getBody(), 0, 300),
                ['app' => 'souvera_mail']
            );
            return null;
        }

        $decoded = \json_decode((string) $response->getBody(), true);
        $accessToken = \is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
        $expiresIn = \is_array($decoded) ? (int) ($decoded['expires_in'] ?? 0) : 0;
        if (!\is_string($accessToken) || $accessToken === '') {
            $this->logger->warning(
                'Souvera Mail: Google OAuth2 token exchange response had no access_token',
                ['app' => 'souvera_mail']
            );
            return null;
        }

        $ttl = $expiresIn > 0 ? \min($expiresIn, self::TOKEN_CACHE_SECONDS) : self::TOKEN_CACHE_SECONDS;
        $expiresAt = $now + $ttl;

        $this->accessTokenMemo = $accessToken;
        $this->accessTokenMemoExpiresAt = $expiresAt;
        $this->appConfig->setValueString('souvera_mail', self::APP_CONFIG_TOKEN_KEY, $accessToken);
        $this->appConfig->setValueInt('souvera_mail', self::APP_CONFIG_TOKEN_EXPIRY_KEY, $expiresAt);

        return $accessToken;
    }

    /**
     * @param array{client_email: string, private_key: string, token_uri: string} $serviceAccount
     */
    private function buildSignedJwt(array $serviceAccount, int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $serviceAccount['client_email'],
            'sub' => $serviceAccount['client_email'],
            'scope' => self::FCM_SCOPE,
            'aud' => $serviceAccount['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $signingInput = $this->base64UrlEncode((string) \json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . $this->base64UrlEncode((string) \json_encode($claims, JSON_UNESCAPED_SLASHES));

        $privateKey = \openssl_pkey_get_private($serviceAccount['private_key']);
        if ($privateKey === false) {
            throw new \RuntimeException('Could not parse FCM service-account private_key');
        }

        $signature = '';
        $signed = \openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new \RuntimeException('openssl_sign() failed while signing the FCM service-account JWT');
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Resolves + parses the service-account JSON, memoised per-request.
     * Accepts either the raw JSON string or a filesystem path to a JSON
     * key file (whichever `souvera_mail.fcm_service_account_json` holds).
     *
     * @return array{client_email: string, private_key: string, token_uri: string}|null
     */
    private function getServiceAccount(): ?array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount !== false ? $this->serviceAccount : null;
        }

        $raw = \trim((string) $this->config->getSystemValue(self::SYSTEM_CONFIG_SERVICE_ACCOUNT, ''));
        if ($raw === '') {
            $this->serviceAccount = false;
            return null;
        }

        if (\is_file($raw) && \is_readable($raw)) {
            $contents = \file_get_contents($raw);
            if ($contents === false) {
                $this->logger->warning(
                    'Souvera Mail: ' . self::SYSTEM_CONFIG_SERVICE_ACCOUNT . ' points at an unreadable file: ' . $raw,
                    ['app' => 'souvera_mail']
                );
                $this->serviceAccount = false;
                return null;
            }
            $raw = $contents;
        }

        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            $this->logger->warning(
                'Souvera Mail: ' . self::SYSTEM_CONFIG_SERVICE_ACCOUNT . ' is not valid JSON (and not a readable file path)',
                ['app' => 'souvera_mail']
            );
            $this->serviceAccount = false;
            return null;
        }

        $clientEmail = (string) ($decoded['client_email'] ?? '');
        $privateKey = (string) ($decoded['private_key'] ?? '');
        $tokenUri = (string) ($decoded['token_uri'] ?? self::DEFAULT_TOKEN_URI);
        if ($clientEmail === '' || $privateKey === '') {
            $this->logger->warning(
                'Souvera Mail: FCM service account JSON is missing client_email/private_key',
                ['app' => 'souvera_mail']
            );
            $this->serviceAccount = false;
            return null;
        }

        $this->serviceAccount = [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'token_uri' => $tokenUri !== '' ? $tokenUri : self::DEFAULT_TOKEN_URI,
        ];
        return $this->serviceAccount;
    }
}
