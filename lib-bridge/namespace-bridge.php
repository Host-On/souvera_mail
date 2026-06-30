<?php

/*
 * Souvera Mail namespace-bridge — eager-loaded by composer.
 *
 * Two autoload hooks live in this file. Both are critical to surviving the
 * operator-reported namespace failures in Nextcloud 34.
 *
 * ------------------------------------------------------------------
 * Hook 1 — Stale-composer PSR-4 fallback for `OCA\SouveraMail\*`
 * ------------------------------------------------------------------
 * Operator-reported (2026-07-01):
 *   "Could not resolve `OCA\SouveraMail\Service\DomainConfigService`"
 *   "Class `OCA\SouveraMail\Util\NavigationTitle` not found"
 *
 * Root cause: the operator upgrades the app by `rsync`-replacing the
 * directory tree, but their `vendor/composer/autoload_classmap.php` is a
 * snapshot from a previous release (newer files added in this release are
 * missing from the classmap). Composer's `ClassLoader::findFile()` returns
 * `false` for files it does not know about, even when the file is sitting
 * right there at `lib/Service/DomainConfigService.php`. PHP then throws
 * `Class not found` and NC's DI container crashes mid-request.
 *
 * We cannot force the operator to run `composer dump-autoload -o` after
 * every deploy (operators ship the tarball as-is to their NC `custom_apps/`
 * directory). The robust answer is a defensive PSR-4 resolver registered
 * BEHIND composer's classmap (`true` for prepend = false → appended), so:
 *   - composer-classmap hits  → fast path, zero overhead, untouched
 *   - composer-classmap miss  → this fallback walks `lib/` PSR-4 style
 *
 * Mirrors the defensive `Smail\Engine\*` autoloader in
 * {@see \OCA\SouveraMail\Util\EngineHelper::loadApp()} — same philosophy:
 * never trust a deploy-time artifact for runtime correctness.
 *
 * ------------------------------------------------------------------
 * Hook 2 — Lowercase-underscore namespace alias for NC34
 * ------------------------------------------------------------------
 * NC 34's `IAppManager::getAppNamespace()` falls back to `ucfirst($appId)`
 * when the in-memory `core.appinfo` cache is missing the `<namespace>` tag,
 * which derives `OCA\Souvera_mail\*` from app id `souvera_mail`. The
 * companion bridge class at `lib-bridge/Souvera_mail/AppInfo/Application.php`
 * exists for the same reason. Both stay registered until the operator's
 * memcache catches up with our `info.xml`.
 */

declare(strict_types=1);

if (\defined('SOUVERA_MAIL_NAMESPACE_BRIDGE_INSTALLED')) {
    return;
}
\define('SOUVERA_MAIL_NAMESPACE_BRIDGE_INSTALLED', true);

// Hook 1: PSR-4 fallback for OCA\SouveraMail\* — covers stale composer classmaps.
\spl_autoload_register(function (string $class): void {
    static $prefix = 'OCA\\SouveraMail\\';
    static $baseDir = null;
    if ($baseDir === null) {
        // lib-bridge/namespace-bridge.php → lib-bridge/ → app root → lib/
        $baseDir = \dirname(__DIR__) . '/lib/';
    }
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $relative = \substr($class, \strlen($prefix));
    $file = $baseDir . \strtr($relative, '\\', DIRECTORY_SEPARATOR) . '.php';
    if (\is_file($file)) {
        include_once $file;
    }
}, true, false); // prepend=false → only kicks in when composer's classmap missed.

// Hook 2: Alias OCA\Souvera_mail\* → OCA\SouveraMail\* for NC34's ucfirst() derivation.
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
