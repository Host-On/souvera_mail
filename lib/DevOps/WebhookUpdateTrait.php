<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Shared auto-update webhook with stable / dev channels.
 *
 * Channel control (per-app, set via occ):
 *   occ config:app:set <appid> devops.channel --value "stable"   (default)
 *   occ config:app:set <appid> devops.channel --value "dev"
 *
 * STABLE channel (default):
 *   - Only reacts to GitHub "release" events (published tag).
 *   - Pulls the tag with `git fetch origin <tag>` and checks it out.
 *
 * DEV channel:
 *   - Reacts to every "push" event on the configured branch.
 *   - Fast-forward pulls via `git pull origin <branch>`.
 *
 * Security:
 *   occ config:app:set <appid> devops.secret --value "webhook-secret"
 *   (Set the same secret in GitHub webhook settings; empty = no HMAC check.)
 *
 * Configuration:
 *   occ config:app:set <appid> devops.branch --value "main"
 */
trait WebhookUpdateTrait
{
    abstract protected function getAppId(): string;

    private function runUpdate(): DataResponse
    {
        $appId = $this->getAppId();
        $config = \OCP\Server::get(IConfig::class);
        $logger = \OCP\Server::get(LoggerInterface::class);

        if (!$this->verifySignature($appId, $logger)) {
            return new DataResponse(['error' => 'Invalid signature'], Http::STATUS_FORBIDDEN);
        }

        $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
        $payload = \json_decode(\file_get_contents('php://input'), true);
        $channel = \trim((string) $config->getAppValue($appId, 'devops.channel', 'stable'));
        $branch = \trim((string) $config->getAppValue($appId, 'devops.branch', 'main'));

        $appPath = \OC_App::getAppPath($appId);
        if (!$appPath || !\is_dir("$appPath/.git")) {
            $logger->error("$appId devops: app path or .git missing");
            return new DataResponse(['error' => 'App path not found'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($channel === 'dev' && $event === 'push') {
            $ref = $payload['ref'] ?? '';
            $pushedBranch = \str_replace('refs/heads/', '', $ref);
            if ($pushedBranch !== $branch) {
                $logger->info("$appId devops: push on $pushedBranch, ignoring (channel=$channel, target=$branch)");
                return new DataResponse(['skipped' => true, 'reason' => "Branch $pushedBranch != $branch"]);
            }
            return $this->doGitPull($appId, $appPath, $branch, $channel, $logger);
        }

        if ($channel === 'stable' && $event === 'release') {
            $action = $payload['action'] ?? '';
            if ($action !== 'published') {
                return new DataResponse(['skipped' => true, 'reason' => "Release action=$action, not published"]);
            }
            $tag = $payload['release']['tag_name'] ?? '';
            if ($tag === '') {
                return new DataResponse(['error' => 'No tag in release'], Http::STATUS_BAD_REQUEST);
            }
            return $this->doGitCheckout($appId, $appPath, $tag, $channel, $logger);
        }

        return new DataResponse([
            'skipped' => true,
            'reason' => "Channel=$channel ignores event=$event",
        ]);
    }

    private function doGitPull(string $appId, string $appPath, string $ref, string $channel, LoggerInterface $logger): DataResponse
    {
        $logger->info("$appId devops [$channel]: pulling $ref");

        $output = [];
        $exitCode = 0;
        \exec(\sprintf(
            'cd %s && git fetch origin %s 2>&1 && git reset --hard origin/%s 2>&1',
            \escapeshellarg($appPath), \escapeshellarg($ref), \escapeshellarg($ref)
        ), $output, $exitCode);

        if ($exitCode !== 0) {
            $log = \implode("\n", $output);
            $logger->error("$appId devops: git failed: $log");
            return new DataResponse(['error' => 'Git failed', 'log' => $log], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return $this->enableApp($appId, $appPath, \implode("\n", $output), $logger);
    }

    private function doGitCheckout(string $appId, string $appPath, string $tag, string $channel, LoggerInterface $logger): DataResponse
    {
        $logger->info("$appId devops [$channel]: checking out tag $tag");

        $output = [];
        $exitCode = 0;
        \exec(\sprintf(
            'cd %s && git fetch origin --tags 2>&1 && git checkout %s 2>&1',
            \escapeshellarg($appPath), \escapeshellarg($tag)
        ), $output, $exitCode);

        if ($exitCode !== 0) {
            $log = \implode("\n", $output);
            $logger->error("$appId devops: checkout failed: $log");
            return new DataResponse(['error' => 'Checkout failed', 'log' => $log], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return $this->enableApp($appId, $appPath, \implode("\n", $output), $logger);
    }

    private function enableApp(string $appId, string $appPath, string $gitLog, LoggerInterface $logger): DataResponse
    {
        $occOut = [];
        $occExit = 0;
        \exec(\sprintf(
            'php %s/occ app:enable %s 2>&1',
            \escapeshellarg(\dirname($appPath, 3)), \escapeshellarg($appId)
        ), $occOut, $occExit);

        $commit = \trim((string) \shell_exec("cd $appPath && git log -1 --format=%h"));
        $logger->info("$appId devops: updated to $commit");

        return new DataResponse([
            'success' => true,
            'commit' => $commit,
            'git_log' => $gitLog,
            'occ_log' => \implode("\n", $occOut),
            'occ_exit' => $occExit,
        ]);
    }

    private function verifySignature(string $appId, LoggerInterface $logger): bool
    {
        $config = \OCP\Server::get(IConfig::class);
        $secret = \trim((string) $config->getAppValue($appId, 'devops.secret', ''));
        if ($secret === '') {
            return true; // no secret configured → no verification
        }
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $payload = \file_get_contents('php://input');
        $expected = 'sha256=' . \hash_hmac('sha256', $payload, $secret);
        if (!\hash_equals($expected, $signature)) {
            $logger->warning("$appId devops: invalid signature");
            return false;
        }
        return true;
    }
}
