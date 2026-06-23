<?php

declare(strict_types=1);

namespace OCA\Smail\Settings;

use OCA\Smail\Util\EngineHelper;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getID(): string
    {
        return 'smail';
    }

    public function getName(): string
    {
        try {
            $this->engineHelper->loadApp();
            return \Smail\Engine\Api::Config()->Get('webmail', 'title', 'Souvera Mail') . ' ' . $this->l->t('Settings');
        } catch (\Throwable) {
            return 'Souvera Mail ' . $this->l->t('Settings');
        }
    }

    public function getPriority(): int
    {
        return 75;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('smail', 'logo-64x64.png');
    }
}
