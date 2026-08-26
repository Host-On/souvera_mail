<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Db\DeviceTokenMapper;
use OCP\IConfig;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Thin APNs (Apple Push Notification service) sender for the Souvera iOS app.
 *
 * Mirrors {@see FcmClient}: token-based device pushes, configuration via
 * system config (provisioned through CloudManager), unregistered devices are
 * removed from `oc_souvera_mail_devicetoken` as a side effect.
 *
 * Credentials (from the Apple Developer Portal — "Keys" → APNs Auth Key):
 * system config `souvera_mail.apns_config_json` as JSON:
 *   {
 *     "key":       "<P8 private key as PEM string>",
 *     "keyId":     "ABCDE12345",
 *     "teamId":    "12345ABCDE",
 *     "bundleId":  "de.host-on.souvera.ios",
 *     "sandbox":   false
 *   }
 *
 * APNs requires HTTP/2 — the send therefore runs through the curl binary
 * (proven on this infrastructure, see the Play upload tooling) instead of
 * the Nextcloud HTTP client.
 */
class ApnsClient
{
    public const SYSTEM_CONFIG_CREDENTIALS = 'souvera_mail.apns_config_json';

    private const APP_CONFIG_TOKEN_KEY = 'souvera_mail.apns_jwt';
    private const APP_CONFIG_TOKEN_EXPIRY_KEY = 'souvera_mail.apns_jwt_expiry';
    private const JWT_LIFETIME_SECONDS = 3600;

