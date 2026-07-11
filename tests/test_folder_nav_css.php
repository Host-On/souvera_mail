<?php
/**
 * Tests for v0.14.20 — Nextcloud-Sidebar-Look for the Snappymail folder tree.
 *
 * The operator (2026-02-19) sent two Shield/Files screenshots side by side
 * with the Souvera Mail sidebar and asked: "make the Posteingang / Gesendet
 * / … list look exactly like this — vertical blue bar on the left of the
 * selected item, light-blue background, rounded pill".
 *
 * This test pins:
 *   1. The new CSS file exists at the expected path.
 *   2. It's registered by the Snappymail Nextcloud plugin via addCss().
 *   3. It targets the correct engine selectors (`.b-folders li a`, the
 *      `.selectable.selected` state, hover, drop-target, unread bubble).
 *   4. It uses Nextcloud CSS custom properties (--color-primary-element,
 *      --color-primary-element-light, --color-background-hover, …) so
 *      the sidebar automatically follows NC theming (light/dark/HC).
 *   5. It sets a 4px left accent bar on the selected item and paints
 *      the background with the light-blue NC tint.
 *   6. Version was bumped and CHANGELOG has a matching section.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. CSS file exists
// ---------------------------------------------------------------
$cssPath = '/app/app/smail/v/current/app/plugins/nextcloud/css/folder-nav.css';
assertTrue(is_file($cssPath), "folder-nav.css exists at $cssPath", $passes, $failures);
$css = (string) file_get_contents($cssPath);

// ---------------------------------------------------------------
// 2. Plugin registration
// ---------------------------------------------------------------
$plugin = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
assertTrue(str_contains($plugin, "\$this->addCss('css/folder-nav.css')"),
    "plugin Init() registers css/folder-nav.css via addCss()",
    $passes, $failures);

// help-modal.css must still be registered (regression against accidental removal)
assertTrue(str_contains($plugin, "\$this->addCss('css/help-modal.css')"),
    "plugin still registers css/help-modal.css (no accidental swap)",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Targets the correct engine selectors
// ---------------------------------------------------------------
$selectorContract = [
    '.b-folders'                            => 'sidebar container root',
    '.b-folders li a'                       => 'row base',
    '.b-folders li a.selectable:hover'      => 'hover state',
    '.b-folders li a.selectable.selected'   => 'selected state',
    '.b-folders li a.selectable.droppableHover' => 'drag-drop hover state',
    '.b-folders .b-folders-system'          => 'system-folder block',
    '.b-folders hr'                         => 'divider between system + user folders',
    '.b-folders .b-toolbar'                 => 'compose/contacts toolbar',
    '.b-folders .e-checkbox'                => 'only-unread checkbox row',
    '.b-folders .search-input-wrp'          => 'folder-search wrapper',
];
foreach ($selectorContract as $sel => $desc) {
    assertTrue(str_contains($css, $sel),
        "folder-nav.css scopes selector '$sel' ($desc)",
        $passes, $failures);
}

// Explicit rule for the unread-count bubble in both system + user folders
assertTrue(str_contains($css, ".b-folders .b-folders-system a[data-unread]::after")
        && str_contains($css, ".b-folders .b-folders-user a[data-unread]:not(.system)::after"),
    "folder-nav.css repaints both system + user unread bubbles",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. Nextcloud CSS variables used (with fallbacks)
// ---------------------------------------------------------------
$ncVars = [
    '--color-primary-element',        // selected accent + unread bubble
    '--color-primary-element-light',  // selected background
    '--color-background-hover',       // hover pill
    '--color-main-text',              // row text
    '--color-border',                 // divider
    '--border-radius',                // pill corner radius (v0.14.35: was --border-radius-large in 0.14.20-0.14.34, reduced so the inset-shadow bar covers the full pill height)
];
foreach ($ncVars as $var) {
    assertTrue(str_contains($css, "var($var"),
        "folder-nav.css uses NC CSS variable var($var) (with fallback)",
        $passes, $failures);
}

// Every var() must ship with a fallback so the sidebar still looks right
// outside of a fully themed NC page. Bar length limit keeps the pin
// resilient to minor formatting tweaks.
$varUsages = [];
if (preg_match_all('#var\((--[a-z0-9-]+)(?:\s*,\s*[^)]+)?\)#i', $css, $m)) {
    foreach ($m[0] as $expr) {
        $varUsages[] = $expr;
    }
}
$noFallback = array_filter($varUsages, fn($u) => !str_contains($u, ','));
assertTrue(empty($noFallback),
    "every var(--nc-*) usage ships with a fallback value (defensive against un-themed hosts). offenders: "
    . implode(', ', $noFallback),
    $passes, $failures);

// ---------------------------------------------------------------
// 5. The money selectors — selected state = fully-rounded blue pill
// (v0.14.33: NO left accent bar; pill is fully rounded on BOTH sides.
// Earlier iterations (0.14.20 → 0.14.29) used a 4-px left border +
// right-only rounded corners; the operator asked to match the Central
// admin sidebar which uses the fully-rounded floating pill without a
// bar. Regex tolerates the combined `.selectable.selected, .selected`
// comma-list shipped since 0.14.29.)
// ---------------------------------------------------------------
assertTrue((bool) preg_match(
    '#\.b-folders li a\.selectable\.selected[\s\S]{0,200}\{[^}]*background-color\s*:\s*var\(--color-primary-element-light#s',
    $css
), "selected row uses --color-primary-element-light as background",
    $passes, $failures);

// v0.14.34: NO 4-px `border-left` accent bar on the selected row
// (would kill the rounded left corner). The bar is now painted via
// `box-shadow: inset 4px 0 0 …` which respects the pill's radius.
assertTrue(
    !(bool) preg_match(
        '#\.b-folders li a(?:\.selectable)?\.selected[\s\S]{0,300}\{[^}]*border-left\s*:\s*4px\s+solid#s',
        $css
    ),
    "selected row does NOT paint a border-left 4-px accent bar (v0.14.33 removed it)",
    $passes, $failures);

// v0.14.35: the vertical bar IS painted, but as an inset box-shadow
// so it follows the pill's rounded corners. Bar is 5 px wide (was
// 4 px in 0.14.34) so it fills the pill height even with rounded
// corners — matches Central-App reference.
assertTrue((bool) preg_match(
    '#\.b-folders li a(?:\.selectable)?\.selected[\s\S]{0,300}\{[^}]*box-shadow\s*:\s*inset\s+5px\s+0\s+0\s+0?\s*var\(--color-primary-element#s',
    $css
), "selected row paints a 5-px NC-primary bar via `box-shadow: inset 5px 0 …` (follows the pill radius)",
    $passes, $failures);

// v0.14.33: selected label + icon paint in NC-primary blue so the
// pill has clear focus for colour-blind users too.
assertTrue((bool) preg_match(
    '#\.b-folders li a(?:\.selectable)?\.selected[\s\S]{0,300}\{[^}]*color\s*:\s*var\(--color-primary-element#s',
    $css
), "selected row label paints in --color-primary-element (v0.14.33 pill accent)",
    $passes, $failures);

// v0.14.33: base row zeros the left border (no phantom bar breaking
// the rounded left corner) and gives the pill 44 px line-height + 2/8
// margin on BOTH sides.
assertTrue((bool) preg_match(
    '#\.b-folders li a\s*\{[^}]*border-left\s*:\s*0#s',
    $css
), "base .b-folders li a zeroes border-left (v0.14.33 pill baseline)",
    $passes, $failures);

assertTrue((bool) preg_match(
    '#\.b-folders li a\s*\{[^}]*line-height\s*:\s*44px#s',
    $css
), "base row uses 44px line-height (NC Files sidebar baseline)",
    $passes, $failures);

assertTrue((bool) preg_match(
    '#\.b-folders li a\s*\{[^}]*margin\s*:\s*2px\s+8px\s*;#s',
    $css
), "base row margin is 2px 8px on BOTH sides (pill floats inside sidebar)",
    $passes, $failures);

// Fully-rounded pill on both sides — the money look. v0.14.35 uses
// `--border-radius` (8 px) instead of `--border-radius-large` (12 px)
// so the inset-shadow bar isn't swallowed by the top/bottom corners.
assertTrue((bool) preg_match(
    '#\.b-folders li a\s*\{[^}]*border-radius\s*:\s*var\(--border-radius(?!-large)#s',
    $css
), "base row is rounded with --border-radius (8 px, Central-App parity — v0.14.35 reduced from --border-radius-large)",
    $passes, $failures);

// Hover pill background — NC grey, not the engine's dark default
assertTrue((bool) preg_match(
    '#\.b-folders li a\.selectable:hover\s*\{[^}]*background-color\s*:\s*var\(--color-background-hover#s',
    $css
), "hover pill uses --color-background-hover (light grey NC pill, not engine dark)",
    $passes, $failures);

// Unread bubble matches NC primary colour (not engine's grey)
assertTrue((bool) preg_match(
    '#a\[data-unread\](?:[^{]*::after|::after)\s*\{[^}]*background-color\s*:\s*var\(--color-primary-element#s',
    $css
), "unread bubble background painted with --color-primary-element",
    $passes, $failures);

// ---------------------------------------------------------------
// 6. Version bump + CHANGELOG regression
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
$ver = $vm[1] ?? '0';
assertTrue(version_compare($ver, '0.14.20', '>='),
    "info.xml <version> >= 0.14.20 (got: '$ver')",
    $passes, $failures);

$pkg = (string) file_get_contents('/app/package.json');
assertTrue((bool) preg_match('#"version"\s*:\s*"' . preg_quote($ver, '#') . '"#', $pkg),
    "package.json version matches info.xml ($ver)",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($cl, '[0.14.20]'),
    "CHANGELOG has a [0.14.20] section",
    $passes, $failures);
assertTrue((bool) preg_match('#0\.14\.20[\s\S]{0,600}(?:folder-nav|Sidebar|Ordner)#i', $cl),
    "0.14.20 section mentions folder-nav / Sidebar / Ordner",
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
