<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\MigrationService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reset the migration state for a user — clears "dismissed" / "completed"
 * so the migration wizard becomes available again.
 *
 *     occ souvera_mail:migration:reset joerg
 */
class MigrationReset extends Command
{
    public function __construct(
        private IUserManager $userManager,
        private MigrationService $migrations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:migration:reset')
            ->setDescription('Reset the migration welcome state and cancel any active migration for a user')
            ->addArgument('uid', InputArgument::REQUIRED, 'Nextcloud user id (e.g. "joerg" — NOT the email)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (string) $input->getArgument('uid');
        $user = $this->userManager->get($uid);
        if ($user === null) {
            $output->writeln("<error>User '{$uid}' does not exist.</error>");
            return 1;
        }

        $dismissed = $this->migrations->resetForUser($uid);
        $output->writeln("<info>Migration state reset for user '{$uid}' ({$dismissed} job row(s) dismissed).</info>");
        $output->writeln('The migration assistant is now available again in the settings.');
        return 0;
    }
}
