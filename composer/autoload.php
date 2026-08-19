<?php

/*
 * Souvera Mail — composer-style autoload bootstrap (vendor/-less).
 *
 * Why this file exists
 * --------------------
 * Nextcloud's `OC_App::registerAutoloading()`
 * (lib/private/legacy/OC_App.php, around line 116) does:
 *
 *     if (file_exists($path . '/composer/autoload.php')) {
 *         require_once $path . '/composer/autoload.php';
 *     } else {
 *         \OC::$composerAutoloader->addPsr4($appNamespace . '\\', $path . '/lib/', true);
 *     }
 *
 * The operator deploys this app by rsync-ing the git tree without ever
 * running `composer install`, so `vendor/autoload.php` is never present
 * on the target. Worse, NC34's `IAppManager::getAppNamespace()` falls back
 * to `ucfirst($appId)` when its memcache-cached info.xml is stale,
 * yielding `OCA\Souvera_mail\` (lowercase-`m`-underscore) and triggering:
 *
 *     "Could not resolve OCA\Souvera_mail\Controller\PageController!
 *      Class \"OCA\Souvera_mail\Controller\PageController\" does not exist"
 *
 * This file lives at `<app-root>/composer/autoload.php` so NC ALWAYS
 * picks it up at app-boot — completely independent of `composer install`
 * being run on the target.
 *
 * What this file does
 * -------------------
 * 1. Calls `\OC::$composerAutoloader->addPsr4()` for BOTH the canonical
 *    namespace `OCA\SouveraMail\` AND the broken-fallback variant
 *    `OCA\Souvera_mail\`, both pointing at `<app-root>/lib/`. Because
 *    NC's Composer ClassLoader is a real PSR-4 loader, the OCA\Souvera_mail
 *    entry alone would `include` the right file — but the file declares
 *    the canonical namespace, so PHP would still throw
 *    `Class not declared in file`.
 * 2. Installs a PHP-level `spl_autoload_register` hook for the
 *    underscore variant that, on lookup, alias-casts via `class_alias()`
 *    to the canonical name AFTER the PSR-4 loader has loaded the file.
 *    This is the ONLY way to make `OCA\Souvera_mail\Controller\PageController`
 *    resolve to `OCA\SouveraMail\Controller\PageController` without forking
 *    every controller file.
 * 3. Installs an alias hook for the underscore variant (see below).
 *
 * Re-entrancy guard
 * -----------------
 * NC may call `registerAutoloading` multiple times during the same request
 * (force-mode, or when scanning different `apps_paths` roots). The
 * `SOUVERA_MAIL_BOOTSTRAP_LOADED` guard makes that idempotent.
 */

declare(strict_types=1);

if (\defined('SOUVERA_MAIL_BOOTSTRAP_LOADED')) {
    return;
}
\define('SOUVERA_MAIL_BOOTSTRAP_LOADED', true);

(static function (): void {
    // composer/autoload.php → app-root → lib/
    $appRoot = \dirname(__DIR__);
    $libDir = $appRoot . '/lib/';

    // 1. Register PSR-4 on NC's global Composer ClassLoader for BOTH
    //    namespace variants. Both point at the SAME lib/ root.
    if (isset(\OC::$composerAutoloader) && \is_object(\OC::$composerAutoloader)) {
        try {
            \OC::$composerAutoloader->addPsr4('OCA\\SouveraMail\\', $libDir, true);
            \OC::$composerAutoloader->addPsr4('OCA\\Souvera_mail\\', $libDir, true);
        } catch (\Throwable $e) {
            // NC's ClassLoader API may differ across versions; we never
            // want to take the app down because of an upstream signature
            // change. The spl_autoload_register hooks below cover us.
        }
    }

    // 2. PSR-4 fallback hook for OCA\SouveraMail\* — independent of NC's
    //    Composer loader, in case `OC::$composerAutoloader` wasn't ready
    //    at the moment NC required this file. Registered as prepend=false
    //    so it sits behind any real classmap fast path.
    \spl_autoload_register(static function (string $class) use ($libDir): void {
        static $prefix = 'OCA\\SouveraMail\\';
        if (!\str_starts_with($class, $prefix)) {
            return;
        }
        $relative = \substr($class, \strlen($prefix));
        $file = $libDir . \strtr($relative, '\\', \DIRECTORY_SEPARATOR) . '.php';
        if (\is_file($file)) {
            include_once $file;
        }
    }, true, false);

    // 3. Alias hook for OCA\Souvera_mail\* → OCA\SouveraMail\*. PHP's
    //    `class_exists()` first runs all autoloaders; this one fires
    //    LAST (prepend=false) and creates a runtime alias so NC's DI
    //    container can resolve underscore-namespace classes against the
    //    canonical implementation. The bridge dir is checked first so
    //    we honor concrete bridge classes (e.g. AppInfo\Application)
    //    they ship.
    \spl_autoload_register(static function (string $class): void {
        static $prefix = 'OCA\\Souvera_mail\\';
        if (!\str_starts_with($class, $prefix)) {
            return;
        }
        $relative = \substr($class, \strlen($prefix));
        $real = 'OCA\\SouveraMail\\' . $relative;
        if (\class_exists($real, true) || \interface_exists($real, true) || \trait_exists($real, true)) {
            \class_alias($real, $class, true);
        }
    }, true, false);
})();
