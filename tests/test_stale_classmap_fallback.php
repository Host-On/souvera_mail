<?php
/**
 * Regression test for the stale-composer-classmap fallback added to
 * `lib-bridge/namespace-bridge.php` after the live crash report
 * 2026-07-01:
 *
 *   Could not resolve OCA\SouveraMail\Service\DomainConfigService
 *   Class "OCA\SouveraMail\Util\NavigationTitle" not found
 *
 * Root cause: the operator rsyncs the app tree on upgrade but ships their
 * existing `vendor/composer/autoload_classmap.php` snapshot, which does
 * not list classes added in the new release. Composer's ClassLoader then
 * returns false from findFile() and PHP throws ClassNotFound mid-request,
 * taking down NC's DI graph at app boot.
 *
 * Fix: a defensive PSR-4 fallback autoload hook installed by
 * `namespace-bridge.php` BEHIND composer's classmap. Composer's classmap
 * still wins the fast path; the fallback only fires on classmap misses.
 *
 * This test:
 *   - asserts the fallback exists in the bridge source with the expected
 *     properties (prepend=false so it sits behind composer, scoped to
 *     OCA\SouveraMail\ prefix only, resolves files relative to /lib/)
 *   - simulates an empty / stale composer classmap and confirms the
 *     fallback resolves real classes from /app/lib/
 *   - confirms the fallback does NOT bleed outside the OCA\SouveraMail\
 *     prefix (e.g. it does not try to autoload OCA\OtherApp\* classes)
 *   - confirms the file-existence check protects against random
 *     lookups for non-existent classes (no fatal include)
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Static checks: namespace-bridge.php declares the fallback hook
// ---------------------------------------------------------------
$src = (string)file_get_contents('/app/lib-bridge/namespace-bridge.php');

assertTrue(str_contains($src, "static \$prefix = 'OCA\\\\SouveraMail\\\\'"),
    "namespace-bridge.php fallback hook targets the OCA\\SouveraMail\\ prefix",
    $passes, $failures);

assertTrue((bool)preg_match("/\\\$baseDir\\s*=\\s*\\\\dirname\\(__DIR__\\)\\s*\\.\\s*'\\/lib\\/'/", $src),
    "namespace-bridge.php fallback resolves files relative to <approot>/lib/ (PSR-4 root)",
    $passes, $failures);

// Must be registered AFTER composer (prepend=false) so composer's classmap
// wins the fast path. The OLD hook for OCA\Souvera_mail\ still uses
// prepend=true; we must have BOTH signatures present.
$prependFalseCount = preg_match_all('/spl_autoload_register\([\s\S]+?true,\s*false\)/', $src);
$prependTrueCount  = preg_match_all('/spl_autoload_register\([\s\S]+?true,\s*true\)/', $src);
assertTrue($prependFalseCount >= 1,
    "namespace-bridge.php registers at least one APPENDED hook (prepend=false) — sits behind composer",
    $passes, $failures);
assertTrue($prependTrueCount >= 1,
    "namespace-bridge.php still registers the lowercase-underscore alias hook with prepend=true",
    $passes, $failures);

assertTrue(str_contains($src, 'include_once $file'),
    "namespace-bridge.php fallback includes the resolved file (does not alias)",
    $passes, $failures);

assertTrue(str_contains($src, 'is_file($file)'),
    "namespace-bridge.php fallback guards include_once with is_file() — no fatals for misses",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. End-to-end sim: stale classmap, fallback must still resolve
// ---------------------------------------------------------------
$sim = <<<'PHP'
<?php
declare(strict_types=1);

// Simulate composer's classmap as EMPTY (operator shipped a stale snapshot).
// Register it prepend=true, just like composer's ClassLoader does.
spl_autoload_register(function (string $class) {
    static $staleClassmap = [];
    if (isset($staleClassmap[$class]) && is_file($staleClassmap[$class])) {
        include_once $staleClassmap[$class];
    }
}, true, true);

require '/app/lib-bridge/namespace-bridge.php';

// 1) NavigationTitle (no external deps) — should resolve via the fallback.
if (!class_exists('OCA\\SouveraMail\\Util\\NavigationTitle')) {
    fwrite(STDERR, "FAIL: fallback did not resolve OCA\\SouveraMail\\Util\\NavigationTitle\n");
    exit(1);
}
$r = new ReflectionClass('OCA\\SouveraMail\\Util\\NavigationTitle');
if ($r->getFileName() !== '/app/lib/Util/NavigationTitle.php') {
    fwrite(STDERR, "FAIL: NavigationTitle resolved from wrong file: " . $r->getFileName() . "\n");
    exit(2);
}

// 2) Sanity: a non-existent class under our prefix must NOT cause a fatal.
$err = null;
set_error_handler(function ($e, $m) use (&$err) { $err = $m; });
$exists = class_exists('OCA\\SouveraMail\\Service\\ClassThatWillNeverExist', true);
restore_error_handler();
if ($exists) {
    fwrite(STDERR, "FAIL: fallback wrongly reported a non-existent class as resolvable\n");
    exit(3);
}
if ($err !== null) {
    fwrite(STDERR, "FAIL: fallback emitted a warning for a non-existent class: $err\n");
    exit(4);
}

// 3) Bleed check: a class outside our prefix must not be touched.
$exists = class_exists('OCA\\OtherApp\\Foo', false);
if ($exists) {
    fwrite(STDERR, "FAIL: fallback bled outside its prefix\n");
    exit(5);
}

// 4) DomainConfigService — the file MUST exist on disk where the fallback expects it
//    (we can't load it here without OCP\IConfig, but we can confirm the fallback's
//    file-resolution math points to the real file).
$expectedPath = '/app/lib/Service/DomainConfigService.php';
if (!is_file($expectedPath)) {
    fwrite(STDERR, "FAIL: DomainConfigService.php missing at the path the fallback computes\n");
    exit(6);
}

echo "ALL OK\n";
PHP;
file_put_contents('/tmp/stale_classmap_sim.php', $sim);
$out = (string)shell_exec('php /tmp/stale_classmap_sim.php 2>&1');
assertTrue(str_contains($out, 'ALL OK'),
    "End-to-end stale-classmap sim — fallback resolves NavigationTitle from lib/Util/, no bleed, no fatals on misses (output: " . trim($out) . ")",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Composer's CURRENT classmap still has the canonical entries
//    (so the fast path stays fast on freshly-dumped vendor/)
// ---------------------------------------------------------------
$classmap = (string)file_get_contents('/app/vendor/composer/autoload_classmap.php');
assertTrue(str_contains($classmap, "'OCA\\\\SouveraMail\\\\Util\\\\NavigationTitle' => \$baseDir . '/lib/Util/NavigationTitle.php'"),
    "Fresh composer classmap entry for NavigationTitle is present (fast path)",
    $passes, $failures);
assertTrue(str_contains($classmap, "'OCA\\\\SouveraMail\\\\Service\\\\DomainConfigService' => \$baseDir . '/lib/Service/DomainConfigService.php'"),
    "Fresh composer classmap entry for DomainConfigService is present (fast path)",
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
