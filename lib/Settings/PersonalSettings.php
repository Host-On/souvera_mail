<?php

declare(strict_types=1);

namespace OCA\Smail\Settings;

use OCA\Smail\Util\EngineHelper;
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
        $brandName = \Smail\Engine\Api::Config()->Get('webmail', 'title', 'Souvera Mail');

        return new TemplateResponse('smail', 'personal_settings', [
            'brandName' => $brandName,
            'settingsUrl' => $this->urlGenerator->linkToRoute('smail.page.index') . '#/settings/accounts',
        ], '');
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
