<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Client for the PMG Learning API (see PROXMOX_SPAM.md).
 *
 * Trains the PMG spamfilter Bayes database on all cluster nodes from user
 * "report spam / ham" actions. Transport is the original RFC 822 mail
 * (JMAP blob download), NOT a client-side re-serialization.
 *
 * Behaviour per spec:
 *  - HTTP 200 → all 5 nodes learned/forgotten (success)
 *  - HTTP 207 → partial: some nodes failed; log + report, NO blind retry
 *    (duplicates are harmless — sa-learn matches via bayes_seen)
 *  - 400/401/403/404 → error surfaced to the caller
 *  - Timeout 90s (the API fans out to 5 nodes over SSH)
 */
class PmgLearningService
{
    public const DEFAULT_BASE_URL = 'https://mx10.mail-gw.org:9911';
    public const APP_CONFIG_URL = 'pmg.api_url';
    public const APP_CONFIG_TOKEN = 'pmg.api_token';

    private const TIMEOUT_SECONDS = 90;
    private const MIN_BYTES = 400;
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->getToken() !== '';
    }

    public function getBaseUrl(): string
    {
        $url = trim((string) $this->appConfig->getValueString(Application::APP_ID, self::APP_CONFIG_URL, ''));
        return $url !== '' ? rtrim($url, '/') : self::DEFAULT_BASE_URL;
    }

    private function getToken(): string
    {
        return trim((string) $this->appConfig->getValueString(Application::APP_ID, self::APP_CONFIG_TOKEN, ''));
    }

    /**
     * Learn / forget a raw RFC 822 mail as spam or ham.
     *
     * @param string $class   'spam' | 'ham'
     * @param string $mode    'learn' | 'forget'
     * @param string $rawMail original RFC 822 bytes
     * @return array{success: bool, partial: bool, nodes_ok: string, results?: array, error?: string}
     */
    public function learn(string $class, string $mode, string $rawMail): array
    {
        if (!in_array($class, ['spam', 'ham'], true)) {
            return ['success' => false, 'partial' => false, 'nodes_ok' => '', 'error' => 'Invalid class'];
        }
        if (!in_array($mode, ['learn', 'forget'], true)) {
            return ['success' => false, 'partial' => false, 'nodes_ok' => '', 'error' => 'Invalid mode'];
        }
        if ($this->getToken() === '') {
            return ['success' => false, 'partial' => false, 'nodes_ok' => '', 'error' => 'PMG learning not configured (token missing)'];
        }

        $size = strlen($rawMail);
        if ($size < self::MIN_BYTES || $size > self::MAX_BYTES) {
            return ['success' => false, 'partial' => false, 'nodes_ok' => '',
                'error' => sprintf('Mail size %d bytes out of range (%d–%d)', $size, self::MIN_BYTES, self::MAX_BYTES)];
        }

        $url = $this->getBaseUrl() . '/v1/' . $mode . '/' . $class;

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($url, [
                'headers' => [
                    'X-API-Token' => $this->getToken(),
                    'Content-Type' => 'message/rfc822',
                ],
                'body' => $rawMail,
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('PMG learning request failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
            return ['success' => false, 'partial' => false, 'nodes_ok' => '', 'error' => 'PMG request failed: ' . $e->getMessage()];
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if ($status === 200) {
            return [
                'success' => (bool) ($data['success'] ?? true),
                'partial' => false,
                'nodes_ok' => (string) ($data['nodes_ok'] ?? ''),
                'results' => $data['results'] ?? [],
            ];
        }

        if ($status === 207) {
            // Partial failure: some nodes learned, some did not. Log loudly for
            // the admin — a manual retry is safe (duplicate detection) but we
            // do not retry blind.
            $this->logger->warning('PMG learning partial failure (207): ' . $body, ['app' => Application::APP_ID]);
            return [
                'success' => (bool) ($data['success'] ?? false),
                'partial' => true,
                'nodes_ok' => (string) ($data['nodes_ok'] ?? ''),
                'results' => $data['results'] ?? [],
            ];
        }

        $message = 'PMG learning error: HTTP ' . $status . ' — ' . substr($body, 0, 200);
        $this->logger->error($message, ['app' => Application::APP_ID]);

        return ['success' => false, 'partial' => false, 'nodes_ok' => '', 'error' => $message];
    }

    /**
     * @return array<string, mixed>|null health payload or null on failure
     */
    public function health(): ?array
    {
        $client = $this->clientService->newClient();
        try {
            $response = $client->get($this->getBaseUrl() . '/v1/health', [
                'timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable) {
            return null;
        }
        $data = json_decode((string) $response->getBody(), true);
        return is_array($data) ? $data : null;
    }
}
