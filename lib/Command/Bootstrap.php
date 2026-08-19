<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\OidcProviderService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-shot install command for declarative deploys. Runs three things in
 * order, each idempotent:
 *
 *   1. Verifies the H2CK/oidc app is installed + enabled and `default_token_type=jwt`.
 *   2. Registers a souvera_mail OIDC client in H2CK/oidc (skips if already present).
 *   3. Writes the IMAP/SMTP/Sieve domain profile via `souvera_mail:setup` (skips
 *      preflight unless `--check`, lets the operator decide whether to fail
 *      the deploy on network hiccups).
 *
 * Exits non-zero on any blocker so deploy pipelines fail fast. With `--json`,
 * a single combined report is printed to stdout — perfect for the Ansible
 * `command:` module or k8s init containers.
 */
class Bootstrap extends Command
{
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private OidcProviderService $oidcProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:bootstrap')
            ->setDescription('One-shot setup for automated deploys: register OIDC client + configure mail-server profile')
            // OIDC client options (forwarded to souvera_mail:oidc:register-client)
            ->addOption('client-name', null, InputOption::VALUE_REQUIRED, 'OIDC client name in H2CK/oidc', 'souvera_mail')
            ->addOption('client-secret-out', null, InputOption::VALUE_REQUIRED, 'Write the generated client_secret to this file (0600)')
            ->addOption('token-lifetime', null, InputOption::VALUE_REQUIRED, 'OIDC access-token lifetime in seconds (sets app-global oidc/expire_time)', '1800')
            // Mail server options (forwarded to souvera_mail:setup)
            ->addOption('mail-imap-host', null, InputOption::VALUE_REQUIRED, 'IMAP hostname')
            ->addOption('mail-imap-port', null, InputOption::VALUE_REQUIRED, 'IMAP port', '993')
            ->addOption('mail-imap-ssl', null, InputOption::VALUE_REQUIRED, 'IMAP SSL mode (none|ssl|starttls)', 'ssl')
            ->addOption('mail-smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP hostname (defaults to IMAP host)')
            ->addOption('mail-smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP port', '465')
            ->addOption('mail-smtp-ssl', null, InputOption::VALUE_REQUIRED, 'SMTP SSL mode', 'ssl')
            ->addOption('mail-sieve-host', null, InputOption::VALUE_REQUIRED, 'Sieve hostname (omit to disable ManageSieve)')
            ->addOption('mail-sieve-port', null, InputOption::VALUE_REQUIRED, 'Sieve port', '4190')
            ->addOption('mail-sieve-ssl', null, InputOption::VALUE_REQUIRED, 'Sieve SSL mode', 'ssl')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Mail domain (e.g. example.com)')
            ->addOption('oidc-audience', null, InputOption::VALUE_REQUIRED, 'Override OIDC token audience (defaults to client name)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Run live connectivity preflight (off by default to keep deploys deterministic)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print every step without changing anything')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON status object')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonMode = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');

        $report = [
            'command' => 'souvera_mail:bootstrap',
            'dry_run' => $dryRun,
            'steps' => [],
            'status' => 'ok',
        ];

        // ─── Step 1: preconditions ─────────────────────────────────────────────
        if (!$this->appManager->isInstalled('oidc') || !$this->appManager->isEnabledForUser('oidc')) {
            return $this->fail(
                $output, $jsonMode, $report,
                'H2CK/oidc app is not installed or enabled. Run: occ app:install oidc && occ app:enable oidc',
            );
        }
        $report['steps'][] = ['preflight' => 'H2CK/oidc installed + enabled'];

        $imapHost = $input->getOption('mail-imap-host');
        $domain = $input->getOption('domain');
        if (!\is_string($imapHost) || $imapHost === '') {
            return $this->fail($output, $jsonMode, $report, '--mail-imap-host is required');
        }
        if (!\is_string($domain) || $domain === '') {
            return $this->fail($output, $jsonMode, $report, '--domain is required');
        }

        $application = $this->getApplication();
        if ($application === null) {
            return $this->fail($output, $jsonMode, $report, 'Symfony console application unavailable');
        }

        // ─── Step 2: register OIDC client (idempotent via souvera_mail:oidc:register-client) ──
        if (!$application->has('souvera_mail:oidc:register-client')) {
            return $this->fail($output, $jsonMode, $report, 'Internal: souvera_mail:oidc:register-client is not registered');
        }
        try {
            $registerCmd = $application->find('souvera_mail:oidc:register-client');
            $registerArgs = [
                '--name' => (string) $input->getOption('client-name'),
                '--json' => true,
            ];
            if (\is_string($secret = $input->getOption('client-secret-out')) && $secret !== '') {
                $registerArgs['--secret-out'] = $secret;
            }
            if (\is_string($lifetime = $input->getOption('token-lifetime')) && $lifetime !== '') {
                $registerArgs['--token-lifetime'] = $lifetime;
            }
            if ($dryRun) {
                $registerArgs['--dry-run'] = true;
            }

            $bufferedOutput = new BufferedOutput();
            $rc = $registerCmd->run(new ArrayInput($registerArgs), $bufferedOutput);
            $decoded = \json_decode($bufferedOutput->fetch(), true);
            $report['steps'][] = ['register_client' => $decoded ?? ['rc' => $rc]];
            if ($rc !== Command::SUCCESS) {
                return $this->fail($output, $jsonMode, $report, 'souvera_mail:oidc:register-client failed (see register_client section)');
            }
        } catch (\Throwable $e) {
            return $this->fail($output, $jsonMode, $report, 'register-client dispatch failed: ' . $e->getMessage());
        }

        // ─── Step 3: domain profile (souvera_mail:setup) ──────────────────────────────
        if (!$application->has('souvera_mail:setup')) {
            return $this->fail($output, $jsonMode, $report, 'Internal: souvera_mail:setup is not registered');
        }
        try {
            $setupCmd = $application->find('souvera_mail:setup');
            $setupArgs = [
                '--imap-host' => $imapHost,
                '--imap-port' => (string) ($input->getOption('mail-imap-port') ?? '993'),
                '--imap-ssl'  => (string) ($input->getOption('mail-imap-ssl') ?? 'ssl'),
                '--smtp-host' => (string) ($input->getOption('mail-smtp-host') ?? $imapHost),
                '--smtp-port' => (string) ($input->getOption('mail-smtp-port') ?? '465'),
                '--smtp-ssl'  => (string) ($input->getOption('mail-smtp-ssl') ?? 'ssl'),
                '--domain'    => $domain,
                '--json'      => true,
            ];
            $sieveHost = $input->getOption('mail-sieve-host');
            if (\is_string($sieveHost) && $sieveHost !== '') {
                $setupArgs['--sieve'] = true;
                $setupArgs['--sieve-host'] = $sieveHost;
                $setupArgs['--sieve-port'] = (string) ($input->getOption('mail-sieve-port') ?? '4190');
                $setupArgs['--sieve-ssl']  = (string) ($input->getOption('mail-sieve-ssl') ?? 'ssl');
            }
            if (\is_string($aud = $input->getOption('oidc-audience')) && $aud !== '') {
                $setupArgs['--oidc-audience'] = $aud;
            }
            if (!$input->getOption('check')) {
                $setupArgs['--skip-checks'] = true;
            }
            if ($dryRun) {
                $setupArgs['--dry-run'] = true;
            }

            $bufferedOutput = new BufferedOutput();
            $rc = $setupCmd->run(new ArrayInput($setupArgs), $bufferedOutput);
            $decoded = \json_decode($bufferedOutput->fetch(), true);
            $report['steps'][] = ['setup' => $decoded ?? ['rc' => $rc]];
            if ($rc !== Command::SUCCESS) {
                return $this->fail($output, $jsonMode, $report, 'souvera_mail:setup failed (see setup section)');
            }
        } catch (\Throwable $e) {
            return $this->fail($output, $jsonMode, $report, 'souvera_mail:setup dispatch failed: ' . $e->getMessage());
        }

        // ─── Step 4: final status check ────────────────────────────────────────
        if ($application->has('souvera_mail:status')) {
            try {
                $statusCmd = $application->find('souvera_mail:status');
                $bufferedOutput = new BufferedOutput();
                $statusCmd->run(new ArrayInput(['--json' => true]), $bufferedOutput);
                $decoded = \json_decode($bufferedOutput->fetch(), true);
                $report['steps'][] = ['status' => $decoded ?? []];
            } catch (\Throwable $e) {
                $report['steps'][] = ['status_warning' => $e->getMessage()];
            }
        }

        return $this->finalize($output, $jsonMode, $report);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function fail(OutputInterface $output, bool $jsonMode, array $report, string $message): int
    {
        $report['status'] = 'error';
        $report['message'] = $message;
        if ($jsonMode) {
            $output->writeln((string) \json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $output->writeln('<error>' . $message . '</error>');
        }
        return Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function finalize(OutputInterface $output, bool $jsonMode, array $report): int
    {
        if ($jsonMode) {
            $output->writeln((string) \json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $output->writeln('<info>Souvera Mail bootstrap complete</info>');
            foreach ($report['steps'] as $step) {
                foreach ($step as $key => $value) {
                    $output->writeln("  <comment>{$key}</comment>");
                }
            }
        }
        // suppress unused-property warnings for properties we keep on the class
        // for future extension (e.g., dependency-injected oidcProvider).
        unset($this->oidcProvider, $this->appConfig);
        return Command::SUCCESS;
    }
}
