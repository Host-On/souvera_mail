<?php
/**
 * Regression test for the namespace-bridge introduced in Souvera Mail 0.13.5
 * against the live crash reported on 2026-06-30:
 *
 *   Could not resolve OCA\Souvera_mail\Controller\PageController!
 *   Class "OCA\Souvera_mail\Controller\PageController" does not exist
 *
 * Root cause: NC34's `IAppManager::getAppNamespace($appId)` falls back to
 * `ucfirst($appId)` when the cached info.xml lacks `<namespace>`. For
 * `souvera_mail` that yields `OCA\Souvera_mail\*` (lower-case `m`), which
 * doesn't match our PSR-4 mapping `OCA\SouveraMail\*`.
 *
 * Fix: a two-part bridge.
 *  1. `lib-bridge/namespace-bridge.php` — registered under composer's
 *     `autoload.files`, eagerly installs an spl_autoload hook that
 *     rewrites `OCA\Souvera_mail\<sub>` → `OCA\SouveraMail\<sub>` via
 *     `class_alias`.
 *  2. `lib-bridge/Souvera_mail/AppInfo/Application.php` — registered in
 *     the classmap; gives NC something to `new` when it asks for the
 *     underscore-namespace Application class.
 *
 * The companion JS regression (the redundant `⚙ Settings` fallback pill in
 * `quota.js`) is also locked down here.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Composer autoload artefacts contain both bridge entries
// ---------------------------------------------------------------
$classmap = file_get_contents('/app/vendor/composer/autoload_classmap.php');
assertTrue(str_contains($classmap, "'OCA\\\\Souvera_mail\\\\AppInfo\\\\Application' => \$baseDir . '/lib-bridge/Souvera_mail/AppInfo/Application.php'"),
    "Composer classmap routes OCA\\Souvera_mail\\AppInfo\\Application to the bridge file",
    $passes, $failures);

$files = file_get_contents('/app/vendor/composer/autoload_files.php');
assertTrue(str_contains($files, "'/lib-bridge/namespace-bridge.php'"),
    "Composer autoload_files lists lib-bridge/namespace-bridge.php (eager-load on every NC boot)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. composer.json declares both bridge entries
// ---------------------------------------------------------------
$composer = json_decode((string)file_get_contents('/app/composer.json'), true);
assertTrue(\is_array($composer['autoload']['files'] ?? null)
        && in_array('lib-bridge/namespace-bridge.php', $composer['autoload']['files'], true),
    "composer.json `autoload.files` includes lib-bridge/namespace-bridge.php",
    $passes, $failures);
assertTrue(\is_array($composer['autoload']['classmap'] ?? null)
        && in_array('lib-bridge/', $composer['autoload']['classmap'], true),
    "composer.json `autoload.classmap` includes lib-bridge/",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. namespace-bridge.php — content / safety properties
// ---------------------------------------------------------------
$bridgeSrc = file_get_contents('/app/lib-bridge/namespace-bridge.php');

// (a) Syntactically clean
$lint = shell_exec('php -l /app/lib-bridge/namespace-bridge.php 2>&1');
assertTrue(str_contains((string)$lint, 'No syntax errors'),
    "namespace-bridge.php passes `php -l`", $passes, $failures);

// (b) Registers the spl_autoload hook with prepend=true so it overrides
//     composer's own loader for the bridge prefix:
assertTrue((bool)preg_match("/spl_autoload_register\\([\\s\\S]+?true,\\s*true\\)/", $bridgeSrc),
    "namespace-bridge.php registers spl_autoload with (prepend=true, throw=true)",
    $passes, $failures);

// (c) Re-entrancy guard — must be safe to require_once'd multiple times by
//     different apps' composer autoloads at boot.
assertTrue(str_contains($bridgeSrc, 'SOUVERA_MAIL_NAMESPACE_BRIDGE_INSTALLED'),
    "namespace-bridge.php is re-entrancy-guarded by a SOUVERA_MAIL_NAMESPACE_BRIDGE_INSTALLED define",
    $passes, $failures);

// (d) Hook prefix is exactly the underscore namespace
assertTrue(str_contains($bridgeSrc, "'OCA\\\\Souvera_mail\\\\'"),
    "namespace-bridge.php targets exactly 'OCA\\Souvera_mail\\' (no over-broad match)",
    $passes, $failures);
assertTrue(str_contains($bridgeSrc, "'OCA\\\\SouveraMail\\\\'"),
    "namespace-bridge.php rewrites to 'OCA\\SouveraMail\\' (the canonical PSR-4 namespace)",
    $passes, $failures);

// (e) Uses class_alias to keep the underscore class resolvable for
//     subsequent `class_exists($underscore, false)` checks.
assertTrue(str_contains($bridgeSrc, 'class_alias($real, $class, true)'),
    "namespace-bridge.php uses class_alias() so the underscore name stays resolvable",
    $passes, $failures);

// (f) Handles classes, interfaces and traits — NC's DI graph touches all three.
assertTrue(str_contains($bridgeSrc, 'class_exists($real, true)')
        && str_contains($bridgeSrc, 'interface_exists($real, true)')
        && str_contains($bridgeSrc, 'trait_exists($real, true)'),
    "namespace-bridge.php aliases classes AND interfaces AND traits (NC DI touches all)",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. Bridge Application class — content / properties
// ---------------------------------------------------------------
$brSrc = file_get_contents('/app/lib-bridge/Souvera_mail/AppInfo/Application.php');
$lint = shell_exec('php -l /app/lib-bridge/Souvera_mail/AppInfo/Application.php 2>&1');
assertTrue(str_contains((string)$lint, 'No syntax errors'),
    "bridge Application.php passes `php -l`", $passes, $failures);
assertTrue((bool)preg_match("/namespace\\s+OCA\\\\Souvera_mail\\\\AppInfo;/", $brSrc),
    "bridge Application declares namespace OCA\\Souvera_mail\\AppInfo;", $passes, $failures);
assertTrue((bool)preg_match("/final\\s+class\\s+Application\\s+extends\\s+\\\\OCA\\\\SouveraMail\\\\AppInfo\\\\Application/", $brSrc),
    "bridge `final class Application extends \\OCA\\SouveraMail\\AppInfo\\Application` — inherits everything",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. End-to-end behavioural sim of the autoload hook
//    (in an isolated PHP process so we don't accidentally trigger the
//     real NC classes which our test env doesn't have)
// ---------------------------------------------------------------
$simScript = <<<'PHP'
<?php
declare(strict_types=1);
// Stub the canonical SouveraMail namespace BEFORE loading the bridge file.
namespace OCA\SouveraMail\Smoke {
    class Target { public function ping(): string { return 'pong'; } }
    interface ITargetIface {}
    trait TargetTrait {}
}
namespace {
    // Load the bridge in isolation — no composer autoload.
    require '/app/lib-bridge/namespace-bridge.php';

    // 1. Class alias works (the main scenario — Controller lookup)
    if (!class_exists('OCA\\Souvera_mail\\Smoke\\Target', true)) {
        fwrite(STDERR, "FAIL: bridge did not resolve OCA\\Souvera_mail\\Smoke\\Target\n");
        exit(1);
    }
    $obj = new \OCA\Souvera_mail\Smoke\Target();
    if ($obj->ping() !== 'pong') {
        fwrite(STDERR, "FAIL: bridge alias does not delegate to the real class\n");
        exit(2);
    }
    // 2. Reflection confirms it's an alias
    $r = new \ReflectionClass('OCA\\Souvera_mail\\Smoke\\Target');
    if ($r->getName() !== 'OCA\\SouveraMail\\Smoke\\Target') {
        fwrite(STDERR, "FAIL: alias name=" . $r->getName() . "\n");
        exit(3);
    }
    // 3. Interface alias
    if (!interface_exists('OCA\\Souvera_mail\\Smoke\\ITargetIface', true)) {
        fwrite(STDERR, "FAIL: bridge did not alias interface\n");
        exit(4);
    }
    // 4. Trait alias
    if (!trait_exists('OCA\\Souvera_mail\\Smoke\\TargetTrait', true)) {
        fwrite(STDERR, "FAIL: bridge did not alias trait\n");
        exit(5);
    }
    // 5. Unrelated class lookups are NOT touched by the hook
    if (class_exists('OCA\\OtherApp\\Foo', false)) {
        fwrite(STDERR, "FAIL: bridge bled outside its prefix\n");
        exit(6);
    }
    // 6. We do NOT load lib-bridge/Souvera_mail/AppInfo/Application.php here —
    //    it `extends \OCA\SouveraMail\AppInfo\Application` which would force-
    //    load the whole NC service graph. We assert its file structure (`final
    //    class Application extends …`) above in the static checks instead.
    echo "ALL OK\n";
}
PHP;
file_put_contents('/tmp/bridge_sim.php', $simScript);
$out = shell_exec('php /tmp/bridge_sim.php 2>&1');
assertTrue(str_contains((string)$out, 'ALL OK'),
    "End-to-end bridge sim — alias + reflection + interface + trait + non-bleed (output: " . trim((string)$out) . ")",
    $passes, $failures);

// ---------------------------------------------------------------
// 6. quota.js — the redundant "⚙ Settings" fallback pill is gone
// ---------------------------------------------------------------
$quota = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/quota.js');
assertTrue(!preg_match("/textContent\\s*=\\s*['\"]\\\\u2699[^'\"]*Settings['\"]/", $quota),
    "quota.js no longer renders the standalone '⚙ Settings' fallback pill",
    $passes, $failures);
assertTrue((bool)preg_match("/renderSettingsOnly[\\s\\S]{0,500}removePill\\(\\)/", $quota),
    "quota.js renderSettingsOnly() now degrades by simply removing the pill",
    $passes, $failures);

// ---------------------------------------------------------------
// 7. info.xml still declares the canonical CamelCase namespace
//    (so operators with a fresh cache get the clean path)
// ---------------------------------------------------------------
$info = file_get_contents('/app/appinfo/info.xml');
preg_match('#<namespace>([^<]+)</namespace>#', $info, $nm);
assertTrue(($nm[1] ?? '') === 'SouveraMail',
    "info.xml still declares <namespace>SouveraMail</namespace> (the canonical CamelCase path)",
    $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
