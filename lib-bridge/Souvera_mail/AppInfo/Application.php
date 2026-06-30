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

final class Application extends \OCA\SouveraMail\AppInfo\Application
{
}
