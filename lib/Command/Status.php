<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\DomainConfigService;
use OCA\SouveraMail\Service\LogService;
use OCA\SouveraMail\Service\OidcProviderService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only diagnostic dump. Designed for deploy pipelines: `--json` returns a
 * complete state snapshot that downstream automation can grep, jq, or assert
 * against. Exits 0 when the install is healthy, 1 when a blocker is found.
 */
class Status extends Command
{
    private const APP_ID = 'souvera_mail';

    public function __construct(
        private IAppConfig $appConfig,
        private DomainConfigService $domainService,
        private IAppManager $appManager,
        private LogService $logService,
        private OidcProviderService $oidcProvider,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:status')
            ->setDescription('Inspect Souvera Mail configuration and OIDC provider health')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single machine-readable JSON object')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonMode = (bool) $input->getOption('json');
        $issues = [];
        $report = [
            'command' => 'souvera_mail:status',
            'app' => [
                'version' => $this->appManager->getAppVersion(self::APP_ID),
                'enabled' => $this->appManager->isInstalled(self::APP_ID),
            ],
            'oidc_provider' => $this->oidcProviderReport($issues),
            'domain' => $this->domainReport($issues),
            'engine' => ['present' => false, 'note' => 'legacy SnappyMail engine removed (v2 client)'],
            'debug_log' => [
                'enabled' => $this->logService->isEnabled(),
                'file' => $this->domainService->getDataPath() . '/souvera_mail.log',
                'toggle_cmd' => 'occ config:app:set souvera_mail debug_log --value=1|0',
            ],
            'issues' => $issues,
            'status' => $issues === [] ? 'ok' : 'issues',
        ];

        if ($jsonMode) {
            $output->writeln((string) \json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->renderHuman($output, $report);
        }
        return $issues === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $issues
     * @return array<string, mixed>
     */
    private function oidcProviderReport(array &$issues): array
    {
        $installed = $this->appManager->isInstalled(OidcProviderService::OIDC_APP_ID);
        $enabled = $this->appManager->isEnabledForUser(OidcProviderService::OIDC_APP_ID);
        $available = $this->oidcProvider->isProviderAvailable();
        $clientName = $this->oidcProvider->getClientIdentifier();
        $clientRegistered = $this->appConfig->getValueString(self::APP_ID, OidcProviderService::SOUVERA_MAIL_CLIENT_KEY, '') !== '';
        $jwksUrl = $this->urlGenerator->getAbsoluteURL('/index.php/apps/oidc/jwks');
        $discoveryUrl = $this->urlGenerator->getAbsoluteURL('/index.php/apps/oidc/openid-configuration');

        if (!$installed) {
            $issues[] = 'H2CK/oidc app not installed (`occ app:install oidc`)';
        } elseif (!$enabled) {
            $issues[] = 'H2CK/oidc app installed but not enabled (`occ app:enable oidc`)';
        } elseif (!$available) {
            $issues[] = 'H2CK/oidc event class not loadable — possible version mismatch (need 1.17+)';
        } elseif (!$clientRegistered) {
            $issues[] = 'No OIDC client registered for souvera_mail (`occ souvera_mail:oidc:register-client`)';
        }

        $defaultTokenType = $this->appConfig->getValueString('oidc', 'default_token_type', 'opaque');
        if ($defaultTokenType !== 'jwt') {
            $issues[] = "H2CK/oidc default_token_type is '{$defaultTokenType}' (expected 'jwt' — run `occ config:app:set oidc default_token_type --value jwt`)";
        }

        return [
            'h2ck_oidc_installed' => $installed,
            'h2ck_oidc_enabled' => $enabled,
            'event_class_loadable' => $available,
            'client_name' => $clientName,
            'client_registered' => $clientRegistered,
            'default_token_type' => $defaultTokenType,
            'discovery_url' => $discoveryUrl,
            'jwks_url' => $jwksUrl,
        ];
    }

    /**
     * @param list<string> $issues
     * @return array<string, mixed>
     */
    private function domainReport(array &$issues): array
    {
        $domains = $this->domainService->listDomains();
        if ($domains === []) {
            $issues[] = 'No mail domain configured (`occ souvera_mail:setup --imap-host … --domain …`)';
            return ['configured' => []];
        }
        $entries = [];
        foreach ($domains as $domain) {
            $cfg = $this->domainService->readDomainConfig($domain);
            if (!$cfg) {
                $entries[$domain] = ['error' => 'unreadable'];
                continue;
            }
            $imapType = $cfg['IMAP']['type'] ?? 0;
            $smtpType = $cfg['SMTP']['type'] ?? 0;
            $sieveType = $cfg['Sieve']['type'] ?? 0;
            $entries[$domain] = [
                'imap' => [
                    'host' => $cfg['IMAP']['host'] ?? '',
                    'port' => $cfg['IMAP']['port'] ?? 0,
                    'ssl' => \is_int($imapType) ? DomainConfigService::sslToString($imapType) : 'custom',
                ],
                'smtp' => [
                    'host' => $cfg['SMTP']['host'] ?? '',
                    'port' => $cfg['SMTP']['port'] ?? 0,
                    'ssl' => \is_int($smtpType) ? DomainConfigService::sslToString($smtpType) : 'custom',
                ],
                // Surface the full Sieve config so operators can correlate
                // engine-side "Error 352 / CantGetFilters" with the actual
                // ManageSieve host/port/TLS triple the engine is dialling.
                // Stalwart's ManageSieve listener defaults to port 4190
                // STARTTLS — make sure the SSL mode here matches.
                'sieve' => [
                    'enabled' => (bool) ($cfg['Sieve']['enabled'] ?? false),
                    'host' => $cfg['Sieve']['host'] ?? '',
                    'port' => $cfg['Sieve']['port'] ?? 0,
                    'ssl' => \is_int($sieveType) ? DomainConfigService::sslToString($sieveType) : 'custom',
                    'sasl' => $cfg['Sieve']['sasl'] ?? [],
                ],
                'sieve_enabled' => (bool) ($cfg['Sieve']['enabled'] ?? false),
            ];
        }
        return [
            'configured' => $entries,
            'single_active' => \count($entries) === 1,
            'oidc_audience' => $this->appConfig->getValueString(self::APP_ID, 'oidc-exchange-audience', ''),
            'oidc_scopes' => $this->appConfig->getValueString(self::APP_ID, 'oidc-exchange-scopes', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function renderHuman(OutputInterface $output, array $report): void
    {
        $output->writeln('<info>Souvera Mail Status</info>');
        $output->writeln('');
        $output->writeln('<comment>App:</comment>');
        $output->writeln('  version: ' . ($report['app']['version'] ?: '?'));
        $output->writeln('');
        $output->writeln('<comment>OIDC Provider (H2CK/oidc):</comment>');
        foreach ($report['oidc_provider'] as $k => $v) {
            $output->writeln('  ' . $k . ': ' . (\is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v));
        }
        $output->writeln('');
        $output->writeln('<comment>Domain Profile:</comment>');
        if (($report['domain']['configured'] ?? []) === []) {
            $output->writeln('  (none — run occ souvera_mail:setup)');
        } else {
            foreach ($report['domain']['configured'] as $name => $cfg) {
                $output->writeln('  ' . $name);
                if (isset($cfg['imap'])) {
                    $output->writeln("    IMAP  {$cfg['imap']['host']}:{$cfg['imap']['port']} ({$cfg['imap']['ssl']})");
                    $output->writeln("    SMTP  {$cfg['smtp']['host']}:{$cfg['smtp']['port']} ({$cfg['smtp']['ssl']})");
                    $output->writeln('    Sieve ' . ($cfg['sieve_enabled'] ? 'enabled' : 'disabled'));
                }
            }
            $output->writeln('  audience: ' . ($report['domain']['oidc_audience'] ?? ''));
        }
        $output->writeln('');
        $output->writeln('<comment>Engine:</comment>');
        foreach ($report['engine'] as $k => $v) {
            $output->writeln('  ' . $k . ': ' . (\is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v));
        }
        $output->writeln('');
        if ($report['issues'] !== []) {
            $output->writeln('<comment>Issues:</comment>');
            foreach ($report['issues'] as $issue) {
                $output->writeln('  <error>✗ ' . $issue . '</error>');
            }
        } else {
            $output->writeln('<info>✓ all checks passed</info>');
        }
    }
}
