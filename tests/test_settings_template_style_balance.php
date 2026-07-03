<?php
/**
 * Regression test — SettingsSouveraAccount.html must contain EXACTLY
 * one <style>…</style> pair.
 *
 * Real-world incident (2026-02-18): a copy-paste during the v0.14.0
 * app-password UI rewrite left a duplicated CSS block AFTER the
 * closing </style>, which the browser then rendered as visible text
 * on the Souvera account settings page (screenshot: raw
 * `body[data-theme-dark] .souvera-settings .sv-pill-muted { … }`
 * appearing below the "Verbundene Geräte" section).
 *
 * A single string count is a much cheaper safety net than debugging
 * a visual regression again — this test fails if a future refactor
 * introduces a second <style> block accidentally.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$path = '/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html';
$html = (string) file_get_contents($path);
ok($html !== '', "SettingsSouveraAccount.html readable", $passes, $failures);

$openTags = \substr_count($html, '<style');
$closeTags = \substr_count($html, '</style>');
ok($openTags === 1,
    "Template contains exactly ONE <style> opening tag (found: {$openTags})",
    $passes, $failures);
ok($closeTags === 1,
    "Template contains exactly ONE </style> closing tag (found: {$closeTags})",
    $passes, $failures);

// The opening tag MUST appear before the closing tag (guards against
// pathological orderings like </style>...<style>).
$openPos  = \strpos($html, '<style');
$closePos = \strpos($html, '</style>');
ok($openPos !== false && $closePos !== false && $openPos < $closePos,
    "<style> opens BEFORE </style> closes", $passes, $failures);

// Nothing between the closing </style> and end-of-file except optional
// whitespace / a trailing newline. Anything else would render as text.
$tail = \substr($html, $closePos + \strlen('</style>'));
ok(\trim($tail) === '',
    "Nothing renders after the closing </style> (found: '"
    . \substr(\trim($tail), 0, 60) . "...')",
    $passes, $failures);

// Sanity: the same CSS selector block ('.sv-pill-muted' dark variant)
// must not appear more than twice — once in the light-theme block,
// once in the dark-theme block. Three or more copies = a copy-paste
// leak like the one we just fixed.
$sel = 'body[data-theme-dark] .souvera-settings .sv-pill-muted';
$count = \substr_count($html, $sel);
ok($count === 1,
    "Dark-theme sv-pill-muted selector appears exactly once (found: {$count} — was 2 before the v0.14.3 fix)",
    $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n"; foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
