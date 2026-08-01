<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\DomainConfigService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ souvera_mail:data:check [uid]` — resolves the engine data directory
 * and the per-user settings storage path, verifies write access and shows
 * whether the settings file actually exists and when it was last modified.
 *
 * Purpose: "my webmail settings are not saved persistently" usually means
 * either the settings file is written somewhere unexpected (MULTIDOMAIN
 * switch, local-vs-central storage) or writes fail silently. This command
 * makes both visible in one shot.
 */
class DataCheck extends Command {

    public function __construct(
        private DomainConfigService $domainService,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('souvera_mail:data:check')
            ->setDescription('Show engine data path + per-user settings storage status')
            ->addArgument('uid', InputArgument::OPTIONAL, 'Nextcloud user id (resolves their settings file)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $dataPath = $this->domainService->getDataPath();
        $output->writeln('Engine data root : ' . $dataPath);
        $output->writeln('MULTIDOMAIN      : ' . (\defined('MULTIDOMAIN') ? 'ON (settings are per host!)' : 'off'));
        $output->writeln('APP_PRIVATE_DATA : ' . (\defined('APP_PRIVATE_DATA') ? APP_PRIVATE_DATA : '(engine not booted yet)'));

        $rootWritable = \is_dir($dataPath) && \is_writable($dataPath);
        $output->writeln('data root exists : ' . (\is_dir($dataPath) ? 'yes' : 'NO') . ', writable: ' . ($rootWritable ? 'yes' : 'NO'));

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

        // Mirror the engine's FileStorage layout exactly:
        // <dataPath>/_data_/_default_/storage/<domain>/<localpart>/.config/<uid>/settings[|_local].json
        // The .config/<uid> part is added by the NextcloudStorage plugin
        // override; WITHOUT the plugin (or when the plugin's isLoggedIn()
        // check fails) the engine writes directly into
        // <dataPath>/_data_/_default_/storage/<domain>/<localpart>/.
        $email = (string) ($user->getEMailAddress() ?: $uid);
        $parts = \explode('@', $email);
        $domain = \trim(1 < \count($parts) ? \array_pop($parts) : '');
        $localpart = \implode('@', $parts) ?: '.unknown';
        $storageBase = $dataPath . '/_data_/_default_/storage/'
            . ($domain !== '' ? $domain : 'unknown.tld')
            . '/' . $localpart;
        $settingsBase = $storageBase . '/.config/' . $uid;

        $output->writeln('resolved email  : ' . $email);
        $output->writeln('plugin path      : ' . $settingsBase);
        $output->writeln('  exists         : ' . (\is_dir($settingsBase) ? 'yes' : 'NO'));
        $output->writeln('  writable       : ' . (\is_dir($settingsBase) && \is_writable($settingsBase) ? 'yes' : 'NO'));
        $output->writeln('fallback path    : ' . $storageBase . '  (without .config/<uid>)');
        $output->writeln('  exists         : ' . (\is_dir($storageBase) ? 'yes' : 'NO'));

        foreach (['settings.json' => $settingsBase . '/settings.json', 'settings_local.json' => $settingsBase . '/settings_local.json',
                  'settings.json (fallback)' => $storageBase . '/settings.json', 'settings_local.json (fallback)' => $storageBase . '/settings_local.json'] as $label => $file) {
            if (\is_file($file)) {
                $size = \filesize($file);
                $mtime = \date('Y-m-d H:i:s', (int) \filemtime($file));
                $output->writeln('  ' . $label . ' : exists (' . $size . ' bytes, modified ' . $mtime . ')');
            } else {
                $output->writeln('  ' . $label . ' : NOT FOUND');
            }
        }

        // Ground truth: scan the whole engine data tree for settings files
        // — this shows where writes ACTUALLY land, whatever the code path.
        $output->writeln('---');
        $output->writeln('scan: all settings*.json under ' . $dataPath . ':');
        $found = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $fileInfo) {
            if ($fileInfo->isFile() && \preg_match('/^settings.*\.json$/', $fileInfo->getFilename())) {
                $found++;
                $output->writeln('  ' . $fileInfo->getPathname() . ' (' . $fileInfo->getSize() . ' bytes, modified ' . \date('Y-m-d H:i:s', $fileInfo->getMTime()) . ')');
            }
        }
        $output->writeln('  (' . $found . ' settings file(s) found)');

        return Command::SUCCESS;
    }
}
