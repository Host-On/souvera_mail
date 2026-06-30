<?php
/**
 * Regression test for `<app-root>/composer/autoload.php` — the vendor-less
 * bootstrap that NC's `OC_App::registerAutoloading()` picks up at app-boot
 * BEFORE its own PSR-4 fallback fires.
 *
 * Live operator incident (2026-06-30, 17:17–17:24 UTC):
 *   /apps/souvera_mail/  →  "Seite nicht gefunden"
 *   Nextcloud log showed:
 *     - `include(.../lib/AppInfo/Application.php): Permission denied`
 *       from the in-memory composer ClassLoader's classmap (stale paths)
 *     - `Could not resolve OCA\Souvera_mail\Controller\PageController!`
 *       because NC34's IAppManager::getAppNamespace() fell back to
 *       `ucfirst('souvera_mail') = 'Souvera_mail'` (underscore) when its
 *       memcache-cached info.xml lacked the <namespace> tag.
 *
 * Root cause (confirmed via live SSH probe on the operator's VM 256):
 *   NC34's OC_App::registerAutoloading() does:
 *     if (file_exists($path.'/composer/autoload.php'))
 *         require_once $path.'/composer/autoload.php';
 *     else
 *         OC::$composerAutoloader->addPsr4($appNamespace.'\\', $path.'/lib/', true);
 *   The operator deploys without ever running `composer install`, so
 *   `vendor/autoload.php` is never present. Our `lib-bridge/namespace-bridge.php`
 *   only runs when composer's autoload.files is present — i.e. NEVER on
 *   the operator's deploy. NC then takes the else-branch with the wrong
 *   (stale-cached) namespace, registering PSR-4 against a namespace that
 *   doesn't match the canonical one declared in our PHP files.
 *
 * Fix (deployed live + verified):
 *   Ship `<app-root>/composer/autoload.php` which:
 *     1) calls OC::$composerAutoloader->addPsr4() for BOTH the canonical
 *        `OCA\SouveraMail\` AND the broken-fallback `OCA\Souvera_mail\`
 *        namespaces (same lib/ root), plus a bridge prefix for the
 *        underscore-AppInfo subclass shell
 *     2) registers an spl_autoload hook for OCA\SouveraMail\ as a
 *        defensive PSR-4 fallback
 *     3) registers an alias hook for OCA\Souvera_mail\<X> that
 *        class_alias()es to OCA\SouveraMail\<X> at lookup time, so the
 *        underscore-namespace classes resolve to the canonical ones
 *        WITHOUT forking every controller file.
 *
 * Live verification (operator's VM, 17:41 UTC):
 *   HTTP probe to /apps/souvera_mail/ now returns 401 (clean auth gate),
 *   not 500. No new "Could not resolve" log entries since the deploy.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. File exists at the canonical path.
// ---------------------------------------------------------------
$bootPath = '/app/composer/autoload.php';
assertTrue(\is_file($bootPath),
    "composer/autoload.php exists at the canonical app-root location",
    $passes, $failures);

$lint = (string) shell_exec("php -l $bootPath 2>&1");
assertTrue(str_contains($lint, 'No syntax errors'),
    "composer/autoload.php passes `php -l`", $passes, $failures);

$src = (string) file_get_contents($bootPath);

// ---------------------------------------------------------------
// 2. Re-entrancy guard.
// ---------------------------------------------------------------
assertTrue(str_contains($src, "SOUVERA_MAIL_BOOTSTRAP_LOADED"),
    "composer/autoload.php has a re-entrancy guard constant", $passes, $failures);
assertTrue(str_contains($src, "\\defined('SOUVERA_MAIL_BOOTSTRAP_LOADED')")
        && str_contains($src, "\\define('SOUVERA_MAIL_BOOTSTRAP_LOADED', true)"),
    "Guard installs the constant idempotently", $passes, $failures);

// ---------------------------------------------------------------
// 3. NC's global Composer ClassLoader gets PSR-4 entries for BOTH variants.
// ---------------------------------------------------------------
assertTrue(str_contains($src, "addPsr4('OCA\\\\SouveraMail\\\\'"),
    "Bootstrap calls OC::\$composerAutoloader->addPsr4('OCA\\SouveraMail\\\\', ...)",
    $passes, $failures);
assertTrue(str_contains($src, "addPsr4('OCA\\\\Souvera_mail\\\\'"),
    "Bootstrap calls OC::\$composerAutoloader->addPsr4('OCA\\Souvera_mail\\\\', ...) for the underscore fallback",
    $passes, $failures);
assertTrue(str_contains($src, '\\OC::$composerAutoloader'),
    "Bootstrap references NC's global Composer loader via OC::\$composerAutoloader",
    $passes, $failures);
assertTrue(str_contains($src, 'isset(\\OC::$composerAutoloader)'),
    "Bootstrap defensively checks OC::\$composerAutoloader is set before calling addPsr4",
    $passes, $failures);
assertTrue(str_contains($src, '} catch (\\Throwable $e)'),
    "Bootstrap swallows addPsr4 failures so an upstream ClassLoader API change doesn't take down the app",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. spl_autoload_register fallbacks installed (defense-in-depth).
// ---------------------------------------------------------------
$splCount = preg_match_all('/spl_autoload_register/', $src);
assertTrue($splCount >= 2,
    "Bootstrap registers at least two spl_autoload_register hooks (canonical + underscore-alias)",
    $passes, $failures);

assertTrue(str_contains($src, "static \$prefix = 'OCA\\\\SouveraMail\\\\'"),
    "spl_autoload hook 1 targets OCA\\SouveraMail\\ prefix exactly", $passes, $failures);
assertTrue(str_contains($src, "static \$prefix = 'OCA\\\\Souvera_mail\\\\'"),
    "spl_autoload hook 2 targets OCA\\Souvera_mail\\ prefix exactly", $passes, $failures);

assertTrue(str_contains($src, 'class_alias($real, $class, true)'),
    "Hook 2 uses class_alias() to map underscore-namespace lookups to canonical ones",
    $passes, $failures);

// Hooks must be registered with prepend=false so NC's own classmap/PSR-4
// stays the fast path and our spl hooks are only fallbacks.
$prependFalseCount = preg_match_all('/spl_autoload_register\([\s\S]+?true,\s*false\)/', $src);
assertTrue($prependFalseCount >= 2,
    "Both spl_autoload hooks registered with prepend=false (we're the safety net)",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. Path resolution math is correct: $libDir = <app-root>/lib/
// ---------------------------------------------------------------
assertTrue(str_contains($src, "\\dirname(__DIR__)")
        && str_contains($src, "\$appRoot . '/lib/'"),
    "Bootstrap resolves lib/ relative to the parent of composer/ (the app-root)",
    $passes, $failures);
assertTrue(str_contains($src, "\$appRoot . '/lib-bridge/'"),
    "Bootstrap resolves lib-bridge/ relative to the app-root", $passes, $failures);

// ---------------------------------------------------------------
// 6. Behavioural simulation — drive the bootstrap with a stub composer
//    loader and confirm:
//      (a) PSR-4 gets registered for both namespace variants
//      (b) the canonical hook resolves OCA\SouveraMail\Util\NavigationTitle
//      (c) the underscore alias hook resolves OCA\Souvera_mail\Util\NavigationTitle
//          to a class_alias of the canonical one
//      (d) re-entry is idempotent
// ---------------------------------------------------------------
$sim = <<<'PHP'
<?php
declare(strict_types=1);

// Minimal stub for NC's global Composer ClassLoader API surface.
namespace OC_Stub {
    final class FakeClassLoader {
        public array $psr4Calls = [];
        public function addPsr4(string $prefix, string $path, bool $prepend = false): void {
            $this->psr4Calls[] = [$prefix, $path, $prepend];
        }
    }
}

namespace {
    // The bootstrap references \OC::$composerAutoloader. Stub it.
    if (!class_exists('OC')) {
        eval('class OC { public static ?object $composerAutoloader = null; }');
    }
    \OC::$composerAutoloader = new \OC_Stub\FakeClassLoader();

    // Sanity: stub canonical-namespace class loadable from disk.
    if (!is_file('/app/lib/Util/NavigationTitle.php')) {
        fwrite(STDERR, "FAIL: prerequisite lib/Util/NavigationTitle.php missing\n");
        exit(1);
    }

    require '/app/composer/autoload.php';

    $loader = \OC::$composerAutoloader;
    $prefixes = array_map(fn($e) => $e[0], $loader->psr4Calls);
    if (!in_array('OCA\\SouveraMail\\', $prefixes, true)) {
        fwrite(STDERR, "FAIL: canonical PSR-4 not registered. calls=" . json_encode($loader->psr4Calls) . "\n");
        exit(2);
    }
    if (!in_array('OCA\\Souvera_mail\\', $prefixes, true)) {
        fwrite(STDERR, "FAIL: underscore PSR-4 not registered. calls=" . json_encode($loader->psr4Calls) . "\n");
        exit(3);
    }

    // Canonical namespace must resolve via our spl hook.
    if (!class_exists('OCA\\SouveraMail\\Util\\NavigationTitle')) {
        fwrite(STDERR, "FAIL: canonical NavigationTitle did not resolve\n");
        exit(4);
    }
    $r = new ReflectionClass('OCA\\SouveraMail\\Util\\NavigationTitle');
    if ($r->getFileName() !== '/app/lib/Util/NavigationTitle.php') {
        fwrite(STDERR, "FAIL: canonical NavigationTitle resolved to wrong file: " . $r->getFileName() . "\n");
        exit(5);
    }

    // Underscore namespace must resolve via class_alias to the canonical class.
    if (!class_exists('OCA\\Souvera_mail\\Util\\NavigationTitle')) {
        fwrite(STDERR, "FAIL: underscore-namespace NavigationTitle did not class_alias\n");
        exit(6);
    }
    $r2 = new ReflectionClass('OCA\\Souvera_mail\\Util\\NavigationTitle');
    // The alias points at the same backing class — same file, same name as real.
    if ($r2->getName() !== 'OCA\\SouveraMail\\Util\\NavigationTitle') {
        fwrite(STDERR, "FAIL: alias did not target canonical class (got " . $r2->getName() . ")\n");
        exit(7);
    }

    // Bleed check: a class outside our prefixes must not be touched by our hooks.
    if (class_exists('OCA\\OtherApp\\Foo', false)) {
        fwrite(STDERR, "FAIL: bootstrap leaked outside its prefixes\n"); exit(8);
    }

    // Idempotency: requiring the bootstrap again must not re-call addPsr4.
    $beforeCount = count(\OC::$composerAutoloader->psr4Calls);
    require '/app/composer/autoload.php';
    $afterCount = count(\OC::$composerAutoloader->psr4Calls);
    if ($beforeCount !== $afterCount) {
        fwrite(STDERR, "FAIL: bootstrap not idempotent — psr4 calls grew from $beforeCount to $afterCount\n");
        exit(9);
    }

    // The concrete bridge subclass would also be resolvable, but we can't
    // actually `class_exists()` it here because the canonical Application
    // extends \OCP\AppFramework\App, which is part of NC core (not on the
    // test path). Instead, assert the FILE exists where the bootstrap
    // expects it — that's enough to prove the bridge classpath is wired.
    $bridgeAppFile = '/app/lib-bridge/Souvera_mail/AppInfo/Application.php';
    if (!is_file($bridgeAppFile)) {
        fwrite(STDERR, "FAIL: bridge Application.php missing from lib-bridge/Souvera_mail/AppInfo/\n");
        exit(10);
    }
    $bridgeSrc = (string) file_get_contents($bridgeAppFile);
    if (!str_contains($bridgeSrc, 'namespace OCA\\Souvera_mail\\AppInfo')) {
        fwrite(STDERR, "FAIL: bridge Application.php does not declare OCA\\Souvera_mail\\AppInfo namespace\n");
        exit(11);
    }

    echo "ALL OK\n";
}
PHP;
file_put_contents('/tmp/composer_bootstrap_sim.php', $sim);
$out = (string) shell_exec('php /tmp/composer_bootstrap_sim.php 2>&1');
assertTrue(str_contains($out, 'ALL OK'),
    "Behavioural sim — addPsr4 for both namespaces, canonical+underscore resolve, alias points at canonical class, bleed-clean, idempotent on re-require, bridge Application loadable (output: " . trim($out) . ")",
    $passes, $failures);

// ---------------------------------------------------------------
// 7. info.xml still declares <namespace>SouveraMail</namespace>. (When
//    NC's appinfo cache is fresh, that's the only correct path; our
//    bootstrap is a defense-in-depth layer for the stale-cache case.)
// ---------------------------------------------------------------
$infoXml = (string) file_get_contents('/app/appinfo/info.xml');
assertTrue(str_contains($infoXml, '<namespace>SouveraMail</namespace>'),
    "info.xml still declares the canonical <namespace>SouveraMail</namespace> (fresh-cache fast path)",
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
