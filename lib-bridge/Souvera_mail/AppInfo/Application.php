<?php

/*
 * Bridge `Application` class for the lowercase-`m` underscore namespace
 * `OCA\Souvera_mail\` that Nextcloud 34's `IAppManager::getAppNamespace()`
 * derives from our app id when the `<namespace>` tag in our `info.xml`
 * is stale in the in-memory `core.appinfo` cache.
 *
 * Why this file exists
 * --------------------
 * NC 34's namespace resolution flow:
 *
 *   getAppNamespace($appId)
 *     -> $appInfo = getAppInfo($appId);             // memcache-cached
 *     -> isset($appInfo['namespace'])
 *           ? trim($appInfo['namespace'])            // CamelCase from our info.xml
 *           : ucfirst($appId);                       // fallback -> "Souvera_mail"
 *
 * Operators upgrading from an older release of this app whose info.xml
 * shipped without `<namespace>` see the fallback path: `ucfirst('souvera_mail')`
 * = `Souvera_mail`. NC then looks up `OCA\Souvera_mail\AppInfo\Application`
 * and crashes with QueryNotFoundException (live report 2026-06-30:
 * PageController could not be resolved under `OCA\Souvera_mail\Controller\…`).
 *
 * Rather than fight the operator's memcache state, we accept both shapes.
 * The companion file `lib-bridge/namespace-bridge.php` (loaded eagerly via
 * composer's `autoload.files` mechanism, so it runs before any controller
 * lookup) installs an spl_autoload_register that rewrites every
 * `OCA\Souvera_mail\*` lookup onto the real `OCA\SouveraMail\*` class via
 * `class_alias`. This bridge class itself gives NC something to `new` when
 * it asks for `OCA\Souvera_mail\AppInfo\Application` — it inherits the
 * entire boot/registration surface from the real Application so every
 * NC service (events, repair-steps, navigation, settings, dashboard
 * widget, commands) is wired identically regardless of which namespace
 * shape NC happens to derive for us.
 *
 * Once the operator's memcache catches up with the current info.xml,
 * NC will start resolving us under `OCA\SouveraMail` directly and this
 * bridge becomes dormant (the spl hook short-circuits on every lookup
 * that does not start with the underscore prefix).
 */

declare(strict_types=1);

namespace OCA\Souvera_mail\AppInfo;

/*
 * CRITICAL — pre-class `require_once` (added v0.14.10, 2026-02-19):
 * When Nextcloud's memcache derives our namespace via `ucfirst($appId)`
 * (i.e. before the `<namespace>` tag from info.xml has been repopulated
 * into `core.appinfo`), NC autoloads THIS bridge Application via its
 * composer classmap — NOT via the real \OCA\SouveraMail\AppInfo\Application
 * file. If we don't require vendor/autoload.php HERE, the two spl hooks
 * in `lib-bridge/namespace-bridge.php` (Hook 1 = PSR-4 fallback, Hook 2
 * = underscore→CamelCase class_alias) never register — and the very
 * next NC lookup (`OCA\Souvera_mail\Controller\PageController`) blows
 * up with "class does not exist", crashing the app boot.
 *
 * v0.14.9 tickled this race window because `<background-jobs>` in
 * info.xml shifted the Application load moment earlier in NC's boot.
 * Live report SEG 2026-02-19 (self-recovered on cache clear).
 *
 * `require_once` is idempotent — if vendor/autoload.php has already
 * been loaded (real Application, previous invocation, whatever) this
 * is a no-op zero-cost line.
 *
 * Note: this require_once MUST live AFTER the namespace declaration
 * (PHP forbids top-level statements between `declare` and `namespace`
 * once the file has a namespace at all — hence the placement here).
 * The `\dirname` walk goes up 3 dirs:
 *   AppInfo → Souvera_mail → lib-bridge → app-root, then `/vendor/autoload.php`.
 */
$vendorAutoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
if (\is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

final class Application extends \OCA\SouveraMail\AppInfo\Application
{
}
