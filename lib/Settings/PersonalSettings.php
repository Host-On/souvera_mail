<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Settings;

use OCA\SouveraMail\Util\EngineHelper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getForm()
    {
        $this->engineHelper->loadApp();
        $brandName = \X2Mail\Engine\Api::Config()->Get('webmail', 'title', 'Souvera Mail');

        return new TemplateResponse('souvera_mail', 'personal_settings', [
            'brandName' => $brandName,
            'settingsUrl' => $this->urlGenerator->linkToRoute('souvera_mail.page.index') . '#/settings/accounts',
        ], '');
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
