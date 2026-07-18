<?php
/**
 * v0.16.3 — Regression pin for the "Seite nicht gefunden" bug:
 *
 * Operator deploy of v0.16.2 triggered `composer install` fresh
 * from tarball. Composer 2.5+ hardened its PSR-4 enforcement and
 * emitted "does not comply with psr-4 autoloading standard" for
 * `lib/Sieve/Types.php` (which intentionally holds 5 value-object
 * classes in one file) — SKIPPING the class mappings. The 5
 * PSR-4-shim files (`Rule.php`, `TestNode.php`, `ActionNode.php`,
 * `MessageFacts.php`, `EvaluatedActions.php`) each only
 * `require_once __DIR__ . '/Types.php'` — Composer 2.5+ sees them
 * as "declares no class" and skips them too.
 *
 * Net effect: `\OCA\SouveraMail\Sieve\Rule` had NO classmap entry.
 * First code path that touched Sieve (Sieve-Apply POST, install
 * repair-step scanning) hit a class-not-found fatal → Nextcloud
 * auto-disabled the app → /apps/souvera_mail responded with
 * "Seite nicht gefunden".
 *
 * Fix (v0.16.3):
 *   - `scripts/fix-classmap.php` re-inserts the 5 Sieve →
 *     shim-file mappings AFTER every `composer dump-autoload`.
 *   - `composer.json` runs it via `post-autoload-dump`.
 *   - Idempotent — safe to run against an already-fixed classmap.
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

// -------------------------------------------------------------------
// 1. Fix-script exists and is executable PHP.
// -------------------------------------------------------------------
$script = '/app/scripts/fix-classmap.php';
$a(\is_file($script), 'scripts/fix-classmap.php exists');
$scriptSrc = (string) \file_get_contents($script);
$a(\str_contains($scriptSrc, 'ActionNode'), 'fix-classmap.php handles ActionNode');
$a(\str_contains($scriptSrc, 'EvaluatedActions'), 'fix-classmap.php handles EvaluatedActions');
$a(\str_contains($scriptSrc, 'MessageFacts'), 'fix-classmap.php handles MessageFacts');
$a(\str_contains($scriptSrc, "'Rule'"), 'fix-classmap.php handles Rule');
$a(\str_contains($scriptSrc, 'TestNode'), 'fix-classmap.php handles TestNode');
$a(\str_contains($scriptSrc, 'is_file($classmap)'), 'fix-classmap.php gracefully skips when vendor/composer is absent');

// The script must patch BOTH files — the runtime classmap
// (autoload_classmap.php) AND the optimized static loader
// (autoload_static.php) — otherwise composer's "optimize-autoloader"
// mode still misses the classes.
$a(\str_contains($scriptSrc, 'autoload_classmap.php'), 'fix-classmap.php targets autoload_classmap.php');
$a(\str_contains($scriptSrc, 'autoload_static.php'), 'fix-classmap.php targets autoload_static.php');

// PHP-syntax check on the fix script itself.
$syntaxCheck = \shell_exec('php -l ' . \escapeshellarg($script) . ' 2>&1');
$a(\is_string($syntaxCheck) && \str_contains($syntaxCheck, 'No syntax errors'),
    'fix-classmap.php passes `php -l`');

// -------------------------------------------------------------------
// 2. composer.json wires the fix into post-autoload-dump.
// -------------------------------------------------------------------
$composerJson = \json_decode((string) \file_get_contents('/app/composer.json'), true);
$a(\is_array($composerJson), 'composer.json is valid JSON');
$postScripts = $composerJson['scripts']['post-autoload-dump'] ?? [];
$a(\is_array($postScripts) && \count($postScripts) > 0,
    'composer.json post-autoload-dump has at least one script wired up');
$a(\in_array('@php scripts/fix-classmap.php', $postScripts, true)
    || \in_array('php scripts/fix-classmap.php', $postScripts, true),
    'composer.json post-autoload-dump runs scripts/fix-classmap.php');

// -------------------------------------------------------------------
// 3. Live classmap: after our most recent dump-autoload run, all 5
//    Sieve value-objects AND both new SASL classes are mapped
//    correctly.
// -------------------------------------------------------------------
$classmap = (string) \file_get_contents('/app/vendor/composer/autoload_classmap.php');
$static   = (string) \file_get_contents('/app/vendor/composer/autoload_static.php');

foreach ([
    'ActionNode' => '/lib/Sieve/ActionNode.php',
    'EvaluatedActions' => '/lib/Sieve/EvaluatedActions.php',
    'MessageFacts' => '/lib/Sieve/MessageFacts.php',
    'Rule' => '/lib/Sieve/Rule.php',
    'TestNode' => '/lib/Sieve/TestNode.php',
] as $cls => $shim) {
    $needleCm = "'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}' => \$baseDir . '{$shim}',";
    $needleSt = "'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}' => __DIR__ . '/../..' . '{$shim}',";
    $a(\str_contains($classmap, $needleCm),
        "autoload_classmap.php maps Sieve\\{$cls} → {$shim}");
    $a(\str_contains($static, $needleSt),
        "autoload_static.php maps Sieve\\{$cls} → {$shim}");
}

// SASL PLAIN + LOGIN — restored in v0.16.1, must survive
// dump-autoload cycles because they live under the classmap
// directive (`app/smail/v/current/app/libraries/Smail/`) and don't
// need the fix-script.
$a(\str_contains($classmap,
    "'Smail\\\\Engine\\\\SASL\\\\PLAIN' => \$baseDir . '/app/smail/v/current/app/libraries/Smail/Engine/sasl/plain.php'"),
    'autoload_classmap.php maps SASL\\PLAIN → sasl/plain.php');
$a(\str_contains($classmap,
    "'Smail\\\\Engine\\\\SASL\\\\LOGIN' => \$baseDir . '/app/smail/v/current/app/libraries/Smail/Engine/sasl/login.php'"),
    'autoload_classmap.php maps SASL\\LOGIN → sasl/login.php');

// -------------------------------------------------------------------
// 4. Idempotence — running fix-classmap.php a second time must
//    produce zero mutations (safety against loops / repeated hooks).
// -------------------------------------------------------------------
$secondRun = @\shell_exec('cd /app && php scripts/fix-classmap.php 2>&1');
$a($secondRun === null || $secondRun === '' || !\str_contains((string) $secondRun, 'patched'),
    'fix-classmap.php is idempotent — second run leaves classmap untouched');

// -------------------------------------------------------------------
// 5. Post-run: 59+ classes still in the map (sanity that we didn't
//    accidentally corrupt the file).
// -------------------------------------------------------------------
$a(\preg_match_all("/^\\s*'[A-Za-z_\\\\\\\\]+'\\s*=>/m", $classmap) > 300,
    'autoload_classmap.php still holds 300+ class entries after patching');

// -------------------------------------------------------------------
// 6. Version bump
// -------------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$pkg  = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#<version>0\.(?:1[6-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.16.3 or higher');
$a((bool) \preg_match('#"version"\s*:\s*"0\.(?:1[6-9]|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.16.3 or higher');

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
