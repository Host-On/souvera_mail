<?php

declare(strict_types=1);

namespace OCA\Smail\Migration;

use OCA\Smail\AppInfo\Application;
use OCA\Smail\Util\EngineHelper;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Run on app enable and after upgrade.
 */
class InstallStep implements IRepairStep
{
    public function __construct(
        private IAppManager $appManager,
        private IAppConfig $appConfig,
        private IConfig $config,
        private IUserConfig $userConfig,
        private LoggerInterface $logger,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getName()
    {
        return 'Setup Souvera Mail';
    }

    public function run(IOutput $output): void
    {
        $output->info('clearstatcache');
        \clearstatcache();
        \clearstatcache(true);
        $output->info('opcache_reset');
        \opcache_reset();

        $output->info('Load App');
        $this->engineHelper->loadApp();

        $output->info('Fix permissions');
        \Smail\Engine\Upgrade::fixPermissions();

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';

        if (!\file_exists($app_dir . '/.htaccess') && \file_exists($app_dir . '/_htaccess')) {
            \rename($app_dir . '/_htaccess', $app_dir . '/.htaccess');
        }
        $versionRoot = APP_VERSION_ROOT_PATH;
        if (!\file_exists($versionRoot . 'app/.htaccess') && \file_exists($versionRoot . 'app/_htaccess')) {
            \rename($versionRoot . 'app/_htaccess', $versionRoot . 'app/.htaccess');
        }

        $oConfig = \Smail\Engine\Api::Config();

        // Keep post-update changes narrow: write Souvera Mail defaults only when the
        // engine still holds its stock value, so admin customizations survive upgrades.
        $this->applyReleaseDefaults($oConfig, $output);

        if (!$oConfig->Get('webmail', 'app_path')) {
            $output->info('Set config [webmail]app_path');
            $appWebPath = $this->appManager->getAppWebPath('smail');
            $appPath = \preg_replace('#(?<!:)/+#', '/', \rtrim($appWebPath, '/') . '/app/');
            $oConfig->Set('webmail', 'app_path', $appPath);
        }

        // Clean-sync bundled nextcloud plugin to engine data directory
        $bundledPlugin = $app_dir . '/smail/v/current/app/plugins/nextcloud';
        $installedPlugin = APP_PLUGINS_PATH . 'nextcloud';
        if (\is_dir($bundledPlugin)) {
            if (!(bool) $oConfig->Get('plugins', 'enable', false)) {
                $oConfig->Set('plugins', 'enable', true);
                $output->info('Enabled plugins support for bundled nextcloud integration');
            }

            // Ensure plugin is registered
            $aList = \array_values(\array_filter(
                \array_map('trim', \explode(',', (string) $oConfig->Get('plugins', 'enabled_list', '')))
            ));
            if (!\in_array('nextcloud', $aList)) {
                $aList[] = 'nextcloud';
                $oConfig->Set('plugins', 'enabled_list', \implode(',', \array_unique($aList)));
                $output->info('Enabled bundled nextcloud plugin');
            }

            // Delete old plugin dir to prevent stale files
            if (\is_dir($installedPlugin)) {
                $output->info('Clean installed plugin dir');
                $this->recursiveDelete($installedPlugin);
            }

            // Copy fresh from bundled version
            $output->info('Sync bundled nextcloud plugin');
            \mkdir($installedPlugin, 0755, true);
            foreach (
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($bundledPlugin, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                ) as $item
            ) {
                $relPath = \substr($item->getPathname(), \strlen($bundledPlugin));
                $dest = $installedPlugin . $relPath;
                if ($item->isDir()) {
                    \mkdir($dest, 0755, true);
                } else {
                    \copy($item->getPathname(), $dest);
                }
            }
        }

        // Remove legacy admin password file if present
        $passfile = APP_PRIVATE_DATA . 'admin_password.txt';
        if (\is_file($passfile)) {
            \unlink($passfile);
        }

        $oConfig->Save()
            ? $output->info('Config saved')
            : $output->info('Config failed');

        // Check for custom initial config file
        try {
            $customConfigFile = $this->appConfig->getValueString(Application::APP_ID, 'custom_config_file');
            if ($customConfigFile) {
                $output->info("Load custom config: {$customConfigFile}");
                // Security: restrict to appdata_smail/ directory
                $resolved = \realpath($customConfigFile);
                $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
                $allowedDir = \realpath($dataDir . '/appdata_smail');
                if ($resolved && $allowedDir && \str_starts_with($resolved, $allowedDir . '/')) {
                    require $resolved;
                } else {
                    throw new \Exception("custom config must be inside appdata_smail/");
                }
            }
        } catch (\Throwable $e) {
            $output->warning("custom config error: " . $e->getMessage());
            $this->logger->error("custom config error: " . $e->getMessage());
        }

        // Clear legacy Engine\Crypt passwords once — ICrypto format is incompatible (v0.6.1)
        try {
            $migrationKey = 'migration-passphrase-cleared-v061';
            if ($this->appConfig->getValueString(Application::APP_ID, $migrationKey, '0') !== '1') {
                $this->userConfig->deleteKey('smail', 'passphrase');
                $this->appConfig->setValueString(Application::APP_ID, $migrationKey, '1');
                $output->info('Cleared legacy password storage (re-encrypted on next login)');
            }
        } catch (\Throwable $e) {
            // Non-fatal — users will re-authenticate
        }
    }

    /**
     * Apply Souvera Mail defaults only when the engine still holds its stock value.
     * Whitelist `['', 'Smail']` covers fresh installs (no value set yet) and the
     * engine's own default ("Smail" — i.e. the upstream library identity after our
     * rename); admin customizations to anything else are preserved.
     */
    private function applyReleaseDefaults(object $config, IOutput $output): void
    {
        /** @var \Smail\Engine\Config\Application $config */
        $this->setIfCurrentIn(
            $config,
            'webmail',
            'title',
            'Souvera Mail',
            ['', 'Smail'],
            $output,
            'Set webmail title to Souvera Mail',
        );
        $this->setIfCurrentIn(
            $config,
            'webmail',
            'loading_description',
            'Souvera Mail',
            ['', 'Smail'],
            $output,
            'Set loading description to Souvera Mail',
        );
        $this->setIfCurrentIn(
            $config,
            'webmail',
            'theme',
            'smail',
            ['', 'Default', 'NextcloudV25+'],
            $output,
            'Set theme to smail',
        );
        $this->setIfCurrentIn(
            $config,
            'webmail',
            'allow_additional_identities',
            true,
            [false],
            $output,
            'Enabled additional identities for Souvera Mail defaults',
        );
        $this->setIfCurrentIn(
            $config,
            'security',
            'custom_server_signature',
            'Souvera Mail',
            ['', 'Smail'],
            $output,
            'Set server signature to Souvera Mail',
        );
        $this->setIfCurrentIn(
            $config,
            'imap',
            'show_login_alert',
            false,
            [true],
            $output,
            'Disabled IMAP login alert for release defaults',
        );
        $this->setIfCurrentIn(
            $config,
            'defaults',
            'autologout',
            15,
            [30],
            $output,
            'Set release autologout default to 15 minutes',
        );
        $this->setIfCurrentIn(
            $config,
            'defaults',
            'contacts_autosave',
            false,
            [true],
            $output,
            'Disabled contacts autosave for release defaults',
        );
    }

    /**
     * @param list<string|bool|int> $legacyValues
     * @param string|bool|int $newValue
     */
    /**
     * @param object $config Engine config with Get()/Set() methods
     * @param list<string|bool|int> $legacyValues
     * @param string|bool|int $newValue
     */
    private function setIfCurrentIn(
        object $config,
        string $section,
        string $key,
        string|bool|int $newValue,
        array $legacyValues,
        IOutput $output,
        string $message,
    ): void {
        /** @var \Smail\Engine\Config\Application $config */
        $currentValue = $config->Get($section, $key);
        if (\in_array($currentValue, $legacyValues, true)) {
            $config->Set($section, $key, $newValue);
            $output->info($message);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($dir);
    }
}
