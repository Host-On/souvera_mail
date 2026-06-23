<?php

declare(strict_types=1);

namespace OCA\Smail\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
    public function __construct(
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return 'smail';
    }

    public function getName(): string
    {
        return 'Souvera Mail';
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
