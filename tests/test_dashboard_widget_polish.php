<?php
/**
 * Regression pins for v0.14.22 — Dashboard-Widget Polish:
 * ---------------------------------------------------------------
 *  1. Widget title dropped "· Inbox" suffix ("Souvera Mail" only).
 *  2. Default mode switched from MODE_UNREAD → MODE_ALL so the
 *     widget shows recent mail (not just unread) by default.
 *  3. Empty-state text moved from `halfEmptyContentMessage` (3rd
 *     WidgetItems arg) to `emptyContentMessage` (2nd arg) so
 *     NcEmptyContent renders it centred — the previous placement
 *     never showed the text at all when the item list was empty.
 *  4. Empty-state text updated to "Your mailbox is currently
 *     empty" / "Ihre Mailbox ist derzeit leer" (DE).
 *  5. `load()` registers both `css/dashboard-widget.css` and
 *     `js/dashboard-widget-enhancer.js` so the widget icon
 *     inverts per theme (Light → black, Dark → white for widget;
 *     Light → white, Dark → black for App-Menu / Nav) and the
 *     Souvera-Shield-style ✓ checkmark is injected into the
 *     NcEmptyContent icon slot on empty inbox.
 *
 *  Reference: operator screenshot 2026-02-19 comparing our
 *  widget with the Souvera Shield "Mail-Quarantäne" widget.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$widgetPath = '/app/lib/Dashboard/UnreadMailWidget.php';
ok(is_file($widgetPath), "UnreadMailWidget.php exists", $passes, $failures);
$w = (string) file_get_contents($widgetPath);

// ---------------------------------------------------------------
// 1. Title cleaned up.
// ---------------------------------------------------------------
ok((bool) preg_match(
    "#public function getTitle\(\)[\s\S]{0,400}return \\\$this->l10n->t\('Souvera Mail'\)#",
    $w
), "getTitle() returns L10N-wrapped 'Souvera Mail' (no '· Inbox' suffix)",
    $passes, $failures);

ok(!str_contains($w, "'Souvera Mail · Inbox'"),
    "regression pin: title no longer contains 'Souvera Mail · Inbox'",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. Default mode flipped to MODE_ALL.
// ---------------------------------------------------------------
ok((bool) preg_match(
    "#public const MODE_DEFAULT\s*=\s*self::MODE_ALL#",
    $w
), "MODE_DEFAULT === MODE_ALL (widget now shows all recent mail by default)",
    $passes, $failures);

ok(str_contains($w, "public const MODE_UNREAD = 'unread'")
    && str_contains($w, "public const MODE_ALL = 'all'"),
    "MODE_UNREAD + MODE_ALL constants still exposed (personal setting still works)",
    $passes, $failures);

// ---------------------------------------------------------------
// 3 + 4. Empty-state — text in emptyContentMessage (2nd arg).
// ---------------------------------------------------------------
ok((bool) preg_match(
    "#return new WidgetItems\(\[\],\s*\\\$empty,\s*''\)#",
    $w
), "empty-state uses `new WidgetItems([], \$empty, '')` — text in 2nd arg (emptyContentMessage), not 3rd",
    $passes, $failures);

ok(!(bool) preg_match(
    "#return new WidgetItems\(\[\],\s*'',\s*\\\$empty\)#",
    $w
), "regression pin: NO WidgetItems([], '', \$empty) — that placement never rendered",
    $passes, $failures);

ok(str_contains($w, "'Your mailbox is currently empty'"),
    "empty-mode-ALL uses L10N key 'Your mailbox is currently empty'",
    $passes, $failures);

// German translation present
$de = (string) file_get_contents('/app/l10n/de.json');
ok(str_contains($de, '"Your mailbox is currently empty": "Ihre Mailbox ist derzeit leer"'),
    "l10n/de.json translates 'Your mailbox is currently empty' → 'Ihre Mailbox ist derzeit leer'",
    $passes, $failures);
$deJs = (string) file_get_contents('/app/l10n/de.js');
ok(str_contains($deJs, '"Your mailbox is currently empty" : "Ihre Mailbox ist derzeit leer"'),
    "l10n/de.js has the same translation (client-side)",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. load() registers the enhancer bundle.
// ---------------------------------------------------------------
ok((bool) preg_match(
    "#public function load\(\)[\s\S]{0,2000}Util::addStyle\('souvera_mail',\s*'dashboard-widget'\)#",
    $w
), "load() registers css/dashboard-widget.css via Util::addStyle",
    $passes, $failures);

ok((bool) preg_match(
    "#public function load\(\)[\s\S]{0,2000}Util::addScript\('souvera_mail',\s*'dashboard-widget-enhancer'\)#",
    $w
), "load() registers js/dashboard-widget-enhancer.js via Util::addScript",
    $passes, $failures);

// ---------------------------------------------------------------
// 6. Frontend assets exist + contain the right rules.
// ---------------------------------------------------------------
$css = (string) @file_get_contents('/app/css/dashboard-widget.css');
ok($css !== '', "css/dashboard-widget.css exists and is non-empty", $passes, $failures);

// Widget icon: dark-mode invert rule
ok((bool) preg_match(
    '#body\[data-theme-dark\][^{]*img\[src\*="[^"]*souvera_mail[^"]*"\][\s\S]{0,200}filter\s*:\s*invert\(1\)#',
    $css
), "CSS inverts widget <img> icon in dark mode (body[data-theme-dark] + filter: invert(1))",
    $passes, $failures);

// v0.14.25 — App-Menu icon: NC handles this automatically via mask-
// image once the SVG is fully monochrome (`fill="currentColor"`).
// The old `background-color !important` + `filter: invert(1)`
// overrides from v0.14.22-24 were counter-productive:
//   • The `<g fill="#000000">` in app.svg made NC's monochrome
//     detector classify our icon as COLORED, so it rendered as a
//     plain black <img> instead of a themed mask (App-Popover
//     regression, 2026-02-19 operator screenshot).
//   • Our CSS override tried to fix that but hit only *some*
//     rendering paths and produced the "verdreht" effect.
// Now: SVG monochrome + no CSS override → NC auto-renders our icon
// exactly like every other Souvera app (white-in-blue in App-Popover).
ok(str_contains($css, 'HANDLED BY NC AUTOMATICALLY')
    || str_contains($css, 'App menu / Nav icon — HANDLED BY NC'),
    "CSS documents that App-Menu icon is now handled by NC automatically (no override)",
    $passes, $failures);

ok(!(bool) preg_match(
    '~\.app-menu-entry\[data-app-id="souvera_mail"\][\s\S]{0,400}background-color\s*:\s*\#(?:ffffff|000000)\s*!important~',
    $css
), "regression pin: no more `background-color !important` on .app-menu-entry (was breaking App-Popover)",
    $passes, $failures);

ok(!(bool) preg_match(
    '~\.app-menu-entry\[data-app-id="souvera_mail"\] img[\s\S]{0,300}filter\s*:\s*invert~',
    $css
), "regression pin: no more `filter: invert()` on .app-menu-entry img (redundant with monochrome SVG)",
    $passes, $failures);

// Empty-state checkmark styling
ok(str_contains($css, '.souvera-mail-widget-empty-icon'),
    "CSS provides styling for `.souvera-mail-widget-empty-icon` (checkmark wrapper)",
    $passes, $failures);

// Enhancer JS
$js = (string) @file_get_contents('/app/js/dashboard-widget-enhancer.js');
ok($js !== '', "js/dashboard-widget-enhancer.js exists", $passes, $failures);
ok(str_contains($js, "souvera_mail-unread"),
    "enhancer JS targets the widget id 'souvera_mail-unread'",
    $passes, $failures);
ok(str_contains($js, "empty-content__icon"),
    "enhancer JS injects into `.empty-content__icon` (NcEmptyContent slot)",
    $passes, $failures);
ok(str_contains($js, "MutationObserver"),
    "enhancer JS uses MutationObserver to catch Vue re-renders",
    $passes, $failures);
ok(str_contains($js, "data-souvera-check-injected"),
    "enhancer JS is idempotent via `data-souvera-check-injected` marker",
    $passes, $failures);
ok((bool) preg_match('#<path\s+d="M9 16\.17#', $js),
    "enhancer JS ships an inline checkmark SVG (no extra HTTP hop)",
    $passes, $failures);

// ---------------------------------------------------------------
// 7. Version + CHANGELOG regression.
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
$ver = $vm[1] ?? '0';
ok(version_compare($ver, '0.14.22', '>='),
    "info.xml <version> >= 0.14.22 (got: '$ver')",
    $passes, $failures);

$pkg = (string) file_get_contents('/app/package.json');
ok((bool) preg_match('#"version"\s*:\s*"' . preg_quote($ver, '#') . '"#', $pkg),
    "package.json version matches info.xml ($ver)",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
ok(str_contains($cl, '[0.14.22]'),
    "CHANGELOG has a [0.14.22] section",
    $passes, $failures);
ok((bool) preg_match('#0\.14\.22[\s\S]{0,1200}(?:emptyContent|checkmark|MODE_ALL|Souvera Shield|Mailbox)#i', $cl),
    "0.14.22 section mentions empty-state / MODE_ALL / checkmark / Shield-Look",
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
