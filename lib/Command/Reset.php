<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\DomainConfigService;
use OCA\SouveraMail\Service\OidcProviderService;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tear down a Souvera Mail install so a fresh `souvera_mail:bootstrap` can rebuild
 * from scratch. Designed for declarative deploys: same playbook on a clean
 * Nextcloud and on a previously-configured one converges to the same result.
 *
 * What gets removed:
 *   - All app-config entries under the `souvera_mail` namespace
 *   - The single active domain config in the engine
 *   - The souvera_mail OIDC client in H2CK/oidc (only with --purge-oidc-client)
 *   - Cached OIDC access tokens
 *   - Engine config keys (webmail/title, theme, etc.) ARE NOT touched here —
 *     re-running `souvera_mail:bootstrap` will rewrite them via InstallStep.
 *
 * The engine data directory `appdata_souvera_mail/` (mail caches, attachment cache,
 * S/MIME keys) stays put unless `--purge-engine-data` is supplied.
 */
class Reset extends Command
{
    private const APP_ID = 'souvera_mail';

    public function __construct(
        private IAppConfig $appConfig,
        private DomainConfigService $domainService,
        private OidcProviderService $oidcProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:reset')
            ->setDescription('Remove all Souvera Mail configuration so a fresh souvera_mail:bootstrap can start clean')
            ->addOption(
                'purge-oidc-client',
                null,
                InputOption::VALUE_NONE,
                'Also remove the souvera_mail client from H2CK/oidc (otherwise the client remains registered)',
            )
            ->addOption(
                'purge-engine-data',
                null,
                InputOption::VALUE_NONE,
                'Delete the engine data directory (appdata_souvera_mail/) — all mail caches, attachments, S/MIME state gone',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be removed without touching anything')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single machine-readable JSON object to stdout')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonMode = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $purgeOidc = (bool) $input->getOption('purge-oidc-client');
        $purgeEngine = (bool) $input->getOption('purge-engine-data');

        $report = [
            'command' => 'souvera_mail:reset',
            'dry_run' => $dryRun,
            'actions' => [],
            'status' => 'ok',
        ];

        // 1. Domains
        $domains = $this->domainService->listDomains();
        foreach ($domains as $domain) {
            if ($dryRun) {
                $report['actions'][] = ['would_remove_domain' => $domain];
            } else {
                try {
                    $this->domainService->deleteDomainConfig($domain);
                    $report['actions'][] = ['removed_domain' => $domain];
                } catch (\Throwable $e) {
                    $report['actions'][] = ['warning' => "could not remove domain '{$domain}': " . $e->getMessage()];
                }
            }
        }

        // 2. App config — wipe every souvera_mail/** key
        try {
            $keys = $this->appConfig->getKeys(self::APP_ID);
            foreach ($keys as $key) {
                if ($dryRun) {
                    $report['actions'][] = ['would_clear_appconfig' => self::APP_ID . '/' . $key];
                } else {
                    $this->appConfig->deleteKey(self::APP_ID, $key);
                    $report['actions'][] = ['cleared_appconfig' => self::APP_ID . '/' . $key];
                }
            }
        } catch (\Throwable $e) {
            $report['actions'][] = ['warning' => 'could not enumerate appconfig keys: ' . $e->getMessage()];
        }

        // 3. Token cache
        if ($dryRun) {
            $report['actions'][] = ['would_invalidate' => 'oidc-token-cache'];
        } else {
            $this->oidcProvider->invalidate();
            $report['actions'][] = ['invalidated' => 'oidc-token-cache'];
        }

        // 4. Optional H2CK/oidc client removal
        if ($purgeOidc) {
            $clientName = $this->oidcProvider->getClientIdentifier();
            $application = $this->getApplication();
            if ($application !== null && $application->has('oidc:remove')) {
                if ($dryRun) {
                    $report['actions'][] = ['would_remove_oidc_client' => $clientName];
                } else {
                    try {
                        $removeCmd = $application->find('oidc:remove');
                        $removeCmd->run(new ArrayInput(['name' => $clientName]), new BufferedOutput());
                        $report['actions'][] = ['removed_oidc_client' => $clientName];
                    } catch (\Throwable $e) {
                        $report['actions'][] = ['warning' => "oidc:remove failed: " . $e->getMessage()];
                    }
                }
            } else {
                $report['actions'][] = ['warning' => 'H2CK/oidc not available — cannot remove OIDC client'];
            }
        }

        // 5. Optional engine data wipe
        if ($purgeEngine) {
            $dataDir = $this->domainService->getDataPath();
            if ($dataDir !== '' && \is_dir($dataDir)) {
                if ($dryRun) {
                    $report['actions'][] = ['would_remove_engine_data' => $dataDir];
                } else {
                    try {
                        $this->recursiveDelete($dataDir);
                        $report['actions'][] = ['removed_engine_data' => $dataDir];
                    } catch (\Throwable $e) {
                        $report['actions'][] = ['warning' => 'could not remove engine data: ' . $e->getMessage()];
                    }
                }
            }
        }

        if ($jsonMode) {
            $output->writeln((string) \json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            foreach ($report['actions'] as $action) {
                foreach ($action as $key => $value) {
                    $valueStr = \is_array($value) ? \json_encode($value) : (string) $value;
                    $output->writeln("  <comment>{$key}</comment> {$valueStr}");
                }
            }
            $output->writeln('<info>Souvera Mail reset complete</info>');
        }

        return Command::SUCCESS;
    }

    private function recursiveDelete(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($dir);
    }
}
