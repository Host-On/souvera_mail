<?php

namespace OCA\SouveraMail\Settings;

use OCA\SouveraMail\Util\EngineHelper;
use OCA\SouveraMail\Util\NavigationTitle;
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
        $this->appConfig->setValueString('souvera_mail', 'autologin-oidc', '1');
        $this->appConfig->setValueString('souvera_mail', 'autologin', '1');

        $this->engineHelper->loadApp();

        $parameters = [];
        $parameters['x2mail-debug-log'] = $this->appConfig->getValueString('souvera_mail', 'debug_log', '0') === '1';
        $oConfig = \X2Mail\Engine\Api::Config();

        // Souvera Mail is OIDC-first, no legacy import

        $parameters['x2mail-debug'] = $oConfig->Get('debug', 'enable', false);

        // Check for nextcloud plugin update
        foreach (\X2Mail\Engine\Repository::getPackagesList()['List'] as $plugin) {
            if ('nextcloud' == $plugin['id'] && $plugin['canBeUpdated']) {
                \X2Mail\Engine\Repository::installPackage('plugin', 'nextcloud');
            }
        }

        $app_path = $oConfig->Get('webmail', 'app_path');
        if (!$app_path) {
            $webPath = $this->appManager->getAppWebPath('souvera_mail');
            $app_path = \preg_replace(
                '#(?<!:)/+#',
                '/',
                \rtrim($webPath, '/') . '/app/'
            );
            $oConfig->Set('webmail', 'app_path', $app_path);
            $oConfig->Set('webmail', 'theme', 'souvera_mail');
            $oConfig->Save();
        }
        $parameters['x2mail-app-path'] = $oConfig->Get('webmail', 'app_path', false);
        $parameters['x2mail-nc-lang'] = !$oConfig->Get('webmail', 'allow_languages_on_settings', true);
        $parameters['x2mail-version'] = $this->appManager->getAppVersion('souvera_mail');

        $parameters['menu_title'] = NavigationTitle::storedOverride($this->appConfig);
        $parameters['menu_title_default'] = NavigationTitle::DEFAULT;
        $parameters['attachment_size_limit'] = (int) $oConfig->Get('webmail', 'attachment_size_limit', 25);
        $parameters['show_attachment_thumbnail'] = (bool) $oConfig->Get('interface', 'show_attachment_thumbnail', true);
        $parameters['openpgp'] = (bool) $oConfig->Get('security', 'openpgp', true);
        $parameters['gnupg'] = (bool) $oConfig->Get('security', 'gnupg', true);
        $parameters['x2mail_version'] = $parameters['x2mail-version'];

        \OCP\Util::addScript('souvera_mail', 'setup-wizard');
        \OCP\Util::addStyle('souvera_mail', 'setup-wizard');
        return new TemplateResponse('souvera_mail', 'admin-local', $parameters);
    }

    public function getSection()
    {
        return 'souvera_mail';
    }

    public function getPriority()
    {
        return 50;
    }
}
