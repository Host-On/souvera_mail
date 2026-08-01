<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

/**
 * Self-update via GitHub Releases API (ZIP download).
 * No git, no gh CLI, no webhooks required.
 *
 * Reads token from config.php: 'souvera.devops_token'
 * Runs as a Nextcloud background job every 5 minutes
 * (see SelfUpdateJob — one instance per managed app).
 *
 * v0.22.13: HTTP via Nextcloud's IClientService (Guzzle) with a real
 * connect_timeout; exec() is guarded so a disabled exec cannot silently
 * fail an update; failure responses are reported instead of swallowed.
 */
trait SelfUpdateTrait
{
    abstract protected function getAppId(): string;

    public function checkAndUpdate(): array
    {
        $appId = $this->getAppId();
        $config = \OCP\Server::get(\OCP\IConfig::class);
        $channel = trim((string) $config->getAppValue($appId, 'devops.channel', 'stable'));

        if ($channel === 'stable') {
            // Release channel: check/install at most once per 24h and only
            // inside Nextcloud's maintenance window (config.php:
            // 'maintenance_window_start' => hour 0-23, window length 4h).
            if (!$this->inMaintenanceWindow()) {
                return ['skipped' => true, 'reason' => 'Outside maintenance window'];
            }
            $last = (int) $config->getAppValue($appId, 'devops.last_check', '0');
            if ($last > time() - 24 * 3600) {
                return ['skipped' => true, 'reason' => 'Rate-limited (24h)'];
            }
        }

        $installed = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion($appId);
        if ($installed === '0') {
            return ['error' => 'No version'];
        }

        $appPath = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppPath($appId);
        if ($appPath === null) {
            return ['error' => 'App not found'];
        }

        // Abort early on read-only filesystems (Docker/K8s with immutable containers).
        if (!is_writable($appPath)) {
            return ['error' => 'App directory is not writable'];
        }

        // Lock to prevent concurrent updates.
        $lockFp = $this->acquireLock($appId);
        if ($lockFp === null) {
            return ['skipped' => true, 'reason' => 'Another update is running'];
        }

        try {
            $branch = trim((string) $config->getAppValue($appId, 'devops.branch', 'main'));

            if ($channel === 'dev') {
                $result = $this->downloadBranch($appId, $appPath, $branch);
            } else {
                $latest = $this->latestReleaseTag();
                if ($latest === null) {
                    return ['error' => 'Cannot fetch releases'];
                }
                if (version_compare($latest, $installed, '<=')) {
                    $config->setAppValue($appId, 'devops.last_check', (string) time());
                    return ['up_to_date' => true, 'installed' => $installed, 'latest' => $latest];
                }
                $result = $this->downloadTag($appId, $appPath, $latest);
            }

            // Only write the timestamp after a successful check (or real update).
            if (empty($result['error'])) {
                $config->setAppValue($appId, 'devops.last_check', (string) time());
            }
            return $result;
        } finally {
            $this->releaseLock($lockFp);
        }
    }

    private function inMaintenanceWindow(): bool
    {
        $start = (int) \OCP\Server::get(\OCP\IConfig::class)
            ->getSystemValue('maintenance_window_start', 0);
        $hour = (int) date('G');
        if ($start + 4 <= 24) {
            return $hour >= $start && $hour < $start + 4;
        }
        return $hour >= $start || $hour < ($start + 4) % 24;
    }

    private function latestReleaseTag(): ?string
    {
        $repo = $this->getRepo();
        if ($repo === '') {
            return null;
        }
        $data = $this->apiGet("https://api.github.com/repos/$repo/releases/latest");
        if ($data === null || !isset($data['tag_name'])) {
            return null;
        }
        // Keep the raw tag (e.g. "v1.2.3") for downloading; version_compare
        // handles the optional "v" prefix itself since PHP 7.1.
        return ltrim((string) $data['tag_name'], 'v');
    }

