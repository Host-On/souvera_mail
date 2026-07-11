<?php
/**
 * Regression pins for v0.14.30 — Quota UI (Sidebar-Bar + NC-Menu):
 * ---------------------------------------------------------------
 *  1. Snappymail sidebar bar (`quota.js`):
 *     - 5-minute refresh (was 60s in the top-right pill v0.13.x).
 *     - Warn threshold 80 %, Alert threshold 95 %.
 *     - Toast on ≥95 % via rl.Notification.
 *     - Injects a <div id="souvera-mail-quota-bar"> into `.b-folders`.
 *     - `data-quota-tier` attribute drives colour via CSS.
 *
 *  2. Snappymail sidebar bar CSS (`quota-bar.css`):
 *     - Bar sits at `.b-folders` bottom, uses NC theme vars.
 *     - Tier colours: primary (ok), warning (80-94), error (95+).
 *
 *  3. NC-Header user-menu entry (`nc-header-menu-quota.js` +
 *     `NcHeaderMenuQuotaListener`):
 *     - Loaded on every NC page render (BeforeTemplateRenderedEvent).
 *     - Only for authenticated users in `souvera-users` group.
 *     - Injects "Mail-Speicher: X / Y" into the header menu <ul>.
 *     - Non-clickable info entry (rationale in JS comment block).
 *
 *  Reference: operator request 2026-02-19 "Cool wäre auch ne Anzeige …"
 *  with follow-up preferences 1(a+c), 2(a), 3(b), 4(a+c), 5(a).
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. quota.js (sidebar bar)
// ---------------------------------------------------------------
$qjs = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/quota.js');
ok(str_contains($qjs, "const BAR_ID       = 'souvera-mail-quota-bar'"),
    "quota.js exposes BAR_ID = souvera-mail-quota-bar",
    $passes, $failures);
ok(str_contains($qjs, 'REFRESH_MS   = 5 * 60 * 1000'),
    "quota.js polls every 5 minutes (operator spec)",
    $passes, $failures);
ok(str_contains($qjs, 'WARN_THRESHOLD  = 80')
    && str_contains($qjs, 'ALERT_THRESHOLD = 95'),
    "quota.js uses 80/95 % colour thresholds",
    $passes, $failures);
ok(str_contains($qjs, "sidebar.appendChild(el)"),
    "quota.js appends the bar into the sidebar (NOT top-right pill anymore)",
    $passes, $failures);
ok((bool) preg_match('~el\.setAttribute\([\'"]data-quota-tier[\'"]\s*,\s*tier\)~', $qjs),
    "quota.js writes data-quota-tier attribute for CSS-driven colours",
    $passes, $failures);
ok(str_contains($qjs, 'showAlertToast'),
    "quota.js implements a showAlertToast() function for ≥95 %",
    $passes, $failures);
ok(str_contains($qjs, 'alertShown = false')
    && str_contains($qjs, 'alertShown = true'),
    "quota.js gates the alert toast to once per session (alertShown flag)",
    $passes, $failures);
ok(str_contains($qjs, "rl.Notification"),
    "quota.js prefers Snappymail's rl.Notification pipeline before DOM toast",
    $passes, $failures);
ok(str_contains($qjs, "data.formatted.used} verwendet"),
    "quota.js shows 'X verwendet' (no bar) for unlimited accounts (operator spec option a)",
    $passes, $failures);
// Regression pin: no top-right pill anymore
ok(!str_contains($qjs, "top:8px"),
    "regression pin: quota.js no longer injects a top:8px right:12px pill",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. quota-bar.css
// ---------------------------------------------------------------
$qcss = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/css/quota-bar.css');
ok($qcss !== '', "quota-bar.css exists", $passes, $failures);
ok(str_contains($qcss, '#souvera-mail-quota-bar'),
    "quota-bar.css scopes styles to #souvera-mail-quota-bar",
    $passes, $failures);
ok((bool) preg_match(
    '~\[data-quota-tier="warn"\][\s\S]{0,200}background-color\s*:\s*var\(--color-warning~',
    $qcss
), "quota-bar.css uses --color-warning for the warn tier (80-94 %)",
    $passes, $failures);
ok((bool) preg_match(
    '~\[data-quota-tier="alert"\][\s\S]{0,200}background-color\s*:\s*var\(--color-error~',
    $qcss
), "quota-bar.css uses --color-error for the alert tier (95+ %)",
    $passes, $failures);
ok((bool) preg_match(
    '~\[data-quota-mode="unlimited"\][\s\S]{0,200}\.quota-track[\s\S]{0,100}display\s*:\s*none~',
    $qcss
), "quota-bar.css hides the bar track for unlimited accounts",
    $passes, $failures);

// Plugin registers the CSS
$plugin = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
ok(str_contains($plugin, "\$this->addCss('css/quota-bar.css')"),
    "plugin index.php registers css/quota-bar.css via addCss()",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. NC header-menu entry
// ---------------------------------------------------------------
$listener = (string) file_get_contents('/app/lib/Listeners/NcHeaderMenuQuotaListener.php');
ok(str_contains($listener, 'class NcHeaderMenuQuotaListener'),
    "NcHeaderMenuQuotaListener class exists",
    $passes, $failures);
ok(str_contains($listener, 'BeforeTemplateRenderedEvent'),
    "Listener handles BeforeTemplateRenderedEvent",
    $passes, $failures);
ok(str_contains($listener, "\$this->groupManager->isInGroup(\$user->getUID(), Application::RESTRICTED_GROUP_ID)"),
    "Listener gates injection to souvera-users group",
    $passes, $failures);
ok(str_contains($listener, "\$this->quotaService->isAvailable()"),
    "Listener skips injection when QuotaService is not available",
    $passes, $failures);
ok(str_contains($listener, "\$this->urlGenerator->linkToRoute"),
    "Listener uses IURLGenerator to build the quota endpoint URL",
    $passes, $failures);
ok(str_contains($listener, "Util::addScript(Application::APP_ID, 'nc-header-menu-quota'"),
    "Listener registers js/nc-header-menu-quota.js via Util::addScript",
    $passes, $failures);

// JS loader
$hjs = (string) file_get_contents('/app/js/nc-header-menu-quota.js');
ok(str_contains($hjs, "'souvera-mail-quota-config'"),
    "nc-header-menu-quota.js reads endpoint from #souvera-mail-quota-config inline JSON",
    $passes, $failures);
ok(str_contains($hjs, 'MutationObserver'),
    "nc-header-menu-quota.js uses MutationObserver to catch lazy header-menu render",
    $passes, $failures);
ok(str_contains($hjs, "ENTRY_ID = 'souvera-mail-quota-menu-entry'"),
    "nc-header-menu-quota.js uses stable ENTRY_ID for idempotency",
    $passes, $failures);
ok(str_contains($hjs, "cursor:default"),
    "nc-header-menu-quota.js renders the entry as non-clickable (info only)",
    $passes, $failures);
ok(str_contains($hjs, "Mail-Speicher"),
    "nc-header-menu-quota.js labels the entry 'Mail-Speicher: …'",
    $passes, $failures);

// Application.php wires it up
$app = (string) file_get_contents('/app/lib/AppInfo/Application.php');
ok(str_contains($app, 'use OCA\SouveraMail\Listeners\NcHeaderMenuQuotaListener;'),
    "Application.php imports NcHeaderMenuQuotaListener",
    $passes, $failures);
ok((bool) preg_match(
    '#registerEventListener\(\s*BeforeTemplateRenderedEvent::class,\s*NcHeaderMenuQuotaListener::class\s*\)#s',
    $app
), "Application.php wires NcHeaderMenuQuotaListener on BeforeTemplateRenderedEvent",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. Version + CHANGELOG
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
$ver = $vm[1] ?? '0';
ok(version_compare($ver, '0.14.30', '>='),
    "info.xml <version> >= 0.14.30 (got: '$ver')",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
ok(str_contains($cl, '[0.14.30]'),
    "CHANGELOG has a [0.14.30] section",
    $passes, $failures);
ok((bool) preg_match('#0\.14\.30[\s\S]{0,1500}(?:Quota|Speicher|Sidebar-Bar|Header-Menu)#i', $cl),
    "0.14.30 section mentions the Quota feature",
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
