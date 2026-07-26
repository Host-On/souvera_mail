<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Shared auto-update webhook for Souvera Nextcloud apps.
 *
 * Receives a GitHub push event via POST, verifies the optional HMAC secret,
 * pulls the configured branch, and triggers the app-repair step so migrations
 * and InstallStep run. Designed for automated deploys (Ansible, k8s init
 * containers) — no browser-based setup needed.
 *
 * Usage per app:
 *   1. Register a route: POST /apps/<appid>/devops/update
 *   2. Configure in config.php:
 *        'souvera_mail.devops.secret' => 'github-webhook-secret'
 *        'souvera_mail.devops.branch' => 'main'
 *   3. Add GitHub webhook pointing at <server>/index.php/apps/<appid>/devops/update
 */
trait WebhookUpdateTrait
{
    abstract protected function getAppId(): string;

    private function runUpdate(): DataResponse
    {
        $appId = $this->getAppId();
        $config = \OCP\Server::get(IConfig::class);
        $logger = \OCP\Server::get(LoggerInterface::class);

        $secret = \trim((string) $config->getAppValue($appId, 'devops.secret', ''));
        $branch = \trim((string) $config->getAppValue($appId, 'devops.branch', 'main'));

        // Optional HMAC verification
        if ($secret !== '') {
            $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
            $payload = \file_get_contents('php://input');
            $expected = 'sha256=' . \hash_hmac('sha256', $payload, $secret);
            if (!\hash_equals($expected, $signature)) {
                $logger->warning("$appId devops: invalid webhook signature");
                return new DataResponse(['error' => 'Invalid signature'], Http::STATUS_FORBIDDEN);
            }
        }

        $appPath = \OC_App::getAppPath($appId);
        if (!$appPath || !\is_dir("$appPath/.git")) {
            $logger->error("$appId devops: app path or .git not found at $appPath");
            return new DataResponse(['error' => 'App path not found'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $logger->info("$appId devops: pulling branch $branch");

        $output = [];
        $exitCode = 0;
        \exec(\sprintf(
            'cd %s && git fetch origin %s 2>&1 && git reset --hard origin/%s 2>&1',
            \escapeshellarg($appPath),
            \escapeshellarg($branch),
            \escapeshellarg($branch)
        ), $output, $exitCode);

        $gitLog = \implode("\n", $output);

        if ($exitCode !== 0) {
            $logger->error("$appId devops: git pull failed: $gitLog");
            return new DataResponse(['error' => 'Git pull failed', 'log' => $gitLog], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Trigger app repair steps (InstallStep, migrations, etc.)
        $occOutput = [];
        $occExit = 0;
        \exec(\sprintf(
            'php %s/occ app:enable %s 2>&1',
            \escapeshellarg(\dirname($appPath, 3)),
            \escapeshellarg($appId)
        ), $occOutput, $occExit);

        $occLog = \implode("\n", $occOutput);

        $logger->info("$appId devops: updated to " . \trim(\shell_exec("cd $appPath && git log -1 --format=%h")));

        return new DataResponse([
            'success' => true,
            'branch' => $branch,
            'git_log' => $gitLog,
            'occ_log' => $occLog,
            'occ_exit' => $occExit,
        ]);
    }
}
