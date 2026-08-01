<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\DevOps\SelfUpdateTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ souvera_mail:self-update` — explicitly pull the newest GitHub
 * version for ALL managed Souvera apps (mail, central, shield).
 *
 * Nextcloud's built-in `occ app:update` does nothing for custom apps
 * ("is up-to-date or no updates could be found" — they are not in the
 * App Store), so this is the reliable manual trigger. The pre-update
 * repair step additionally runs on the automatic version-difference
 * path during normal app loading.
 */
class SelfUpdate extends Command {

    use SelfUpdateTrait;

    private string $appId = 'souvera_mail';

    protected function configure(): void {
        $this
            ->setName('souvera_mail:self-update')
            ->setDescription('Pull newest GitHub version for all managed Souvera apps');
    }

    protected function getAppId(): string {
        return $this->appId;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $ok = true;
        foreach (['souvera_mail', 'souvera_central', 'souvera_shield'] as $appId) {
            try {
                $this->appId = $appId;
                $output->writeln('checking ' . $appId . ' …');
                $config = \OCP\Server::get(\OCP\IConfig::class);
                // Explicit manual run: always check, ignore the 3h throttle.
                $config->setAppValue($appId, 'devops.last_check', '0');
                $result = $this->checkAndUpdate();
                $output->writeln($appId . ': ' . json_encode($result, JSON_UNESCAPED_SLASHES));
                if (!empty($result['error'])) {
                    $ok = false;
                }
            } catch (\Throwable $e) {
                $output->writeln('<error>' . $appId . ': ' . $e->getMessage() . '</error>');
                $ok = false;
            }
        }
        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
