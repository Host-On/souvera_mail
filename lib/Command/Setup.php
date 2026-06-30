<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\ConnectivityCheckService;
use OCA\SouveraMail\Service\DomainConfigService;
use OCA\SouveraMail\Service\OidcProviderService;
use OCA\SouveraMail\Util\EngineHelper;
use OCA\SouveraMail\Util\SetupResolvers;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Write (or update) Souvera Mail's single active mail-domain profile —
 * IMAP/SMTP/Sieve hosts, ports, TLS modes, OIDC audience hint. The
 * authoritative OIDC client lives in H2CK/oidc and is managed by
 * `souvera_mail:oidc:register-client` / `souvera_mail:bootstrap`; this command only writes
 * the mail-server side of the profile.
 *
 * Idempotent and deploy-friendly: `--json` returns a single-line machine
 * report, `--dry-run` prints actions without writing. All preflight checks
 * default off (`--skip-checks` is implicit); pass `--check` to enable.
 */
class Setup extends Command
{
    use SetupResolvers;

    private const APP_ID = 'souvera_mail';

    public function __construct(
        private IAppConfig $appConfig,
        private DomainConfigService $domainService,
        private IAppManager $appManager,
        private EngineHelper $engineHelper,
        private ConnectivityCheckService $connectivityCheckService,
        private OidcProviderService $oidcProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:setup')
            ->setDescription('Configure Souvera Mail mail-server profile (IMAP/SMTP/Sieve + OIDC audience)')
            ->addOption('imap-host', null, InputOption::VALUE_REQUIRED, 'IMAP server hostname')
            ->addOption('imap-port', null, InputOption::VALUE_REQUIRED, 'IMAP server port', '993')
            ->addOption('imap-ssl',  null, InputOption::VALUE_REQUIRED, 'IMAP SSL mode (none|ssl|starttls)', 'ssl')
            ->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP server hostname (defaults to imap-host)')
            ->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP submission port', '465')
            ->addOption('smtp-ssl',  null, InputOption::VALUE_REQUIRED, 'SMTP SSL mode (none|ssl|starttls)', 'ssl')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Mail domain (e.g. example.com)')
            ->addOption('oidc-audience', null, InputOption::VALUE_REQUIRED, 'Override OIDC audience hint (defaults to registered client identifier)')
            ->addOption('oidc-scopes',   null, InputOption::VALUE_REQUIRED, 'Optional: space-separated extra OIDC scopes')
            ->addOption('sieve',       null, InputOption::VALUE_NEGATABLE, 'Enable ManageSieve filtering (default: on — Stalwart 0.16 ships it natively on :4190 / OAUTHBEARER; pass --no-sieve to opt out)', true)
            ->addOption('sieve-host',  null, InputOption::VALUE_REQUIRED, 'Sieve server hostname (defaults to imap-host)')
            ->addOption('sieve-port',  null, InputOption::VALUE_REQUIRED, 'Sieve server port', '4190')
            ->addOption('sieve-ssl',   null, InputOption::VALUE_REQUIRED, 'Sieve SSL mode (none|ssl|starttls)', 'ssl')
            ->addOption('check',        null, InputOption::VALUE_NONE, 'Run live IMAP/SMTP/Sieve connectivity preflight before writing')
            ->addOption('skip-checks',  null, InputOption::VALUE_NONE, 'Deprecated alias — connectivity is skipped by default; --check turns it on')
            ->addOption('dry-run',      null, InputOption::VALUE_NONE, 'Print what would be written without modifying any state')
            ->addOption('json',         null, InputOption::VALUE_NONE, 'Emit a single JSON object on stdout')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonMode = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $runChecks = (bool) $input->getOption('check');

        $report = [
            'command' => 'souvera_mail:setup',
            'dry_run' => $dryRun,
            'checks' => [],
            'actions' => [],
            'status' => 'ok',
        ];

        $imapHost = $input->getOption('imap-host');
        $domain = $input->getOption('domain');
        if (!\is_string($imapHost) || $imapHost === '') {
            return $this->fail($output, $jsonMode, $report, '--imap-host is required');
        }
        if (!\is_string($domain) || $domain === '') {
            return $this->fail($output, $jsonMode, $report, '--domain is required');
        }

        $imapPort = (int) $input->getOption('imap-port');
        $imapSsl = $this->normalizeSslMode((string) $input->getOption('imap-ssl'));
        $smtpHost = (string) ($input->getOption('smtp-host') ?: $imapHost);
        $smtpPort = (int) $input->getOption('smtp-port');
        $smtpSsl = $this->normalizeSslMode((string) $input->getOption('smtp-ssl'));
        $sieveHost = (string) ($input->getOption('sieve-host') ?: $imapHost);
        $sievePort = (int) $input->getOption('sieve-port');
        $sieveSsl = $this->normalizeSslMode((string) $input->getOption('sieve-ssl'));
        $sieveEnabled = (bool) ($input->getOption('sieve') ?? true);
        $oidcAudience = (string) ($input->getOption('oidc-audience') ?? '');
        $oidcScopes = (string) ($input->getOption('oidc-scopes') ?? '');

        foreach (['imap-ssl' => $imapSsl, 'smtp-ssl' => $smtpSsl, 'sieve-ssl' => $sieveSsl] as $name => $val) {
            if (!\in_array($val, ['none', 'ssl', 'starttls'], true)) {
                return $this->fail($output, $jsonMode, $report, "Invalid --{$name}. Must be: none, ssl, starttls");
            }
        }
        if ($imapPort < 1 || $imapPort > 65535 || $smtpPort < 1 || $smtpPort > 65535
            || ($sieveEnabled && ($sievePort < 1 || $sievePort > 65535))
        ) {
            return $this->fail($output, $jsonMode, $report, 'Invalid port number (must be 1-65535)');
        }

        // ─── Preconditions: H2CK/oidc must be ready ──────────────────────────
        if (!$this->oidcProvider->isProviderAvailable()) {
            return $this->fail(
                $output, $jsonMode, $report,
                'H2CK/oidc is not installed/enabled. Run `occ app:install oidc && occ app:enable oidc`'
                . ' and then `occ souvera_mail:oidc:register-client` (or `occ souvera_mail:bootstrap`).',
            );
        }
        $clientId = $this->oidcProvider->getClientIdentifier();
        $report['checks'][] = ['oidc_client' => $clientId];

        // ─── Optional preflight checks ───────────────────────────────────────
        if ($runChecks) {
            $imapResult = $this->connectivityCheckService->checkImap($imapHost, $imapPort, $imapSsl);
            $report['checks'][] = ['imap' => $imapResult];
            if (!$imapResult['connected']) {
                return $this->fail($output, $jsonMode, $report, "IMAP preflight failed: {$imapResult['error']}");
            }
            $hasOAuth = false;
            foreach ($imapResult['capabilities'] as $cap) {
                if ($cap === 'AUTH=OAUTHBEARER' || $cap === 'AUTH=XOAUTH2') {
                    $hasOAuth = true;
                }
            }
            if (!$hasOAuth) {
                return $this->fail($output, $jsonMode, $report, 'IMAP does not advertise OAUTHBEARER/XOAUTH2');
            }

            $smtpResult = $this->connectivityCheckService->checkSmtp($smtpHost, $smtpPort, $smtpSsl);
            $report['checks'][] = ['smtp' => $smtpResult];
            if (!$smtpResult['connected']) {
                return $this->fail($output, $jsonMode, $report, "SMTP preflight failed: {$smtpResult['error']}");
            }

            if ($sieveEnabled) {
                $sieveResult = $this->connectivityCheckService->checkSieve($sieveHost, $sievePort, $sieveSsl);
                $report['checks'][] = ['sieve' => $sieveResult];
                if (!$sieveResult['connected']) {
                    return $this->fail($output, $jsonMode, $report, "Sieve preflight failed: {$sieveResult['error']}");
                }
            }
        } else {
            $report['checks'][] = ['preflight' => 'skipped (pass --check to enable)'];
        }

        // ─── Write configuration (skipped on --dry-run) ──────────────────────
        if ($dryRun) {
            $report['actions'][] = ['would_write_domain_config' => $domain];
            $report['actions'][] = ['would_set_appconfig' => [
                'autologin' => '1',
                'autologin-oidc' => '1',
                'oidc-exchange-audience' => $oidcAudience !== '' ? $oidcAudience : $clientId,
                'oidc-exchange-scopes' => $oidcScopes,
            ]];
            return $this->finalize($output, $jsonMode, $report);
        }

        $domainConfig = $this->domainService->buildDomainConfig(
            $imapHost, $imapPort, $imapSsl,
            $smtpHost, $smtpPort, $smtpSsl,
            $sieveHost, $sievePort, $sieveSsl,
            $sieveEnabled,
        );
        $this->domainService->writeDomainConfig($domain, $domainConfig);
        $report['actions'][] = ['wrote_domain_config' => $domain];

        // Consolidate to a single active domain
        foreach ($this->domainService->listDomains() as $existing) {
            if ($existing !== $domain) {
                try {
                    $this->domainService->deleteDomainConfig($existing);
                    $report['actions'][] = ['removed_legacy_domain' => $existing];
                } catch (\Throwable $e) {
                    $report['actions'][] = ['warning' => "could not remove '{$existing}': " . $e->getMessage()];
                }
            }
        }

        // App config
        $this->appConfig->setValueString(self::APP_ID, 'autologin', '1');
        $this->appConfig->setValueString(self::APP_ID, 'autologin-oidc', '1');
        $this->appConfig->setValueString(self::APP_ID, 'oidc-exchange-audience', $oidcAudience !== '' ? $oidcAudience : $clientId);
        $this->appConfig->setValueString(self::APP_ID, 'oidc-exchange-scopes', \trim($oidcScopes));
        $report['actions'][] = ['set_appconfig' => 'autologin, autologin-oidc, oidc-exchange-audience, oidc-exchange-scopes'];

        // Engine config (app_path + default_domain)
        try {
            $this->engineHelper->loadApp();
            $oConfig = \Smail\Engine\Api::Config();
            $webPath = $this->appManager->getAppWebPath(self::APP_ID);
            $appPath = \preg_replace('#(?<!:)/+#', '/', \rtrim($webPath, '/') . '/app/');
            $oConfig->Set('webmail', 'app_path', $appPath);
            $oConfig->Set('login', 'default_domain', $domain);
            $oConfig->Save();
            $report['actions'][] = ['set_engine_config' => ['app_path' => $appPath, 'default_domain' => $domain]];
        } catch (\Throwable $e) {
            $report['actions'][] = ['warning' => 'engine config skipped: ' . $e->getMessage()];
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
            foreach ($report['actions'] as $action) {
                foreach ($action as $key => $value) {
                    $valueStr = \is_array($value) ? \json_encode($value) : (string) $value;
                    $output->writeln("  <comment>{$key}</comment> {$valueStr}");
                }
            }
            $output->writeln('<info>Souvera Mail setup complete</info>');
        }
        return Command::SUCCESS;
    }
}
