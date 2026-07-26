<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

/**
 * Shared auto-update via periodic GitHub release polling.
 *
 * Each app registers a Nextcloud background job that runs every 3 hours,
 * checks the GitHub releases API for a newer version, and — if a higher
 * semver tag is found — pulls + enables the update automatically.
 *
 * NO secrets, NO webhooks, NO external dependencies. Just HTTP.
 *
 * Setup per app (one-time, via occ):
 *   occ config:app:set <appid> devops.repo --value "PhiGi87/souvera_mail"
 *   occ config:app:set <appid> devops.channel --value "stable"
 *
 * Channels:
 *   "stable" (default) — only pulls release tags (v0.19.7, v0.19.8, …)
 *   "dev"              — pulls every commit from the configured branch
 *
 * Versions compared via version_compare() on the info.xml <version> field.
 */
trait SelfUpdateTrait
{
    abstract protected function getAppId(): string;

    public function checkAndUpdate(): array
    {
        $appId = $this->getAppId();
        $config = \OCP\Server::get(\OCP\IConfig::class);
        $repo = \trim((string) $config->getAppValue($appId, 'devops.repo', ''));
        if ($repo === '') {
            return ['skipped' => true, 'reason' => 'No devops.repo configured'];
        }

        $installedVersion = \OC_App::getAppVersion($appId);
        if ($installedVersion === '0') {
            return ['error' => 'Cannot read installed version'];
        }

        $channel = \trim((string) $config->getAppValue($appId, 'devops.channel', 'stable'));

        $branch = \trim((string) $config->getAppValue($appId, 'devops.branch', 'main'));
        $appPath = \OC_App::getAppPath($appId);

        if ($channel === 'dev') {
            return $this->pullBranch($appId, $appPath, $branch, $repo);
        }

        // Stable: check GitHub releases
        $latest = $this->fetchLatestRelease($repo);
        if ($latest === null) {
            return ['error' => 'Cannot fetch GitHub releases'];
        }

        if (\version_compare($latest, $installedVersion, '<=')) {
            return ['up_to_date' => true, 'installed' => $installedVersion, 'latest' => $latest];
        }

        return $this->pullTag($appId, $appPath, $latest, $repo);
    }

    private function fetchLatestRelease(string $repo): ?string
    {
        $url = "https://api.github.com/repos/$repo/releases/latest";
        $ctx = \stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Souvera-DevOps\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 15,
            ],
        ]);
        $json = @\file_get_contents($url, false, $ctx);
        if ($json === false) {
            return null;
        }
        $data = \json_decode($json, true);
        return isset($data['tag_name']) ? \ltrim((string) $data['tag_name'], 'v') : null;
    }

    private function pullTag(string $appId, string $appPath, string $tag, string $repo): array
    {
        \OCP\Server::get(\Psr\Log\LoggerInterface::class)->info("$appId devops: updating to tag v$tag");

        $output = [];
        $exitCode = 0;
        \exec(\sprintf(
            'cd %s && git fetch origin --tags 2>&1 && git checkout v%s 2>&1',
            \escapeshellarg($appPath), \escapeshellarg($tag)
        ), $output, $exitCode);

        if ($exitCode !== 0) {
            return ['error' => 'Git checkout failed', 'log' => \implode("\n", $output)];
        }

        return $this->enableApp($appId, $appPath, $tag, \implode("\n", $output));
    }

    private function pullBranch(string $appId, string $appPath, string $branch, string $repo): array
    {
        \OCP\Server::get(\Psr\Log\LoggerInterface::class)->info("$appId devops [dev]: pulling $branch");

        $output = [];
        $exitCode = 0;
        \exec(\sprintf(
            'cd %s && git fetch origin %s 2>&1 && git reset --hard origin/%s 2>&1',
            \escapeshellarg($appPath), \escapeshellarg($branch), \escapeshellarg($branch)
        ), $output, $exitCode);

        if ($exitCode !== 0) {
            return ['error' => 'Git pull failed', 'log' => \implode("\n", $output)];
        }

        $commit = \trim((string) \shell_exec("cd $appPath && git log -1 --format=%h"));
        return $this->enableApp($appId, $appPath, $commit, \implode("\n", $output));
    }

    private function enableApp(string $appId, string $appPath, string $ref, string $gitLog): array
    {
        $occOut = [];
        $occExit = 0;
        \exec(\sprintf(
            'php %s/occ app:enable %s 2>&1',
            \escapeshellarg(\dirname($appPath, 3)), \escapeshellarg($appId)
        ), $occOut, $occExit);

        \OCP\Server::get(\Psr\Log\LoggerInterface::class)->info("$appId devops: updated to $ref");

        return [
            'success' => true,
            'updated_to' => $ref,
            'git_log' => $gitLog,
            'occ_log' => \implode("\n", $occOut),
        ];
    }
}
