<?php
/**
 * v0.17.3 — Regression pin for the /embed top-gap layout fix.
 *
 * Operator report to v0.17.2 (translated):
 *   „The /embed route works, but there's an empty gap at the top of the
 *    screen where the Nextcloud menu used to be. The mail client should
 *    sit flush against the top edge of the window."
 *
 * Root cause: Nextcloud's `base` layout (core/templates/layout.base.php)
 * wraps our template output in
 *     <body id="body-public" class="layout-base">
 *       <div id="content" class="app-public" role="main"> … </div>
 *     </body>
 * NC's `core/css/server.css` gives `#content.app-public` a top offset
 * (padding-top / margin-top ≈ 50px) that WOULD have left room for the
 * app header rendered by `layout.user.php`. Because we chose `base`,
 * the header is absent — but the reserved space is not, so the mail
 * UI sits ~50px below the viewport top. On a WebView wrapper this
 * looks like an "empty white bar at the top".
 *
 * Fix: /app/css/standalone.css now zeroes every top-offsetting
 * dimension on `#content` and `#content.app-public` (both scoped
 * under `body.layout-base` AND `#body-public` because different NC
 * builds have picked different combinations), plus explicitly hides
 * `#skip-actions` and `#initial-state-container` (they should be
 * hidden already but older themes forgot the reset).
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

$css = (string) \file_get_contents('/app/css/standalone.css');
$a($css !== '', 'standalone.css exists and is readable');

// -------------------------------------------------------------------
// 1. Body-level foundation is still there (regression guard for
//    everything v0.17.0 added).
// -------------------------------------------------------------------
$a((bool) \preg_match('#\bbody\s*\{[^}]*padding:\s*0\s*!important#', $css),
    'body { padding: 0 !important } still present (v0.17.0 baseline)');

// -------------------------------------------------------------------
// 2. THE fix — `#content.app-public` shell must be neutralised.
//    We check every offsetting property because NC's core CSS spreads
//    them across a handful of selectors (padding-top, margin-top, top).
// -------------------------------------------------------------------
$a(\str_contains($css, 'body.layout-base #content'),
    'targets `body.layout-base #content` (NC ≥ 30 body class)');
$a(\str_contains($css, '#body-public #content'),
    'targets `#body-public #content` (older NC body-id)');
$a(\str_contains($css, '.app-public'),
    'targets `.app-public` variant (base layout applies this class)');

// Extract the `#content` overriding block(s) and assert each critical
// property is zeroed with !important. We slice from the first mention
// of body.layout-base #content down to the next top-level selector.
$blockOffset = \strpos($css, 'body.layout-base #content');
$a($blockOffset !== false, 'located #content override block in standalone.css');
$block = $blockOffset !== false ? \substr($css, (int) $blockOffset, 1200) : '';

foreach (
    [
        'padding: 0 !important'      => 'zero padding',
        'margin: 0 !important'       => 'zero margin',
        'top: 0 !important'          => 'top: 0',
        'border-radius: 0 !important'=> 'zero border-radius (no NC rounded shell)',
        'height: 100vh !important'   => 'full-viewport height',
    ] as $needle => $desc
) {
    $a(\str_contains($block, $needle),
        "#content override includes {$desc}: `{$needle}`");
}

// -------------------------------------------------------------------
// 3. #skip-actions / #initial-state-container hidden (NC injects them
//    before our content — hidden in the DOM, but some legacy themes
//    reset display:block).
// -------------------------------------------------------------------
$a((bool) \preg_match(
    '#\#skip-actions[^{]*\{[^}]*display:\s*none\s*!important#s',
    $css
), '#skip-actions is force-hidden');
$a((bool) \preg_match(
    '#\#initial-state-container[^{]*\{[^}]*display:\s*none\s*!important#s',
    $css
), '#initial-state-container is force-hidden');

// -------------------------------------------------------------------
// 4. #x2m-app still covers the viewport (no regression from the
//    property additions). We now also assert `top: 0 !important` and
//    `left: 0 !important` because `inset: 0` alone can lose to a NC
//    server.css rule with matching specificity.
// -------------------------------------------------------------------
$xOffset = \strpos($css, '#x2m-app {');
$a($xOffset !== false, 'located #x2m-app rule in standalone.css');
$xBlock = $xOffset !== false ? \substr($css, (int) $xOffset, 800) : '';
foreach (
    [
        'position: fixed'         => 'fixed positioning',
        'inset: 0'                => 'inset:0 shorthand',
        'top: 0 !important'       => 'explicit top:0 !important',
        'left: 0 !important'      => 'explicit left:0 !important',
        'width: 100vw'            => 'full-viewport width',
        'height: 100vh'           => 'full-viewport height',
        'margin: 0 !important'   => '!important on margin (defence-in-depth)',
        'padding: 0 !important'  => '!important on padding (defence-in-depth)',
    ] as $needle => $desc
) {
    $a(\str_contains($xBlock, $needle),
        "#x2m-app rule includes {$desc}: `{$needle}`");
}

// -------------------------------------------------------------------
// 5. Old SSO-mode UX guards (logout/auto-logout hidden) must still
//    exist — the CSS overhaul above must NOT have deleted them.
// -------------------------------------------------------------------
$a(\str_contains($css, '#x2m-app [data-bind*="logoutClick"]'),
    'SSO-mode logout button still hidden (regression guard from embed.css port)');
$a(\str_contains($css, 'SETTINGS_SECURITY/LABEL_AUTOLOGOUT'),
    'auto-logout setting still hidden (SSO managed by NC)');
$a(\str_contains($css, '#V-AdminPane .btn-logout'),
    'admin-pane logout still hidden');

// -------------------------------------------------------------------
// 6. PageController path is unchanged (renderAs('base') still forced
//    for the embed route). If someone silently reverts this, the CSS
//    fix does nothing because `#content.app-public` never appears
//    without base layout.
// -------------------------------------------------------------------
$pc = (string) \file_get_contents('/app/lib/Controller/PageController.php');
$a(\str_contains($pc, "renderAs('base')"),
    'PageController still uses renderAs(\'base\') when isStandalone is true');
$a(\str_contains($pc, "addStyle('souvera_mail', 'standalone')"),
    'PageController still injects the standalone stylesheet for embed mode');

// -------------------------------------------------------------------
// 7. Version bump — 0.17.3 or higher.
// -------------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$a((bool) \preg_match('#<version>0\.(?:1[7-9]|[2-9]\d)\.(?:[3-9]|\d\d+)</version>#', $info)
   || (bool) \preg_match('#<version>0\.(?:1[8-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.17.3 or higher');

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
