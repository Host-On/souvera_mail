<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Toggles the v2 Vue-3 mail client on/off. SnappyMail (v1) remains the default
 * until explicitly enabled. Both versions coexist in the same app — v2 does not
 * touch v1 code.
 *
 * Usage:
 *   occ souvera_mail:v2:toggle enable   → activates v2
 *   occ souvera_mail:v2:toggle disable  → falls back to SnappyMail (v1)
 */
class V2Toggle extends Command
{
	public function __construct(private IConfig $config)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('souvera_mail:v2:toggle')
			->setDescription('Switch between SnappyMail (v1) and the new Vue-3 client (v2)')
			->addArgument('state', InputArgument::REQUIRED, '"enable" or "disable"');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$state = \strtolower(\trim((string) $input->getArgument('state')));
		if (!\in_array($state, ['enable', 'disable'], true)) {
			$output->writeln('<error>State must be "enable" or "disable"</error>');
			return Command::FAILURE;
		}
		$value = $state === 'enable' ? '1' : '0';
		$this->config->setAppValue('souvera_mail', 'v2_enabled', $value);
		$output->writeln("<info>Mail v2 is now " . ($value === '1' ? 'ENABLED' : 'DISABLED (SnappyMail v1)') . '</info>');
		return Command::SUCCESS;
	}
}
