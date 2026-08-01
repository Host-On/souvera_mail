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
        if (!$this->userManager->userExists($uid)) {
            $output->writeln('<error>User "' . $uid . '" does not exist</error>');
            return Command::FAILURE;
        }

        $email = $uid;
        $settingsBase = $dataPath . '/_data_/_default_/storage/' . $email . '/config/.config/' . $uid;
        $settingsFile = $settingsBase . '/settings.json';
        $settingsLocalFile = $settingsBase . '/settings_local.json';

        $output->writeln('settings dir     : ' . $settingsBase);
        $output->writeln('  exists         : ' . (\is_dir($settingsBase) ? 'yes' : 'NO'));
        $output->writeln('  writable       : ' . (\is_dir($settingsBase) && \is_writable($settingsBase) ? 'yes' : 'NO'));

        foreach (['settings.json' => $settingsFile, 'settings_local.json' => $settingsLocalFile] as $label => $file) {
            if (\is_file($file)) {
                $size = \filesize($file);
                $mtime = \date('Y-m-d H:i:s', (int) \filemtime($file));
                $output->writeln('  ' . $label . ' : exists (' . $size . ' bytes, modified ' . $mtime . ')');
                $output->writeln('  ' . $label . ' : ' . \mb_substr((string) \file_get_contents($file), 0, 300));
            } else {
                $output->writeln('  ' . $label . ' : NOT FOUND');
            }
        }

        return Command::SUCCESS;
    }
}
