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

// ---------------------------------------------------------------
// 1. No white fill anywhere in the SVG (regression against the 0.14.20
//    "invertiert" report). Cover common casings + spacings.
// ---------------------------------------------------------------
foreach ([
    'fill:#ffffff',
    'fill: #ffffff',
    'fill:#FFFFFF',
    'fill:white',
    'fill: white',
    'fill="#ffffff"',
    'fill="#FFFFFF"',
    'fill="white"',
] as $badPattern) {
    ok(!str_contains($svg, $badPattern),
        "img/app.svg does NOT contain '$badPattern' (invert regression pin)",
        $passes, $failures);
}

// ---------------------------------------------------------------
// 2. Every <path> must carry a currentColor fill. Extract each path
//    element, then verify its style attribute mentions currentColor.
// ---------------------------------------------------------------
preg_match_all('#<path\b[^>]*/?>#s', $svg, $pathMatches);
$paths = $pathMatches[0] ?? [];
ok(count($paths) >= 2,
    "img/app.svg has >=2 <path> elements (got " . count($paths) . ")",
    $passes, $failures);

$idx = 0;
foreach ($paths as $pathTag) {
    $idx++;
    // Match `style="…fill:currentColor…"` OR `fill="currentColor"` OR
    // no fill at all (cascades from group). All three are safe.
    $hasCurrent = str_contains($pathTag, 'fill:currentColor')
        || str_contains($pathTag, 'fill: currentColor')
        || str_contains($pathTag, 'fill="currentColor"');
    $noExplicitFill = !preg_match('#fill\s*[:=]#', $pathTag);
    ok($hasCurrent || $noExplicitFill,
        "path #$idx uses currentColor OR cascades from group (no hard-coded colour)",
        $passes, $failures);
}

// ---------------------------------------------------------------
// 3. Group cascade safety net — parent <g> should still have a dark
//    fill so <img>-rendering without CSS color context stays visible.
// ---------------------------------------------------------------
ok((bool) preg_match('~<g\b[^>]*fill\s*=\s*"#000000"~', $svg),
    "parent <g> retains fill=\"#000000\" as safety-net cascade for <img> rendering",
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
    "#getIconUrl\(\)[\s\S]{0,400}urlGenerator->imagePath\(\s*'souvera_mail'\s*,\s*'app\.svg'\s*\)#",
    $widget
), "UnreadMailWidget::getIconUrl() serves 'app.svg' via imagePath()",
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