    private function downloadBranch(string $appId, string $appPath, string $branch): array
    {
        $repo = $this->getRepo();
        if ($repo === '') {
            return ['error' => 'Unknown app'];
        }

        // Dev channel: only download if the branch HEAD changed.
        $latestSha = $this->fetchBranchSha($repo, $branch);
        if ($latestSha === null) {
            return ['error' => 'Cannot fetch branch HEAD'];
        }
        $lastSha = trim((string) \OCP\Server::get(\OCP\IConfig::class)
            ->getAppValue($appId, 'devops.last_sha', ''));
        if ($latestSha === $lastSha) {
            return ['up_to_date' => true, 'sha' => $latestSha];
        }

        $url = "https://api.github.com/repos/$repo/zipball/$branch";
        $result = $this->downloadAndApply($appId, $appPath, $url);
        if (empty($result['error'])) {
            \OCP\Server::get(\OCP\IConfig::class)
                ->setAppValue($appId, 'devops.last_sha', $latestSha);
        }
        return $result;
    }

    private function downloadTag(string $appId, string $appPath, string $tag): array
    {
        $repo = $this->getRepo();
        if ($repo === '') {
            return ['error' => 'Unknown app'];
        }
        // GitHub zipball expects the tag exactly as stored (with or without "v").
        $data = $this->apiGet("https://api.github.com/repos/$repo/releases/latest");
        $rawTag = '';
        if ($data !== null && isset($data['tag_name'])) {
            $rawTag = (string) $data['tag_name'];
        }
        $url = "https://api.github.com/repos/$repo/zipball/" . ($rawTag !== '' ? $rawTag : "v$tag");
        return $this->downloadAndApply($appId, $appPath, $url);
    }

    /**
     * Fetches the latest commit SHA of a branch via GitHub API.
     */
    private function fetchBranchSha(string $repo, string $branch): ?string
    {
        $data = $this->apiGet("https://api.github.com/repos/$repo/commits/$branch");
        if ($data === null || !isset($data['sha'])) {
            return null;
        }
        return (string) $data['sha'];
    }

    private function downloadAndApply(string $appId, string $appPath, string $url): array
    {
        $token = $this->readToken();
        if ($token === '') {
            return ['error' => 'No devops token configured'];
        }

        $client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
        try {
            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'Souvera-DevOps',
                    'Accept' => 'application/vnd.github+json',
                ],
                'timeout' => 60,
                'connect_timeout' => 15,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return ['error' => 'Download failed: ' . $e->getMessage()];
        }
        if ($response->getStatusCode() >= 400) {
            return [
                'error' => 'Download returned HTTP ' . $response->getStatusCode(),
                'hint' => \mb_substr((string) $response->getBody(), 0, 300),
            ];
        }

        $zipContent = (string) $response->getBody();
        // GitHub zipball responses are ZIP archives (magic bytes "PK");
        // anything else is an API error body (auth/rate-limit/not-found).
        if (strlen($zipContent) < 100 || !str_starts_with($zipContent, 'PK')) {
            return [
                'error' => 'Download returned no ZIP archive',
                'hint' => \mb_substr($zipContent, 0, 300),
            ];
        }

