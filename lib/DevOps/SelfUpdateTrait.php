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

    /**
     * @param bool $manual true = expliziterocc-Aufruf: Wartungsfenster und
     *                     24h-Drossel werden ignoriert (der Cron-Pfad nutzt
     *                     den Default false).
     */
    public function checkAndUpdate(bool $manual = false): array
    {
        $appId = $this->getAppId();
        $config = \OCP\Server::get(\OCP\IConfig::class);
        // EIN Suite-Channel für alle Apps (dev = main-HEAD, stable = Release);
        // System-Config souvera.update.channel, Abwärtskompatibilität über den
        // App-Channel von souvera_central.
        $suiteChannel = trim((string) $config->getSystemValue('souvera.update.channel', ''));
        if ($suiteChannel !== 'dev' && $suiteChannel !== 'stable') {
            $suiteChannel = trim((string) $config->getAppValue('souvera_central', 'devops.channel', 'stable'));
        }
        $channel = ($suiteChannel === 'dev') ? 'dev' : 'stable';

        if ($channel === 'stable' && !$manual) {
            // Release channel: check/install at most once per 24h and only
            // inside the maintenance window (config.php:
            // 'maintenance_window_start' => hour 0-23, window length 1h).
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
        // Config value = first hour of the maintenance window; the window is
        // exactly 1 hour long (operator-defined semantics — Nextcloud core
        // uses start..start+4h, this app intentionally deviates).
        $start = (int) \OCP\Server::get(\OCP\IConfig::class)
            ->getSystemValue('maintenance_window_start', 0);
        $hour = (int) date('G');
        $end = ($start + 1) % 24;
        return $start < $end
            ? $hour >= $start && $hour < $end
            : $hour >= $start || $hour < $end;
    }

    private function latestReleaseTag(): ?string
    {
        $repo = $this->getRepo();
        if ($repo === '') {
            return null;
        }

        $project = str_replace('/', '%2F', $repo);

        // 1) Neuester GitLab-Release
        $releases = $this->gitlabApiGet($this->gitlabBase() . '/api/v4/projects/'
            . $project . '/releases?per_page=1');
        if (is_array($releases) && isset($releases[0]['tag_name'])) {
            return ltrim((string) $releases[0]['tag_name'], 'v');
        }

        // 2) Fallback: neuester Tag (semantisch absteigend sortiert)
        $tags = $this->gitlabApiGet($this->gitlabBase() . '/api/v4/projects/'
            . $project . '/repository/tags?per_page=100');
        if (is_array($tags) && $tags !== []) {
            usort($tags, static function ($a, $b) {
                return version_compare($b['name'], $a['name']);
            });
            return ltrim((string) $tags[0]['name'], 'v');
        }

        return null;
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

        if ($this->isGitlabApp()) {
            $url = $this->gitlabBase() . '/api/v4/projects/'
                . $this->gitlabProjectEncoded() . '/repository/archive.zip?sha='
                . rawurlencode($branch);
        } else {
            $url = "https://api.github.com/repos/$repo/zipball/$branch";
        }
        $result = $this->downloadAndApply($appId, $appPath, $url);
        if (!isset($result['error'])) {
            $this->runAppMigrations($appId);
        }
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
        if ($this->isGitlabApp()) {
            $data = $this->gitlabApiGet($this->gitlabBase() . '/api/v4/projects/'
                . $this->gitlabProjectEncoded() . '/releases');
            $rawTag = (is_array($data) && isset($data[0]['tag_name']))
                ? (string) $data[0]['tag_name'] : '';
            if ($rawTag === '') {
                return ['error' => 'No GitLab release found'];
            }
            $url = $this->gitlabBase() . '/api/v4/projects/'
                . $this->gitlabProjectEncoded() . '/repository/archive.zip?sha='
                . rawurlencode($rawTag);
            $applied = $this->downloadAndApply($appId, $appPath, $url);
        if (!isset($applied['error'])) {
            $this->runAppMigrations($appId);
        }
        return $applied;
        }
        // GitHub zipball expects the tag exactly as stored (with or without "v").
        $data = $this->apiGet("https://api.github.com/repos/$repo/releases/latest");
        $rawTag = '';
        if ($data !== null && isset($data['tag_name'])) {
            $rawTag = (string) $data['tag_name'];
        }
        $url = "https://api.github.com/repos/$repo/zipball/" . ($rawTag !== '' ? $rawTag : "v$tag");
        $applied = $this->downloadAndApply($appId, $appPath, $url);
        if (!isset($applied['error'])) {
            $this->runAppMigrations($appId);
        }
        return $applied;
    }

    /**
     * Fetches the latest commit SHA of a branch via GitHub API.
     */
    private function fetchBranchSha(string $repo, string $branch): ?string
    {
        if ($this->isGitlabApp()) {
            $data = $this->gitlabApiGet($this->gitlabBase() . '/api/v4/projects/'
                . $this->gitlabProjectEncoded() . '/repository/branches/'
                . rawurlencode($branch));
            if ($data === null || !isset($data['commit']['id'])) {
                return null;
            }
            return (string) $data['commit']['id'];
        }
        $data = $this->apiGet("https://api.github.com/repos/$repo/commits/$branch");
        if ($data === null || !isset($data['sha'])) {
            return null;
        }
        return (string) $data['sha'];
    }

    /**
     * Run app migrations in-process after a self-update. A raw zipball swap
     * does NOT execute migrations — without this, new tables from newer
     * versions are missing and the app crashes on first use.
     * (Same mechanism as `occ migrations:migrate <app>`.)
     */
    private function runAppMigrations(string $appId): void
    {
        try {
            $connection = \OCP\Server::get(\OCP\IDBConnection::class);
            $ms = new \OC\DB\MigrationService($appId, $connection);
            $ms->migrate();
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)
                ->info('Souvera SelfUpdate: migrations executed', ['app' => $appId]);
        } catch (\Throwable $e) {
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)
                ->error('Souvera SelfUpdate: migrations failed for ' . $appId . ': ' . $e->getMessage());
        }
    }

    private function downloadAndApply(string $appId, string $appPath, string $url): array
    {
        $token = $this->readToken();
        if ($token === '') {
            return ['error' => 'No devops token configured'];
        }

        $client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
        if ($this->isGitlabApp()) {
            $token = $this->readGitlabToken();
        }
        try {
            $headers = $this->isGitlabApp()
                ? ['PRIVATE-TOKEN' => $token, 'User-Agent' => 'Souvera-DevOps']
                : [
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'Souvera-DevOps',
                    'Accept' => 'application/vnd.github+json',
                ];
            $response = $client->get($url, [
                'headers' => $headers,
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
        // Use copy+delete instead of rename — rename() fails on NFS,
        // containers with open file handles, or cross-device mounts.
        try {
            $this->copyRecursive($appPath, $backupDir);
            $this->rmdirRecursive($appPath);
        } catch (\Throwable $e) {
            $this->rmdirRecursive($extractDir);
            return ['error' => 'Cannot move current app to backup: ' . $e->getMessage()];
        }
        // EXDEV-safe install: the extract dir lives on the local filesystem
        // (/tmp), the app dir on NFS — rename() across mounts fails with
        // "Invalid cross-device link". Copy file-by-file instead.
        try {
            $this->copyRecursive($sourceDir, $appPath);
        } catch (\Throwable $e) {
            // Restore backup.
            $this->rmdirRecursive($appPath);
            $this->copyRecursive($backupDir, $appPath);
            $this->rmdirRecursive($backupDir);
            $this->rmdirRecursive($extractDir);
            return ['error' => 'Cannot copy extracted app into place: ' . $e->getMessage()];
        }
        $this->rmdirRecursive($sourceDir);

        $enableResult = $this->enableApp($appId);
        if (!empty($enableResult['error'])) {
            // Rollback: restore backup, remove broken new version.
            $this->rmdirRecursive($appPath);
            $this->copyRecursive($backupDir, $appPath);
            $this->rmdirRecursive($backupDir);
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
        // Gruppen-Freigabe erhalten (05.09.-Fix): der 'enabled'-AppConfig-
        // Wert (JSON mit Gruppenliste) liegt in der DB und überlebt den
        // Code-Tausch — aber ein platte app:enable würde ihn bei einer
        // zwischenzeitlichen Deaktivierung auf 'yes' zurücksetzen. Gruppen
        // daher explizit mit --groups mitgeben.
        $groupArgs = '';
        $enabledRaw = '';
        try {
            $enabledRaw = trim((string) \OCP\Server::get(\OCP\IConfig::class)
                ->getAppValue($appId, 'enabled', 'yes'));
        } catch (\Throwable) {
        }
        $decoded = json_decode($enabledRaw, true);
        if (is_array($decoded)) {
            $groups = isset($decoded['groups']) && is_array($decoded['groups'])
                ? $decoded['groups'] : $decoded;
            foreach ($groups as $g) {
                if (is_string($g) && $g !== '') {
                    $groupArgs .= ' --groups ' . escapeshellarg($g);
                }
            }
        }
        exec(sprintf(
            '%s %s app:enable %s%s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($occPath),
            escapeshellarg($appId),
            $groupArgs
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

    /** GitLab-native Apps (eigenes GitLab) — Update-Quelle nicht GitHub. */
    private function isGitlabApp(): bool
    {
        // Seit der GitLab-Migration liegen ALLE Souvera-Apps auf
        // git.host-on.dev — der GitHub-Pfad ist toter Übergangscode.
        return true;
    }

    private function gitlabBase(): string
    {
        try {
            $u = \OCP\Server::get(\OCP\IConfig::class)
                ->getSystemValue('souvera.gitlab_url', 'https://git.host-on.dev');
        } catch (\Throwable) {
            $u = 'https://git.host-on.dev';
        }
        return rtrim(trim((string) $u), '/');
    }

    private function gitlabProjectEncoded(): string
    {
        return str_replace('/', '%2F', $this->getRepo());
    }

    private function readGitlabToken(): string
    {
        try {
            $t = \OCP\Server::get(\OCP\IConfig::class)
                ->getSystemValue('souvera.gitlab_devops_token', '');
            return trim((string) $t);
        } catch (\Throwable) {
            return '';
        }
    }

    private function gitlabApiGet(string $url): ?array
    {
        $token = $this->readGitlabToken();
        if ($token === '') {
            return null;
        }
        $client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
        try {
            $response = $client->get($url, [
                'headers' => [
                    'PRIVATE-TOKEN' => $token,
                    'User-Agent' => 'Souvera-DevOps',
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
        try {
            return json_decode((string) $response->getBody(), true);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getRepo(): string
    {
        // ALLE Apps liegen auf GitLab: https://git.host-on.dev/souvera/<app>
        return match ($this->getAppId()) {
            'souvera_mail' => 'souvera/souvera_mail',
            'souvera_central' => 'souvera/souvera_central',
            'souvera_shield' => 'souvera/souvera_shield',
            'souvera_mailarchiv' => 'souvera/souvera_mailarchiv',
            'souvera_documents' => 'souvera/souvera_documents',
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
        if ($this->isGitlabApp()) {
            $token = $this->readGitlabToken();
        }
        try {
            $headers = $this->isGitlabApp()
                ? ['PRIVATE-TOKEN' => $token, 'User-Agent' => 'Souvera-DevOps']
                : [
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'Souvera-DevOps',
                    'Accept' => 'application/vnd.github+json',
                ];
            $response = $client->get($url, [
                'headers' => $headers,
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
