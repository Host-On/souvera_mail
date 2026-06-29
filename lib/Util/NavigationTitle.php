<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Util;

use OCP\IAppConfig;

final class NavigationTitle
{
    public const APP_CONFIG_KEY = 'menu-title';

    /**
     * Default label rendered in Nextcloud's left navigation sidebar.
     *
     * Deliberately the short form `Mail` — the long brand name
     * `Souvera Mail` overflows / wraps in the NC sidebar at smaller
     * widths, which looks broken. The product brand stays
     * `Souvera Mail` everywhere else (info.xml `<name>`, settings
     * page heading, breadcrumb, dashboard widget title, About page,
     * App Store listing). Operators who want a different sidebar
     * label can set it via `occ config:app:set souvera_mail
     * menu-title --value '<label>'`.
     */
    public const DEFAULT = 'Mail';

    public static function resolve(IAppConfig $appConfig): string
    {
        $custom = \trim($appConfig->getValueString('souvera_mail', self::APP_CONFIG_KEY, ''));

        return $custom !== '' ? $custom : self::DEFAULT;
    }

    public static function storedOverride(IAppConfig $appConfig): string
    {
        return \trim($appConfig->getValueString('souvera_mail', self::APP_CONFIG_KEY, ''));
    }

    public static function validate(string $title): ?string
    {
        $title = \trim($title);
        if (\strlen($title) > 64 || \preg_match('/[\x00-\x1f]/', $title)) {
            return 'Invalid menu title';
        }

        return null;
    }
}
