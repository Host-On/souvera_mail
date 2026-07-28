<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

/**
 * Self-update via GitHub Releases API (ZIP download).
 * No git, no gh CLI, no webhooks required.
 *
 * Reads token from config.php: 'souvera.devops_token'
 * Runs as a Nextcloud background job every 15 minutes.
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
            $last = (int) $config->getAppValue($appId, 'devops.last_check', '0');
            if ($last > time() - 3 * 3600) {
                return ['skipped' => true, 'reason' => 'Rate-limited'];
            }
        }
        $config->setAppValue($appId, 'devops.last_check', (string) time());

        $installed = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion($appId);
        if ($installed === '0') {
            return ['error' => 'No version'];
        }

        $appPath = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppPath($appId);
        if ($appPath === null) {
            return ['error' => 'App not found'];
        }
        $branch = trim((string) $config->getAppValue($appId, 'devops.branch', 'main'));

        if ($channel === 'dev') {
            return $this->downloadBranch($appId, $appPath, $branch);
        }

        $latest = $this->latestReleaseTag();
        if ($latest === null) {
            return ['error' => 'Cannot fetch releases'];
        }
        if (version_compare($latest, $installed, '<=')) {
            return ['up_to_date' => true, 'installed' => $installed, 'latest' => $latest];
        }
        return $this->downloadTag($appId, $appPath, $latest);
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
        return ltrim((string) $data['tag_name'], 'v');
    }

    private function downloadBranch(string $appId, string $appPath, string $branch): array
    {
        $repo = $this->getRepo();
        if ($repo === '') {
            return ['error' => 'Unknown app'];
        }
        $url = "https://api.github.com/repos/$repo/zipball/$branch";
        return $this->downloadAndApply($appId, $appPath, $url);
    }

    private function downloadTag(string $appId, string $appPath, string $tag): array
    {
        $repo = $this->getRepo();
        if ($repo === '') {
            return ['error' => 'Unknown app'];
        }
        $url = "https://api.github.com/repos/$repo/zipball/v$tag";
        return $this->downloadAndApply($appId, $appPath, $url);
    }

    private function downloadAndApply(string $appId, string $appPath, string $url): array
    {
        $token = $this->readToken();
        if ($token === '') {
            return ['error' => 'No devops token configured'];
        }

        $zipContent = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Souvera-DevOps\r\nAuthorization: Bearer $token\r\n",
                'timeout' => 60,
                'follow_location' => 1,
            ],
        ]));

        if ($zipContent === false) {
            return ['error' => 'Download failed'];
        }

        $tmpZip = sys_get_temp_dir() . "/{$appId}_update.zip";
        file_put_contents($tmpZip, $zipContent);

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
        $this->copyRecursive($sourceDir, $appPath);
        $this->rmdirRecursive($extractDir);

        return $this->enableApp($appId, $appPath);
    }

    private function enableApp(string $appId, string $appPath): array
    {
        $occOut = [];
        $occExit = 0;
        $occPath = \OC::$SERVERROOT . '/occ';
        exec(sprintf(
            'php %s app:enable %s 2>&1',
            escapeshellarg($occPath),
            escapeshellarg($appId)
        ), $occOut, $occExit);
        return [
            'success' => true,
            'occ_log' => implode("\n", $occOut),
            'occ_exit' => $occExit,
        ];
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
        $json = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Souvera-DevOps\r\nAuthorization: Bearer $token\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 15,
            ],
        ]));
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function copyRecursive(string $src, string $dst): void
    {
        $dir = opendir($src);
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
                copy($sp, $dp);
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
