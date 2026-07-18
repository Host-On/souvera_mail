<?php
declare(strict_types=1);

/**
 * scripts/fix-classmap.php
 *
 * Post-autoload-dump repair. Composer ≥ 2.4 hardened its PSR-4 scanner:
 * `lib/Sieve/Rule.php`, `TestNode.php`, `ActionNode.php`, `MessageFacts.php`
 * and `EvaluatedActions.php` are intentional PSR-4 shims that only
 * `require_once __DIR__ . '/Types.php';` — the actual class body lives
 * in Types.php (see the docblock on that file for the rationale:
 * partial-deploy resilience, OpCache safety, single source of truth).
 *
 * Modern Composer sees "shim declares no class" and skips it AND
 * emits "does not comply with psr-4 autoloading standard" for Types.php.
 * Result: the 5 Sieve value-object classes have NO classmap entry —
 * a fatal-error time-bomb the first time any request touches
 * `\OCA\SouveraMail\Sieve\Rule` (which happens on every Sieve
 * mini-interpreter run, ~every rule-apply POST).
 *
 * This script runs from `composer.json`'s `post-autoload-dump` hook.
 * It scans autoload_classmap.php + autoload_static.php and inserts
 * the 5 Sieve value-object → shim-file mappings. Idempotent —
 * running it against an already-fixed classmap is a no-op.
 *
 * v0.16.3 — introduced. The prior deploy on the operator's Nextcloud
 * hit this exact bug: fresh `composer install` from tarball produced
 * a classmap without Sieve mappings → first request that touched
 * Sieve fataled → Nextcloud auto-disabled the app → /apps/souvera_mail
 * showed "Seite nicht gefunden".
 */

$vendor = \dirname(__DIR__) . '/vendor/composer';
$classmap = $vendor . '/autoload_classmap.php';
$static   = $vendor . '/autoload_static.php';

if (!\is_file($classmap) || !\is_file($static)) {
    exit(0);
}

$SHIMS = [
    'ActionNode'       => '/lib/Sieve/ActionNode.php',
    'EvaluatedActions' => '/lib/Sieve/EvaluatedActions.php',
    'MessageFacts'     => '/lib/Sieve/MessageFacts.php',
    'Rule'             => '/lib/Sieve/Rule.php',
    'TestNode'         => '/lib/Sieve/TestNode.php',
];

// Build the exact PHP-source strings we need to insert. In the
// generated autoload files, namespace separators are doubled
// (`OCA\\SouveraMail\\Sieve\\Rule`) — that's how they render in a
// single-quoted PHP string literal.
//
// nowdoc keeps every backslash intact — no escape gymnastics.
$makeCmLine = static function (string $cls, string $shim): string {
    // Result:  'OCA\\SouveraMail\\Sieve\\Rule' => $baseDir . '/lib/Sieve/Rule.php',
    return "    'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}' => \$baseDir . '{$shim}',";
};
$makeStLine = static function (string $cls, string $shim): string {
    // Result:  'OCA\\SouveraMail\\Sieve\\Rule' => __DIR__ . '/../..' . '/lib/Sieve/Rule.php',
    return "        'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}' => __DIR__ . '/../..' . '{$shim}',";
};

// ------------------------------------------------------------------
// Patch autoload_classmap.php
// ------------------------------------------------------------------
$cm = (string) \file_get_contents($classmap);
$cmMut = false;
$anchorCm = "    'OCA\\\\SouveraMail\\\\Sieve\\\\MiniInterpreter'";
foreach ($SHIMS as $cls => $shim) {
    $marker = "'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}'";
    $wrong  = "    'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}' => \$baseDir . '/lib/Sieve/Types.php',";
    $correct = $makeCmLine($cls, $shim);
    if (\str_contains($cm, $wrong)) {
        $cm = \str_replace($wrong, $correct, $cm);
        $cmMut = true;
        continue;
    }
    // Skip if a correct entry (double-backslash form) is already there.
    if (\str_contains($cm, $correct)) {
        continue;
    }
    // Also skip if any variant already exists (single-backslash artefact
    // from a previous incorrect script run — leave it alone; the class
    // does load, just not pretty).
    if (\str_contains($cm, $marker)) {
        continue;
    }
    // Neither correct nor wrong entry present — insert before the
    // MiniInterpreter anchor (already in file per the composer PSR-4 scan).
    if (\str_contains($cm, $anchorCm)) {
        $cm = \str_replace(
            $anchorCm,
            $correct . "\n" . $anchorCm,
            $cm
        );
        $cmMut = true;
    }
}
if ($cmMut) {
    \file_put_contents($classmap, $cm);
    echo "[fix-classmap] patched " . \basename($classmap) . "\n";
}

// ------------------------------------------------------------------
// Patch autoload_static.php
// ------------------------------------------------------------------
$st = (string) \file_get_contents($static);
$stMut = false;
$anchorSt = "        'OCA\\\\SouveraMail\\\\Sieve\\\\MiniInterpreter'";
foreach ($SHIMS as $cls => $shim) {
    $marker = "'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}'";
    $wrong   = "        'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}' => __DIR__ . '/../..' . '/lib/Sieve/Types.php',";
    $correct = $makeStLine($cls, $shim);
    if (\str_contains($st, $wrong)) {
        $st = \str_replace($wrong, $correct, $st);
        $stMut = true;
        continue;
    }
    if (\str_contains($st, $correct)) {
        continue;
    }
    if (\str_contains($st, $marker)) {
        continue;
    }
    if (\str_contains($st, $anchorSt)) {
        $st = \str_replace(
            $anchorSt,
            $correct . "\n" . $anchorSt,
            $st
        );
        $stMut = true;
    }
}
if ($stMut) {
    \file_put_contents($static, $st);
    echo "[fix-classmap] patched " . \basename($static) . "\n";
}

exit(0);
