<?php

/*
 * Souvera Mail namespace-bridge — eager-loaded by composer.
 *
 * This file is registered under `autoload.files` in composer.json, which means
 * the moment any caller `require`s `vendor/autoload.php` (Nextcloud's
 * OC_App::registerAutoloading() does exactly that for every enabled app at
 * bootstrap), this file runs unconditionally and installs the spl_autoload
 * hook below. By the time NC's AppFramework asks the DI container for
 * `OCA\Souvera_mail\AppInfo\Application` or `OCA\Souvera_mail\Controller\…`,
 * the hook already aliases the lookup onto the real `OCA\SouveraMail\*` class.
 *
 * See lib-bridge/Souvera_mail/AppInfo/Application.php for the why; this file
 * exists purely so the spl_autoload_register call is reachable without
 * requiring a class to be looked up first (the chicken-and-egg problem of
 * putting both the autoloader registration AND the bridge class in the same
 * file under a `classmap` entry).
 */

declare(strict_types=1);

if (\defined('SOUVERA_MAIL_NAMESPACE_BRIDGE_INSTALLED')) {
    return;
}
\define('SOUVERA_MAIL_NAMESPACE_BRIDGE_INSTALLED', true);

\spl_autoload_register(function (string $class): void {
    static $prefix = 'OCA\\Souvera_mail\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $real = 'OCA\\SouveraMail\\' . \substr($class, \strlen($prefix));
    if (\class_exists($real, true) || \interface_exists($real, true) || \trait_exists($real, true)) {
        \class_alias($real, $class, true);
    }
}, true, true);
