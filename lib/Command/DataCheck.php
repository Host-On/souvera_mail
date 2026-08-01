<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\DomainConfigService;
use OCA\SouveraMail\Util\EngineHelper;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ souvera_mail:data:check [uid]` — full persistence diagnostics.
 *
 * Boots the engine like a web request does, then resolves EVERY candidate
 * storage root and shows the EXACT paths the settings save would use
 * (plugin override AND engine fallback), verifies write access with a
 * real probe write, and scans every plausible data directory for
 * settings*.json files.
 *
 * Purpose: "my webmail settings are not saved persistently" — this
 * command makes visible in one run:
 *   - which data root the engine REALLY uses at runtime
 *   - whether a settings write would succeed (permissions / open_basedir)
 *   - where settings files ACTUALLY live (incl. engine-default `data/`
 *     inside the app dir, which vanishes on every re-deploy)
 */
class DataCheck extends Command {

    public function __construct(
        private DomainConfigService $domainService,
        private EngineHelper $engineHelper,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('souvera_mail:data:check')
            ->setDescription('Full engine settings-persistence diagnostics')
            ->addArgument('uid', InputArgument::OPTIONAL, 'Nextcloud user id (resolves their settings file)')
            ->addOption('simulate-save', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Run a real engine StorageProvider Put/Get round-trip');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $this->bootEngine();
        $dataPath = $this->domainService->getDataPath();

        $output->writeln('=== Engine data root ===');
        $output->writeln('datadirectory      : ' . $dataPath);
        $output->writeln('MULTIDOMAIN        : ' . (\defined('MULTIDOMAIN') ? 'ON (settings are per host!)' : 'off'));
        $output->writeln('APP_DATA_FOLDER_PATH: ' . (\defined('APP_DATA_FOLDER_PATH') ? APP_DATA_FOLDER_PATH : 'NOT SET'));
        $output->writeln('APP_PRIVATE_DATA   : ' . (\defined('APP_PRIVATE_DATA') ? APP_PRIVATE_DATA : 'NOT SET'));
        $output->writeln('data root exists   : ' . (\is_dir($dataPath) ? 'yes' : 'NO') . ', writable: ' . (\is_dir($dataPath) && \is_writable($dataPath) ? 'yes' : 'NO'));

        // Engine-default data dirs INSIDE the app — these vanish on every
        // re-deploy (git-clone) and are the classic "not persistent" cause.
        $appDataDefault = (\defined('APP_INDEX_ROOT_PATH') ? APP_INDEX_ROOT_PATH : \dirname(__DIR__, 2) . '/app/') . 'data/';
        $output->writeln('engine default data: ' . $appDataDefault
            . ' -> ' . (\is_dir($appDataDefault) ? 'EXISTS (!)' : 'absent'));

        $this->showMount($output, $dataPath);

        $uid = (string) ($input->getArgument('uid') ?: '');
        if ($uid === '') {
            $output->writeln('(pass a uid to inspect the per-user settings file)');
            return Command::SUCCESS;
        }
        $user = $this->userManager->get($uid);
        if ($user === null) {
            $output->writeln('<error>User "' . $uid . '" does not exist</error>');
            return Command::FAILURE;
        }

        $email = (string) ($user->getEMailAddress() ?: $uid);
        $parts = \explode('@', $email);
        $domain = \trim(1 < \count($parts) ? \array_pop($parts) : '');
        $localpart = \implode('@', $parts) ?: '.unknown';

        $output->writeln('');
        $output->writeln('=== Per-user paths (email ' . $email . ') ===');

        // 1) Plugin path — what a logged-in browser request uses.
        $pluginPath = $this->pluginConfigDir($domain, $localpart, $uid);
        $output->writeln('plugin path       : ' . $pluginPath);
        $this->showDirStatus($output, $pluginPath);
        $this->probeWrite($output, $pluginPath);

        // 2) Engine fallback — what an unauthenticated / non-plugin write uses.
        $fallbackDir = APP_PRIVATE_DATA . 'storage/'
            . ($domain !== '' ? $domain : 'unknown.tld') . '/' . $localpart;
        $output->writeln('fallback path     : ' . $fallbackDir);
        $this->showDirStatus($output, $fallbackDir);
        $this->probeWrite($output, $fallbackDir);

        // 3) Files in each candidate location.
        foreach ([
            'plugin settings'     => $pluginPath . '/settings.json',
            'plugin settings_local' => $pluginPath . '/settings_local.json',
            'fallback settings'   => $fallbackDir . '/settings.json',
            'fallback settings_local' => $fallbackDir . '/settings_local.json',
        ] as $label => $file) {
            $output->writeln('  ' . $label . ' : ' . (\is_file($file) ? 'exists (' . \filesize($file) . ' bytes, modified ' . \date('Y-m-d H:i:s', (int) \filemtime($file)) . ')' : 'NOT FOUND'));
        }

        $output->writeln('');
        $output->writeln('=== Scan: settings*.json across ALL candidate roots ===');
        $roots = [];
        if (\is_dir($dataPath)) {
            $roots[] = $dataPath;                      // NC datadir / appdata_souvera_mail
        }
        $appRoot = \defined('APP_INDEX_ROOT_PATH') ? APP_INDEX_ROOT_PATH : \dirname(__DIR__, 2) . '/app/';
        $roots[] = $appRoot . 'data/';                  // engine default (in-app)
        $roots[] = $appRoot . 'app/data/';              // legacy app-level variant
        $found = 0;
        foreach (\array_unique($roots) as $root) {
            $root = \rtrim($root, '\\/');
            $output->writeln('root: ' . $root . ' -> ' . (\is_dir($root) ? 'dir' : 'absent'));
            if (!\is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $fileInfo) {
                if ($fileInfo->isFile() && \preg_match('/^settings.*\.json$/', $fileInfo->getFilename())) {
                    $found++;
                    $output->writeln('  ' . $fileInfo->getPathname() . ' (' . $fileInfo->getSize() . ' bytes, modified ' . \date('Y-m-d H:i:s', $fileInfo->getMTime()) . ')');
                }
            }
        }
        $output->writeln('  (' . $found . ' settings file(s) found across all roots)');

        $output->writeln('');
        $output->writeln('=== Hints ===');
        $output->writeln('- If settings files sit under "engine default data" (in-app), they are wiped by every git-clone deploy.');
        $output->writeln('- If nothing was written at all, check nextcloud.log for "FileStorage" / "Failed to save" warnings.');
        $output->writeln('- open_basedir: ' . (\ini_get('open_basedir') ?: 'not set (unrestricted)'));

        if ($input->getOption('simulate-save')) {
            $output->writeln('');
            $this->simulateSave($output, $email);
        }

        return Command::SUCCESS;
    }

    /**
     * Run a REAL Put/Get round-trip through the live engine storage
     * providers. The engine is booted like a web request would; the
     * providers are the exact instances the Settings page uses. A
     * string account is accepted by GenerateFilePath as an email.
     */
    private function simulateSave(OutputInterface $output, string $email): void {
        try {
            $oActions = \Smail\Engine\Api::Actions();
            $oStorage = $oActions->StorageProvider();
            $oLocal = $oActions->LocalStorageProvider();

            foreach (['StorageProvider()' => $oStorage, 'LocalStorageProvider()' => $oLocal] as $label => $provider) {
                try {
                    $filePath = $provider->GenerateFilePath($email, \Smail\Engine\Providers\Storage\Enumerations\StorageType::CONFIG->value, false);
                } catch (\Throwable $e) {
                    $output->writeln('  [' . $label . '] GenerateFilePath threw: ' . $e->getMessage());
                    continue;
                }
                $output->writeln('  [' . $label . '] CONFIG dir for ' . $email . ' -> ' . $filePath);
                $output->writeln('    exists: ' . (\is_dir($filePath) ? 'yes' : 'NO'));

                $probe = '{"__probe":true}';
                $okPut = $provider->Put($email, \Smail\Engine\Providers\Storage\Enumerations\StorageType::CONFIG->value, '__probe', $probe);
                $output->writeln('    Put("__probe") returned: ' . ($okPut ? 'true' : 'FALSE'));
                $probeFile = $filePath . '/__probe';
                $output->writeln('    probe file exists after Put: ' . (\is_file($probeFile) ? 'yes (' . \filesize($probeFile) . ' bytes)' : 'NO'));
                $read = $provider->Get($email, \Smail\Engine\Providers\Storage\Enumerations\StorageType::CONFIG->value, '__probe');
                $output->writeln('    Get("__probe") returned: ' . ($read === $probe ? 'MATCH' : (\is_string($read) ? 'DIFFERENT: ' . \mb_substr($read, 0, 80) : 'no value (' . \var_export($read, true) . ')')));
                $provider->Clear($email, \Smail\Engine\Providers\Storage\Enumerations\StorageType::CONFIG->value, '__probe');
                $output->writeln('    probe cleaned: ' . (!\is_file($probeFile) ? 'yes' : 'NO'));
            }
        } catch (\Throwable $e) {
            $output->writeln('  simulate-save crashed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    /** Boot the engine exactly like a web request (best-effort). */
    private function bootEngine(): void {
        try {
            $this->engineHelper->loadApp();
        } catch (\Throwable $e) {
            \fwrite(STDERR, 'engine boot failed (continuing with static paths): ' . $e->getMessage() . PHP_EOL);
        }
    }

    /** Storage path the NextcloudStorage plugin override produces. */
    private function pluginConfigDir(string $domain, string $localpart, string $uid): string {
        $base = \defined('APP_PRIVATE_DATA') ? APP_PRIVATE_DATA : $this->domainService->getDataPath() . '/_data_/_default_/';
        return $base . 'storage/'
            . ($domain !== '' ? $domain : 'unknown.tld') . '/' . $localpart
            . '/.config/' . $uid;
    }

    private function showDirStatus(OutputInterface $output, string $dir): void {
        $exists = \is_dir($dir);
        $output->writeln('  exists   : ' . ($exists ? 'yes' : 'NO'));
        $output->writeln('  writable : ' . ($exists && \is_writable($dir) ? 'yes' : 'NO'));
    }

    /** Real probe write — creates the directory chain, then removes the file. */
    private function probeWrite(OutputInterface $output, string $dir): void {
        $probe = $dir . '/.write_probe';
        try {
            if (\is_dir($dir) || \mkdir($dir, 0700, true)) {
                if (\file_put_contents($probe, 'probe') !== false) {
                    $output->writeln('  PROBE WRITE OK -> ' . $probe);
                    \unlink($probe);
                } else {
                    $output->writeln('  PROBE WRITE FAILED: file_put_contents returned false');
                }
            } else {
                $output->writeln('  PROBE WRITE FAILED: mkdir(' . $dir . ') returned false');
            }
        } catch (\Throwable $e) {
            $output->writeln('  PROBE WRITE FAILED: ' . $e->getMessage());
        }
    }

    private function showMount(OutputInterface $output, string $path): void {
        $parent = $path;
        while (!\is_dir($parent) && $parent !== \dirname($parent)) {
            $parent = \dirname($parent);
        }
        $output->writeln('closest existing dir: ' . $parent);
        if (\is_dir($parent)) {
            $st = \stat($parent);
            $out = [];
            \exec('df -T ' . \escapeshellarg($parent) . ' 2>/dev/null', $out, $rc);
            if ($rc === 0 && $out) {
                $output->writeln('filesystem (df -T): ' . \implode(' | ', $out));
            } else {
                $output->writeln('filesystem (df -T): not available (exec disabled?)');
            }
            if ($st !== false) {
                $output->writeln('owner of ' . $parent . ': uid=' . $st['uid'] . ', gid=' . $st['gid'] . ' (this process euid=' . (\function_exists('posix_geteuid') ? posix_geteuid() : '?') . ')');
            }
        }
    }
}
