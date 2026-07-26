<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DevopsChannel extends Command
{
    public function __construct(private IConfig $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:devops:channel')
            ->setDescription('Switch update channel: stable (releases) or dev (every push)')
            ->addArgument('channel', InputArgument::REQUIRED, 'stable or dev');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = \strtolower(\trim((string) $input->getArgument('channel')));
        if (!\in_array($channel, ['stable', 'dev'], true)) {
            $output->writeln('<error>Channel must be "stable" or "dev"</error>');
            return Command::FAILURE;
        }

        $this->config->setAppValue('souvera_mail', 'devops.channel', $channel);
        $interval = $channel === 'dev' ? '15 min' : '3 hours';
        $output->writeln("<info>Update channel set to '$channel' (check every $interval)</info>");
        return Command::SUCCESS;
    }
}
