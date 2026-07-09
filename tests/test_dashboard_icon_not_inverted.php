<?php
/**
 * Regression pin for v0.14.21 — Dashboard-Widget-Icon nicht mehr invertiert.
 *
 * Operator (2026-02-19):
 * > "Das Dashboard Widget zeigt leider das Mail Icon Inverted an,
 * > das sollte nicht so sein."
 *
 * Root cause: `img/app.svg` group has `fill="#000000"` on the parent
 * `<g>` (Step 22, v0.13.12 — "currentColor-only so NC theming picks it
 * up") BUT the two `<path>` children carry inline `style="fill:#ffffff"`
 * which override the group cascade. NC's Dashboard uses `<img>` to render
 * `IIconWidget::getIconUrl()`, so `currentColor` on <img> falls back to
 * `black` — but our inline `#ffffff` short-circuited that, painting the
 * paths pure white on a white widget header ⇒ invisible / "invertiert".
 *
 * Fix contract (locked here):
 *   - No `<path>` inside `img/app.svg` carries `fill:#ffffff` inline
 *     (in any casing / spacing).
 *   - Every `<path>` uses `fill:currentColor` for maximum compat:
 *       • `<img>` fallback → black
 *       • NC nav `mask-image` → alpha-driven (fill irrelevant, still works)
 *       • Inline SVG (future) → inherits CSS `color`
 *   - Group `fill="#000000"` cascade remains as safety net.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$svgPath = '/app/img/app.svg';
ok(is_file($svgPath), "img/app.svg exists", $passes, $failures);
$svg = (string) file_get_contents($svgPath);

// v0.14.27: Widget uses a SEPARATE SVG so the two rendering pipelines
// can never trip over each other again (menu-icon vs widget-icon).
$widgetSvgPath = '/app/img/app-widget.svg';
ok(is_file($widgetSvgPath),
    "img/app-widget.svg exists (dedicated widget icon, v0.14.27 split)",
    $passes, $failures);
$widgetSvg = (string) file_get_contents($widgetSvgPath);
ok(str_contains($widgetSvg, 'fill="#000000"')
    && str_contains($widgetSvg, 'fill:#000000'),
    "img/app-widget.svg uses hard-coded fill=#000000 (widget needs black on light-mode header)",
    $passes, $failures);
ok(!str_contains($widgetSvg, 'fill:#ffffff')
    && !str_contains($widgetSvg, 'fill="#ffffff"'),
    "img/app-widget.svg does NOT contain any #ffffff fill (regression pin against reverting to white)",
    $passes, $failures);

// ---------------------------------------------------------------
// v0.14.26 rewrite: the SVG now ships with hard-coded `#ffffff` on
// every path AND on the parent <g>. This matches every other Souvera
// app icon so NC's App-Popover renders our icon white-in-blue like
// the rest. NC's default dark-mode invert-filter then flips it to
// black on the dark-mode popover — again identical to sister apps.
//
// The Dashboard widget's inverse rendering (Light→black, Dark→white)
// is driven by `.panel--header`-scoped CSS filters in
// css/dashboard-widget.css. This test only pins the SVG contract.
// ---------------------------------------------------------------

// 1. Every <path> MUST use fill:#ffffff (opposite of v0.14.21-25).
foreach ([
    'fill:currentColor',
    'fill: currentColor',
    'fill="currentColor"',
    'fill:#000000',
    'fill: #000000',
    'fill="#000000"',
    'fill:black',
    'fill="black"',
] as $badPattern) {
    // <g> is allowed to keep currentColor cascade until we swap; the
    // patterns we forbid are on <path> only. Extract paths first.
}

preg_match_all('#<path\b[^>]*/?>#s', $svg, $pathMatches);
$paths = $pathMatches[0] ?? [];
ok(count($paths) >= 2,
    "img/app.svg has >=2 <path> elements (got " . count($paths) . ")",
    $passes, $failures);

$idx = 0;
foreach ($paths as $pathTag) {
    $idx++;
    $hasWhite = str_contains($pathTag, 'fill:#ffffff')
        || str_contains($pathTag, 'fill: #ffffff')
        || str_contains($pathTag, 'fill="#ffffff"');
    ok($hasWhite,
        "path #$idx uses hard-coded fill:#ffffff (matches sister-app render pipeline)",
        $passes, $failures);
    // Regression pin against v0.14.21-25 currentColor artefact
    ok(!str_contains($pathTag, 'fill:currentColor')
        && !str_contains($pathTag, 'fill: currentColor')
        && !str_contains($pathTag, 'fill="currentColor"'),
        "path #$idx does NOT use currentColor (that produced black-in-blue popover — v0.14.21-25 regression)",
        $passes, $failures);
}

// 2. Group cascade — v0.14.26.
ok((bool) preg_match('~<g\b[^>]*fill\s*=\s*"#ffffff"~i', $svg),
    "parent <g> uses fill=\"#ffffff\" (matches sister-app pipeline)",
    $passes, $failures);
ok(!(bool) preg_match('~<g\b[^>]*fill\s*=\s*"currentColor"~i', $svg),
    "regression pin: parent <g> NO LONGER uses currentColor",
    $passes, $failures);
ok(!(bool) preg_match('~<g\b[^>]*fill\s*=\s*"#000000"~i', $svg),
    "regression pin: parent <g> NO LONGER uses hard-coded #000000",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. Dashboard widget wiring — the widget serves this exact file via
//    `IIconWidget::getIconUrl()` and `getIconClass()`. Pin both so a
//    future refactor doesn't silently point at a different asset.
// ---------------------------------------------------------------
$widget = (string) file_get_contents('/app/lib/Dashboard/UnreadMailWidget.php');
ok(str_contains($widget, 'implements IAPIWidgetV2, IIconWidget'),
    "UnreadMailWidget implements IIconWidget",
    $passes, $failures);
ok((bool) preg_match(
    "#getIconUrl\(\)[\s\S]{0,2000}urlGenerator->imagePath\(\s*'souvera_mail'\s*,\s*'app-widget\.svg'\s*\)#",
    $widget
), "UnreadMailWidget::getIconUrl() serves 'app-widget.svg' via imagePath() (v0.14.27 split from app.svg)",
    $passes, $failures);
ok((bool) preg_match(
    "#getIconClass\(\)[\s\S]{0,400}return\s+'icon-souvera-mail'#",
    $widget
) || (bool) preg_match(
    "#getIconClass\(\)[\s\S]{0,400}return\s+'#",
    $widget
), "UnreadMailWidget::getIconClass() returns a stable class name",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. Version + CHANGELOG regression pins.
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
$ver = $vm[1] ?? '0';
ok(version_compare($ver, '0.14.21', '>='),
    "info.xml <version> >= 0.14.21 (got: '$ver')",
    $passes, $failures);

$pkg = (string) file_get_contents('/app/package.json');
ok((bool) preg_match('#"version"\s*:\s*"' . preg_quote($ver, '#') . '"#', $pkg),
    "package.json version matches info.xml ($ver)",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
ok(str_contains($cl, '[0.14.21]'),
    "CHANGELOG has a [0.14.21] section",
    $passes, $failures);
ok((bool) preg_match('#0\.14\.21[\s\S]{0,800}(?:invert|Dashboard|currentColor|app\.svg)#i', $cl),
    "0.14.21 section mentions invert / Dashboard / currentColor / app.svg",
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