        $tmpZip = sys_get_temp_dir() . "/{$appId}_update.zip";
        if (file_put_contents($tmpZip, $zipContent) === false) {
            return ['error' => 'Cannot write temp ZIP'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            return ['error' => 'ZIP open failed'];
        }

        $extractDir = sys_get_temp_dir() . "/{$appId}_extract";
        @mkdir($extractDir, 0755, true);
        $zip->extractTo($extractDir);
        $zip->close();
        unlink($tmpZip);

        $dirs = glob("$extractDir/*", GLOB_ONLYDIR);
        if (empty($dirs)) {
            $this->rmdirRecursive($extractDir);
            return ['error' => 'Empty archive'];
        }
        $sourceDir = $dirs[0];

        // Atomic swap: backup current → extract new → enable → keep or rollback.
        $backupDir = $appPath . '.bak';
        if (is_dir($backupDir)) {
            $this->rmdirRecursive($backupDir);
        }
        if (!rename($appPath, $backupDir)) {
            $this->rmdirRecursive($extractDir);
            return ['error' => 'Cannot move current app to backup'];
        }
        // EXDEV-safe install: the extract dir lives on the local filesystem
        // (/tmp), the app dir on NFS — rename() across mounts fails with
        // "Invalid cross-device link". Copy file-by-file instead.
        try {
            $this->copyRecursive($sourceDir, $appPath);
        } catch (\Throwable $e) {
            // Restore backup.
            rename($backupDir, $appPath);
            $this->rmdirRecursive($extractDir);
            return ['error' => 'Cannot copy extracted app into place: ' . $e->getMessage()];
        }
        $this->rmdirRecursive($sourceDir);

        $enableResult = $this->enableApp($appId);
        if (!empty($enableResult['error'])) {
            // Rollback: restore backup, remove broken new version.
            $this->rmdirRecursive($appPath);
            rename($backupDir, $appPath);
            $this->rmdirRecursive($extractDir);
            return $enableResult;
        }

        // Success: clean up backup.
        $this->rmdirRecursive($backupDir);
        $this->rmdirRecursive($extractDir);
        return $enableResult;
    }

    private function enableApp(string $appId): array
    {
        if (!\function_exists('exec')) {
            return ['error' => 'exec() is disabled — cannot run occ app:enable'];
        }
        $occOut = [];
        $occExit = 0;
        $occPath = \OC::$SERVERROOT . '/occ';
        // PHP_BINARY instead of "php": cron often runs with a minimal PATH
        // where the interpreter is not resolvable, failing every update.
        exec(sprintf(
            '%s %s app:enable %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($occPath),
            escapeshellarg($appId)
        ), $occOut, $occExit);

        $log = implode("\n", $occOut);
        if ($occExit !== 0) {
            return ['error' => "occ app:enable failed (exit $occExit)", 'occ_log' => $log];
        }
        return ['success' => true, 'occ_log' => $log, 'occ_exit' => $occExit];
    }

    private function acquireLock(string $appId): mixed
    {
        $lockFile = sys_get_temp_dir() . "/{$appId}_update.lock";
        $fp = @fopen($lockFile, 'w+');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        return $fp;
    }

    private function releaseLock(mixed $fp): void
    {
        if ($fp === null) {
            return;
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function readToken(): string
    {
        try {
            $token = \OCP\Server::get(\OCP\IConfig::class)
                ->getSystemValue('souvera.devops_token', '');
            return trim((string) $token);
        } catch (\Throwable) {
            return '';
        }
    }

    private function getRepo(): string
    {
        return match ($this->getAppId()) {
            'souvera_mail' => 'PhiGi87/souvera_mail',
            'souvera_central' => 'PhiGi87/souvera_central',
            'souvera_shield' => 'PhiGi87/souvera_shield',
            default => '',
        };
    }

    private function apiGet(string $url): ?array
    {
        $token = $this->readToken();
        if ($token === '') {
            return null;
        }
        $client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
        try {
            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'Souvera-DevOps',
                    'Accept' => 'application/vnd.github+json',
                ],
                'timeout' => 15,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            return null;
        }
        $data = json_decode((string) $response->getBody(), true);
        return is_array($data) ? $data : null;
    }

    private function copyRecursive(string $src, string $dst): void
    {
        $dir = @opendir($src);
        if ($dir === false) {
            throw new \RuntimeException("Cannot open source directory $src");
        }
        @mkdir($dst, 0755, true);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $sp = "$src/$file";
            $dp = "$dst/$file";
            if (is_dir($sp)) {
                $this->copyRecursive($sp, $dp);
            } else {
                if (!copy($sp, $dp)) {
                    throw new \RuntimeException("Failed to copy $sp to $dp");
                }
                // Preserve executable bits from source.
                $spPerms = @fileperms($sp);
                if ($spPerms !== false) {
                    @chmod($dp, $spPerms);
                }
            }
        }
        closedir($dir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "$dir/$f";
            is_dir($p) ? $this->rmdirRecursive($p) : unlink($p);
        }
        rmdir($dir);
    }
}
