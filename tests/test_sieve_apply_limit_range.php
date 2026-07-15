<?php
declare(strict_types=1);

/**
 * v0.14.47 — Dispatcher `limit` hard-cap fix.
 *
 * Root cause (verified live on VM 292, nextcloud.log 2026-07-15):
 * NC34's Dispatcher::ensureParameterValueSatisfiesRange() rejects ANY
 * controller parameter literally named `limit` outside 1..500 BEFORE
 * the controller method runs — UNLESS the method docblock carries a
 * psalm int range that ControllerMethodReflector can parse.
 *
 * POST /sieve/apply sends limit=5000 → ParameterOutOfRangeException →
 * HTML error page → frontend "SyntaxError: JSON.parse…".
 *
 * This test replays NC's EXACT reflector regex + dispatcher logic
 * against our controller source, so a regression fails locally without
 * a live NC.
 */

$repo = \dirname(__DIR__);

$passes = 0;
$failures = [];
$check = static function (bool $cond, string $msg) use (&$passes, &$failures): void {
    if ($cond) { $passes++; } else { $failures[] = $msg; }
};

$ctrlSrc = (string) \file_get_contents($repo . '/lib/Controller/SieveApplyController.php');

// -------------------------------------------------------------------
// 1. Replay NC34 ControllerMethodReflector's psalm range regex
//    (lib/private/AppFramework/Utility/ControllerMethodReflector.php).
// -------------------------------------------------------------------
\preg_match(
    '/\/\*\*[\s\S]*?\*\/\s*#\[NoAdminRequired\]\s*public function apply\(/',
    $ctrlSrc,
    $m
);
$check(isset($m[0]), 'apply() has a docblock directly above it');
$docblock = $m[0] ?? '';

$ranges = [];
\preg_match_all(
    '/@(?:psalm-)?param\h+(\?)?(?P<type>\w+)<(?P<rangeMin>(-?\d+|min)),\h*(?P<rangeMax>(-?\d+|max))>(\|null)?\h+\$(?P<var>\w+)/',
    $docblock,
    $matches
);
foreach ($matches['var'] as $index => $varName) {
    if ($matches['type'][$index] !== 'int') { continue; }
    $ranges[$varName] = [
        'min' => $matches['rangeMin'][$index] === 'min' ? PHP_INT_MIN : (int) $matches['rangeMin'][$index],
        'max' => $matches['rangeMax'][$index] === 'max' ? PHP_INT_MAX : (int) $matches['rangeMax'][$index],
    ];
}

$check(isset($ranges['limit']),
    'NC reflector regex extracts a psalm int range for $limit — without it the 1..500 hard-cap applies');
$check(($ranges['limit']['min'] ?? 99) === 1,
    'range min is 1');
$check(($ranges['limit']['max'] ?? 0) >= 5000,
    'range max covers the JS payload of 5000');

// -------------------------------------------------------------------
// 2. Replay Dispatcher::ensureParameterValueSatisfiesRange with the
//    values the frontend actually sends.
// -------------------------------------------------------------------
$dispatcherAccepts = static function (int $value) use ($ranges): bool {
    $rangeInfo = $ranges['limit'] ?? null;
    if ($rangeInfo) {
        return !($value < $rangeInfo['min'] || $value > $rangeInfo['max']);
    }
    // NC fallback: hard-coded DEFAULT_MIN=1 / DEFAULT_MAX=500
    return !($value < 1 || $value > 500);
};
$check($dispatcherAccepts(5000), 'dispatcher accepts limit=5000 (the JS payload)');
$check($dispatcherAccepts(1), 'dispatcher accepts limit=1');
$check(!$dispatcherAccepts(0), 'dispatcher still rejects limit=0');
$check(!$dispatcherAccepts(5001), 'dispatcher still rejects limit=5001');

// -------------------------------------------------------------------
// 3. Frontend contract: JS sends 5000 (inside the annotated range) and
//    parses responses defensively (no bare r.json() on these calls).
// -------------------------------------------------------------------
$jsSrc = (string) \file_get_contents(
    $repo . '/app/smail/v/current/app/plugins/nextcloud/js/sieve-apply.js'
);
$check(\str_contains($jsSrc, 'limit: 5000'),
    'sieve-apply.js still sends limit: 5000');
$check(\str_contains($jsSrc, 'const safeJson'),
    'sieve-apply.js defines safeJson helper');
$check(!\preg_match('/then\(\s*r\s*=>\s*r\.json\(\)\s*\)/', $jsSrc),
    'sieve-apply.js has no bare r.json() left (HTML error pages must not surface as JSON.parse SyntaxError)');
$check(\substr_count($jsSrc, '.then(safeJson)') >= 2,
    'both fetches (folders + apply) route through safeJson');

// -------------------------------------------------------------------
// 4. Version sync 0.14.47.
// -------------------------------------------------------------------
$info = (string) \file_get_contents($repo . '/appinfo/info.xml');
$pkg = \json_decode((string) \file_get_contents($repo . '/package.json'), true);
$check(\str_contains($info, '<version>0.14.47</version>'), 'info.xml bumped to 0.14.47');
$check(($pkg['version'] ?? '') === '0.14.47', 'package.json bumped to 0.14.47');

// -------------------------------------------------------------------
if ($failures) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    echo \count($failures) . " failed, $passes passed\n";
    exit(1);
}
echo "ALL TESTS PASSED ($passes assertions)\n";
