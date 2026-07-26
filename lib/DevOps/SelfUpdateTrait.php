<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

/**
 * Self-update via GitHub Releases API with a single token in config.php.
 *
 * No git, no gh CLI, no webhooks, no per-app setup.
 *
 * ONE-TIME SERVER SETUP:
 *   Add to config/config.php:
 *     'souvera.devops_token' => 'github_pat_...',
 *
 * USAGE:
 *   occ souvera_mail:devops:channel dev     (every 15 min, every push)
 *   occ souvera_mail:devops:channel stable  (every 3 hours, releases only)
 */
trait SelfUpdateTrait
{
    abstract protected function getAppId(): string;

    public function checkAndUpdate(): array
    {
        $appId = $this->getAppId();
        $config = \OCP\Server::get(\OCP\IConfig::class);
        $channel = \trim((string) $config->getAppValue($appId, 'devops.channel', 'stable'));

        if ($channel === 'stable') {
            $last = (int) $config->getAppValue($appId, 'devops.last_check', '0');
            if ($last > \time() - 3 * 3600) {
                return ['skipped' => true, 'reason' => 'Rate-limited'];
            }
        }
        $config->setAppValue($appId, 'devops.last_check', (string) \time());

        $installed = \OC_App::getAppVersion($appId);
        if ($installed === '0') return ['error' => 'No version'];

        $appPath = \OC_App::getAppPath($appId);
        $branch = \trim((string) $config->getAppValue($appId, 'devops.branch', 'main'));

        if ($channel === 'dev') {
            return $this->pullDev($appId, $appPath, $branch);
        }

        $latest = $this->latestTag();
        if ($latest === null) return ['error' => 'API unreachable'];
        if (\version_compare($latest, $installed, '<=')) {
            return ['up_to_date' => true, 'installed' => $installed, 'latest' => $latest];
        }
        return $this->applyTag($appId, $appPath, $latest);
    }

    private function latestTag(): ?string
    {
        $repo = match ($this->getAppId()) {
            'souvera_mail' => 'PhiGi87/souvera_mail',
            'souvera_central' => 'PhiGi87/souvera_central',
            'souvera_shield' => 'PhiGi87/souvera_shield',
            default => '',
        };
        if ($repo === '') return null;

        $tagName = $this->apiGet("repos/$repo/releases/latest", 'tag_name');
        return $tagName ? \ltrim((string) $tagName, 'v') : null;
    }

    private function pullDev(string $appId, string $appPath, string $branch): array
    {
        $repo = match ($appId) {
            'souvera_mail' => 'PhiGi87/souvera_mail',
            'souvera_central' => 'PhiGi87/souvera_central',
            'souvera_shield' => 'PhiGi87/souvera_shield',
            default => '',
        };
        if ($repo === '') return ['error' => 'Unknown app'];

        // Download latest commit as zip from GitHub, extract, enable
        $token = $this->token();
        $archiveUrl = "https://api.github.com/repos/$repo/zipball/$branch";
        $appDir = \dirname($appPath);
        $tmpZip = \sys_get_temp_dir() . "/{$appId}_update.zip";

        $zipContent = @\file_get_contents($archiveUrl, false, \stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: Souvera\r\nAuthorization: Bearer $token\r\n",
            'timeout' => 60,
            'follow_location' => 1,
        ]]));
        if ($zipContent === false) return ['error' => 'Download failed'];

        \file_put_contents($tmpZip, $zipContent);

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            \unlink($tmpZip);
            return ['error' => 'ZIP extract failed'];
        }

        // GitHub zipball extracts to <repo>-<commit>/ — find that dir
        $extractDir = \sys_get_temp_dir() . "/{$appId}_extract";
        @\mkdir($extractDir, 0755, true);
        $zip->extractTo($extractDir);
        $zip->close();
        \unlink($tmpZip);

        // Find the extracted root dir
        $dirs = \glob("$extractDir/*", GLOB_ONLYDIR);
        if (empty($dirs)) return ['error' => 'Empty archive'];
        $sourceDir = $dirs[0];

        // Move files into app dir (overwrite)
        $this->recursiveCopy($sourceDir, $appPath);
        $this->recursiveDelete($extractDir);

        return $this->enableApp($appId, $appPath);
    }

    private function applyTag(string $appId, string $appPath, string $tag): array
    {
        $repo = match ($appId) {
            'souvera_mail' => 'PhiGi87/souvera_mail',
            'souvera_central' => 'PhiGi87/souvera_central',
            'souvera_shield' => 'PhiGi87/souvera_shield',
            default => '',
        };
        if ($repo === '') return ['error' => 'Unknown app'];

        $token = $this->token();
        $url = "https://api.github.com/repos/$repo/zipball/v$tag";
        $appDir = \dirname($appPath);
        $tmpZip = \sys_get_temp_dir() . "/{$appId}_v{$tag}.zip";

        $zipContent = @\file_get_contents($url, false, \stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: Souvera\r\nAuthorization: Bearer $token\r\n",
            'timeout' => 60,
            'follow_location' => 1,
        ]]));
        if ($zipContent === false) return ['error' => 'Tag download failed'];

        \file_put_contents($tmpZip, $zipContent);

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) { \unlink($tmpZip); return ['error' => 'ZIP failed']; }

        $extractDir = \sys_get_temp_dir() . "/{$appId}_v{$tag}";
        @\mkdir($extractDir, 0755, true);
        $zip->extractTo($extractDir);
        $zip->close();
        \unlink($tmpZip);

        $dirs = \glob("$extractDir/*", GLOB_ONLYDIR);
        if (empty($dirs)) return ['error' => 'Empty tag archive'];
        $sourceDir = $dirs[0];

        $this->recursiveCopy($sourceDir, $appPath);
        $this->recursiveDelete($extractDir);

        return $this->enableApp($appId, $appPath);
    }

    private function enableApp(string $appId, string $appPath): array
    {
        $occOut = [];
        $occExit = 0;
        \exec(\sprintf('php %s/occ app:enable %s 2>&1',
            \escapeshellarg(\dirname($appPath, 3)), \escapeshellarg($appId)
        ), $occOut, $occExit);
        return [
            'success' => true,
            'occ_log' => \implode("\n", $occOut),
            'occ_exit' => $occExit,
        ];
    }

    private function token(): string
    {
        try {
            return \trim((string) \OCP\Server::get(\OCP\IConfig::class)
                ->getSystemValue('souvera.devops_token', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function apiGet(string $path, string $field): mixed
    {
        $token = $this->token();
        if ($token === '') return null;
        $json = @\file_get_contents("https://api.github.com/$path", false, \stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: Souvera\r\nAuthorization: Bearer $token\r\nAccept: application/vnd.github+json\r\n",
            'timeout' => 15,
        ]]));
        if ($json === false) return null;
        $data = \json_decode($json, true);
        return $data[$field] ?? null;
    }

    private function recursiveCopy(string $src, string $dst): void
    {
        $dir = \opendir($src);
        @\mkdir($dst, 0755, true);
        while (($file = \readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $sp = "$src/$file";
            $dp = "$dst/$file";
            if (\is_dir($sp)) {
                $this->recursiveCopy($sp, $dp);
            } else {
                \copy($sp, $dp);
            }
        }
        \closedir($dir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!\is_dir($dir)) return;
        foreach (\scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            \is_dir($p) ? $this->recursiveDelete($p) : \unlink($p);
        }
        \rmdir($dir);
    }
}