    /** @var array{key: string, keyId: string, teamId: string, bundleId: string, sandbox: bool}|null */
    private ?array $credentialsMemo = null;

    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
        private DeviceTokenMapper $tokens,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->getCredentials() !== null;
    }

    /**
     * Sends a push to every given APNs device token. Tokens that Apple
     * reports as unregistered are deleted from the device-token table.
     *
     * @param list<string> $apnsTokens
     * @param array<string, string> $data
     */
    public function send(array $apnsTokens, string $title, string $body, array $data = []): void
    {
        $apnsTokens = \array_values(\array_unique(\array_filter(
            $apnsTokens,
            static fn ($t) => \is_string($t) && $t !== ''
        )));
        if ($apnsTokens === []) {
            return;
        }
        $credentials = $this->getCredentials();
        if ($credentials === null) {
            $this->logger->debug(
                'Souvera Mail: ApnsClient::send() skipped — no APNs credentials configured ('
                . self::SYSTEM_CONFIG_CREDENTIALS . ')',
                ['app' => 'souvera_mail']
            );
            return;
        }

        $jwt = $this->getJwt($credentials);
        if ($jwt === null) {
            $this->logger->warning(
                'Souvera Mail: ApnsClient::send() skipped — could not mint the APNs JWT',
                ['app' => 'souvera_mail']
            );
            return;
        }

        $host = $credentials['sandbox'] ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
        foreach ($apnsTokens as $token) {
            $payload = ['aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default']];
            foreach ($data as $k => $v) {
                $payload[$k] = (string) $v;
            }
            $this->sendOne($host, $credentials['bundleId'], $jwt, $token, $payload);
        }
    }

    /**
     * @param array{key: string, keyId: string, teamId: string, bundleId: string, sandbox: bool} $credentials
     * @param array<string, mixed> $payload
     */
    private function sendOne(string $host, string $bundleId, string $jwt, string $token, array $payload): void
    {
        $url = 'https://' . $host . '/3/device/' . \rawurlencode($token);
        $json = \json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->logger->warning('Souvera Mail: APNs payload encoding failed', ['app' => 'souvera_mail']);
            return;
        }

        $cmd = [
            'curl', '-sS', '--http2', '--max-time', '20',
            '-X', 'POST',
            '-H', 'authorization: bearer ' . $jwt,
            '-H', 'apns-topic: ' . $bundleId,
            '-H', 'content-type: application/json',
            '--data-binary', '@-',
            '-w', "\n%{http_code}",
            $url,
        ];
        $pipes = [];
        $proc = @\proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            $this->logger->warning('Souvera Mail: APNs curl could not be started', ['app' => 'souvera_mail']);
            return;
        }
        \fwrite($pipes[0], $json);
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);

        $lines = \explode("\n", \trim($out));
        $status = (int) \end($lines);
        $bodyText = \trim(\implode("\n", \array_slice($lines, 0, -1)));

        if ($status === 200) {
            return;
        }

        if ($status === 410 || $status === 400) {
            $this->tokens->deleteByToken($token);
            $this->logger->info(
                'Souvera Mail: APNs token rejected (HTTP ' . $status . ') — removed from oc_souvera_mail_devicetoken',
                ['app' => 'souvera_mail']
            );
            return;
        }

        if ($status === 403) {
            $this->invalidateCachedJwt();
        }

        $this->logger->warning(
            'Souvera Mail: APNs send failed with HTTP ' . $status . ': ' . \substr($bodyText, 0, 400),
            ['app' => 'souvera_mail']
        );
    }

    /**
     * @param array{key: string, keyId: string, teamId: string, bundleId: string, sandbox: bool} $credentials
     */
    private function getJwt(array $credentials): ?string
    {
        $cached = $this->appConfig->getValueString('souvera_mail', self::APP_CONFIG_TOKEN_KEY, '');
        $expiry = $this->appConfig->getValueInt('souvera_mail', self::APP_CONFIG_TOKEN_EXPIRY_KEY, 0);
        if ($cached !== '' && $expiry > \time() + 60) {
            return $cached;
        }

        $header = self::b64url(\json_encode(['alg' => 'ES256', 'kid' => $credentials['keyId']]));
        $claims = self::b64url(\json_encode(['iss' => $credentials['teamId'], 'iat' => \time()]));
        $signingInput = $header . '.' . $claims;

        $signature = '';
        $ok = \openssl_sign($signingInput, $signature, $credentials['key'], 'sha256');
        if (!$ok || $signature === '') {
            return null;
        }

        $jwt = $signingInput . '.' . self::b64url($signature);
        $this->appConfig->setValueString('souvera_mail', self::APP_CONFIG_TOKEN_KEY, $jwt);
        $this->appConfig->setValueInt(
            'souvera_mail',
            self::APP_CONFIG_TOKEN_EXPIRY_KEY,
            \time() + self::JWT_LIFETIME_SECONDS
        );
        return $jwt;
    }

    private function invalidateCachedJwt(): void
    {
        $this->appConfig->setValueString('souvera_mail', self::APP_CONFIG_TOKEN_KEY, '');
        $this->appConfig->setValueInt('souvera_mail', self::APP_CONFIG_TOKEN_EXPIRY_KEY, 0);
    }

    /**
     * @return array{key: string, keyId: string, teamId: string, bundleId: string, sandbox: bool}|null
     */
    private function getCredentials(): ?array
    {
        if ($this->credentialsMemo !== null) {
            return $this->credentialsMemo;
        }
        $raw = (string) $this->config->getSystemValue(self::SYSTEM_CONFIG_CREDENTIALS, '');
        $this->credentialsMemo = null;
        if ($raw !== '') {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded)) {
                $key = \trim((string) ($decoded['key'] ?? ''));
                $keyId = \trim((string) ($decoded['keyId'] ?? ''));
                $teamId = \trim((string) ($decoded['teamId'] ?? ''));
                $bundleId = \trim((string) ($decoded['bundleId'] ?? ''));
                if ($key !== '' && $keyId !== '' && $teamId !== '' && $bundleId !== '') {
                    $this->credentialsMemo = [
                        'key' => $key,
                        'keyId' => $keyId,
                        'teamId' => $teamId,
                        'bundleId' => $bundleId,
                        'sandbox' => (bool) ($decoded['sandbox'] ?? false),
                    ];
                }
            }
        }
        return $this->credentialsMemo;
    }

    private static function b64url(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
}
