<?php
/**
 * Regression pins for v0.14.29 — Sidebar-Polish:
 * ---------------------------------------------------------------
 *  1. Blue accent bar on the SELECTED folder row is now `!important`
 *     because the engine's own `.b-folders li a { border-left: 3px
 *     solid transparent }` matches at equal specificity and clobbers
 *     our `border-left-color`-only rule in some NC-theme CSS load
 *     orders (2026-02-19 operator screenshot: hellblaue Pill
 *     sichtbar, aber KEIN Balken).
 *
 *  2. Unread-Rollup on collapsed namespace headers ("Geteilte
 *     Postfächer" etc.). Snappymail already tags parents-of-unread
 *     with `.unread-sub` — we turn that into a NC-blue bullet via a
 *     `::before` pseudo-element that only fires when the child <ul>
 *     is `.collapsed` (via `:has()`). Prevents the noise-double-badge
 *     when the tree is expanded.
 *
 *  3. Shared Folders namespace header text is translated to
 *     "Geteilte Postfächer" via the Knockout `folder.name()`
 *     observable (NOT the DOM selector, which never hit the
 *     `<!-- ko text: name -->` comment-syntax text node).
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$cssPath = '/app/app/smail/v/current/app/plugins/nextcloud/css/folder-nav.css';
$css = (string) file_get_contents($cssPath);

// ---------------------------------------------------------------
// 1. Selected row: v0.14.33 replaced the 4-px `border-left` accent
//    with a fully-rounded blue pill (Central-App parity — operator
//    screenshot 2026-02-19: "das sieht anders aus als bei den
//    anderen apps"). Regression guard: the OLD 4-px bar must be
//    GONE (would produce a phantom vertical line breaking the
//    rounded left corner). The NEW selected style must paint the
//    label in primary-blue so the pill has focus without a bar.
// ---------------------------------------------------------------
ok(
    !(bool) preg_match(
        '~\.b-folders li a\.selectable\.selected,?\s*\.b-folders li a\.selected\s*\{[\s\S]{0,400}border-left\s*:\s*4px solid var\(--color-primary-element~',
        $css
    ),
    "Selected folder row NO LONGER paints a 4-px left accent bar (v0.14.33 removed it)",
    $passes, $failures);

ok((bool) preg_match(
    '~\.b-folders li a\.selectable\.selected,?\s*\.b-folders li a\.selected\s*\{[\s\S]{0,400}background-color\s*:\s*var\(--color-primary-element-light~',
    $css
), "Selected folder row paints `background-color: var(--color-primary-element-light)` (Central-Pille)",
    $passes, $failures);

ok((bool) preg_match(
    '~\.b-folders li a\.selectable\.selected,?\s*\.b-folders li a\.selected\s*\{[\s\S]{0,400}color\s*:\s*var\(--color-primary-element[^-]~',
    $css
), "Selected folder row paints label + icon in `var(--color-primary-element)` (v0.14.33)",
    $passes, $failures);

ok(str_contains($css, '.b-folders li a.selected'),
    "Selector still covers plain `.selected` (user-sub-folders match)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. Unread-rollup bullet on collapsed namespace headers
// ---------------------------------------------------------------
ok(str_contains($css, '.b-folders li:has(> ul.collapsed) > a.unread-sub::before'),
    "CSS uses `li:has(> ul.collapsed) > a.unread-sub::before` for the rollup bullet",
    $passes, $failures);

ok((bool) preg_match(
    '~\.b-folders li:has\(> ul\.collapsed\) > a\.unread-sub::before\s*\{[\s\S]{0,600}background-color\s*:\s*var\(--color-primary-element~',
    $css
), "Rollup bullet uses `--color-primary-element` for the NC-blue dot",
    $passes, $failures);

ok((bool) preg_match(
    '~\.b-folders li:has\(> ul\.collapsed\) > a\.unread-sub::before\s*\{[\s\S]{0,600}border-radius\s*:\s*50%~',
    $css
), "Rollup bullet is a circle (border-radius: 50%)",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Shared Folders namespace translation via KO observable
// ---------------------------------------------------------------
$jsPath = '/app/app/smail/v/current/app/plugins/nextcloud/js/folder-names.js';
$js = (string) file_get_contents($jsPath);

ok((bool) preg_match(
    '~/\^shared\( folders\)\?\$/i\.test\(normalizedFN\)~',
    $js
), "folder-names.js matches 'Shared'/'Shared Folders' case-insensitively via regex",
    $passes, $failures);

ok(str_contains($js, 'folder.name(NAMESPACE_LABEL)'),
    "folder-names.js patches folder.name() with NAMESPACE_LABEL for the shared namespace",
    $passes, $failures);

ok(str_contains($js, "/^other users?$/i.test(normalizedFN)"),
    "folder-names.js also translates the 'Other Users' namespace when present",
    $passes, $failures);

// Translation file has the German strings
$langPath = '/app/app/smail/v/current/app/plugins/nextcloud/langs/de.json';
$de = (string) file_get_contents($langPath);
ok(str_contains($de, '"SHARED_NAMESPACE": "Geteilte Postfächer"'),
    "langs/de.json translates SHARED_NAMESPACE to 'Geteilte Postfächer'",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. Version + CHANGELOG
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
$ver = $vm[1] ?? '0';
ok(version_compare($ver, '0.14.29', '>='),
    "info.xml <version> >= 0.14.29 (got: '$ver')",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
ok(str_contains($cl, '[0.14.29]'),
    "CHANGELOG has a [0.14.29] section",
    $passes, $failures);
ok((bool) preg_match('#0\.14\.29[\s\S]{0,1000}(?:Sidebar|unread-sub|Balken|Namespace)#i', $cl),
    "0.14.29 section documents the sidebar polish",
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
