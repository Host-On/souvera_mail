<?php

namespace OCA\Smail\Settings;

use OCA\Smail\Util\EngineHelper;
use OCA\Smail\Util\NavigationTitle;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getForm()
    {
        $this->appConfig->setValueString('smail', 'autologin-oidc', '1');
        $this->appConfig->setValueString('smail', 'autologin', '1');

        $this->engineHelper->loadApp();

        $parameters = [];
        $parameters['smail-debug-log'] = $this->appConfig->getValueString('smail', 'debug_log', '0') === '1';
        $oConfig = \Smail\Engine\Api::Config();

        // Souvera Mail is OIDC-first, no legacy import

        $parameters['smail-debug'] = $oConfig->Get('debug', 'enable', false);

        // Check for nextcloud plugin update
        foreach (\Smail\Engine\Repository::getPackagesList()['List'] as $plugin) {
            if ('nextcloud' == $plugin['id'] && $plugin['canBeUpdated']) {
                \Smail\Engine\Repository::installPackage('plugin', 'nextcloud');
            }
        }

        $app_path = $oConfig->Get('webmail', 'app_path');
        if (!$app_path) {
            $webPath = $this->appManager->getAppWebPath('smail');
            $app_path = \preg_replace(
                '#(?<!:)/+#',
                '/',
                \rtrim($webPath, '/') . '/app/'
            );
            $oConfig->Set('webmail', 'app_path', $app_path);
            $oConfig->Set('webmail', 'theme', 'smail');
            $oConfig->Save();
        }
        $parameters['smail-app-path'] = $oConfig->Get('webmail', 'app_path', false);
        $parameters['smail-nc-lang'] = !$oConfig->Get('webmail', 'allow_languages_on_settings', true);
        $parameters['smail-version'] = $this->appManager->getAppVersion('smail');

        $parameters['menu_title'] = NavigationTitle::storedOverride($this->appConfig);
        $parameters['menu_title_default'] = NavigationTitle::DEFAULT;
        $parameters['attachment_size_limit'] = (int) $oConfig->Get('webmail', 'attachment_size_limit', 25);
        $parameters['show_attachment_thumbnail'] = (bool) $oConfig->Get('interface', 'show_attachment_thumbnail', true);
        $parameters['openpgp'] = (bool) $oConfig->Get('security', 'openpgp', true);
        $parameters['gnupg'] = (bool) $oConfig->Get('security', 'gnupg', true);
        $parameters['smail_version'] = $parameters['smail-version'];

        \OCP\Util::addScript('smail', 'setup-wizard');
        \OCP\Util::addStyle('smail', 'setup-wizard');
        return new TemplateResponse('smail', 'admin-local', $parameters);
    }

    public function getSection()
    {
        return 'smail';
    }

    public function getPriority()
    {
        return 50;
    }
}
