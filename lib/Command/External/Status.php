<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command\External;

use OCA\SouveraMail\Service\ExternalAccountsConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only status dump of the external-accounts feature.
 *
 * This command is safe to invoke from any monitoring / health-probe
 * script. It exits 0 regardless of the feature state so shell pipes
 * do not break.
 *
 * The state itself lives in `souvera_central`. This wrapper is a
 * convenience for operators who are already SSH'd into a Souvera
 * Mail-focused shell — they can inspect Central's state without
 * having to remember Central's own command name.
 */
final class Status extends Command
{
    public function __construct(private ExternalAccountsConfig $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('souvera_mail:external:status')
            ->setDescription('Show the external-mail-account feature configuration (proxied from souvera_central)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $snap = $this->config->snapshot();
        if ($input->getOption('json')) {
            $output->writeln(\json_encode($snap, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            return 0;
        }
        $output->writeln('<info>Souvera Mail — External Accounts feature</info>');
        $output->writeln('  enabled           : ' . ($snap['enabled'] ? '<info>yes</info>' : '<comment>no</comment>'));
        $output->writeln('  allowed_groups    : ' . (empty($snap['allowed_groups']) ? '(all users)' : \implode(', ', $snap['allowed_groups'])));
        $output->writeln('  max_per_user      : ' . $snap['max_per_user']);
        $output->writeln('  consent_required  : ' . ($snap['consent_required'] ? 'yes' : 'no'));
        $output->writeln('  smtp_fail_guard   : ' . ($snap['smtp_fail_guard'] ? 'yes' : 'no'));
        $output->writeln('  migration_handoff : ' . ($snap['migration_handoff'] ? 'yes' : 'no'));
        $output->writeln('  source            : ' . ($snap['_source'] ?? 'unknown'));

        $centralPresent = (bool) ($snap['central_present'] ?? ($snap['_source'] === 'souvera_central'));
        if (!$centralPresent) {
            $output->writeln('');
            $output->writeln('<comment>souvera_central is not installed or not exposing ExternalAccountsConfigService.</comment>');
            $output->writeln('<comment>Feature is treated as DISABLED. To enable, install/upgrade souvera_central and run:</comment>');
            $output->writeln('    occ souvera_central:external:enable');
        }
        return 0;
    }
}
