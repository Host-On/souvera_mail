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

    private const APP_CONFIG_TOKEN_KEY = 'fcm_access_token';
    private const APP_CONFIG_TOKEN_EXPIRY_KEY = 'fcm_access_token_expires_at';

    private const TOKEN_CACHE_SECONDS = 3300;
    private const HTTP_TIMEOUT_SECONDS = 10;

    /** Max parallel FCM HTTP v1 sends per call (no official batch endpoint). */
    private const MAX_CONCURRENT = 10;

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
     * Sends a data-only push (no `notification` block) to every given FCM
     * registration token. FCM HTTP v1 has no batch endpoint, so sends run
     * concurrently in bounded chunks (sendEach pattern) instead of strictly
     * sequentially. Tokens that Google reports as unregistered/invalid are
     * deleted from `oc_souvera_mail_devicetoken` as a side effect.
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
        $url = \sprintf(self::SEND_URL_TEMPLATE, $projectId);
        $client = $this->httpClientService->newClient();

        /** @var array<string, IResponse|\Throwable> $results */
        $results = [];
        foreach (\array_chunk($fcmTokens, self::MAX_CONCURRENT) as $chunk) {
            $promises = [];
            foreach ($chunk as $fcmToken) {
                $messageData = \array_map('strval', $data) + ['title' => $title, 'body' => $body];
                $payload = [
                    'message' => [
                        'token' => $fcmToken,
                        'data' => $messageData,
                        'android' => [
                            'priority' => 'high',
                            'ttl' => '3600s',
                        ],
                    ],
                ];

                $promises[$fcmToken] = $client->postAsync($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => self::HTTP_TIMEOUT_SECONDS,
                    'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
                    'http_errors' => false,
                ])->then(
                    static function (IResponse $response) use (&$results, $fcmToken): IResponse {
                        $results[$fcmToken] = $response;
                        return $response;
                    },
                    static function (\Throwable $e) use (&$results, $fcmToken) {
                        $results[$fcmToken] = $e;
                        return null;
                    }
                );
            }
            \GuzzleHttp\Promise\Utils::settle($promises)->wait();
        }

        foreach ($results as $fcmToken => $result) {
            if ($result instanceof IResponse) {
                $this->handleSendResponse($result, $fcmToken);
                continue;
            }
            $this->logger->warning(
                'Souvera Mail: FCM send request failed: ' . $result->getMessage(),
                ['app' => 'souvera_mail']
            );
        }
    }

    private function handleSendResponse(IResponse $response, string $fcmToken): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $decoded = \json_decode((string) $response->getBody(), true);
        $error = \is_array($decoded) && \is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $errorCode = '';
        foreach ((\is_array($error['details'] ?? null) ? $error['details'] : []) as $detail) {
            if (\is_array($detail) && isset($detail['errorCode'])) {
                $errorCode = (string) $detail['errorCode'];
                break;
            }
        }

        if ($status === 404 || $status === 400) {
            $this->tokens->deleteByToken($fcmToken);
            $this->logger->info(
                'Souvera Mail: FCM token rejected (HTTP ' . $status . ', errorCode=' . $errorCode
                . ') — removed from oc_souvera_mail_devicetoken',
                ['app' => 'souvera_mail']
            );
            return;
        }

        if ($status === 401 || $status === 403) {
            $this->invalidateCachedAccessToken();
        }

        $this->logger->warning(
            'Souvera Mail: FCM send failed with HTTP ' . $status . ': '
            . (string) ($error['message'] ?? $response->getBody()),
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
