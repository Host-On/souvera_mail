<?php
/**
 * Verifies the in-engine Snappymail Settings Tab introduced in
 * Souvera Mail 0.13.3 ("Sicherheit & Geräte").
 *
 * The old NC-chrome page at `/index.php/apps/souvera_mail/settings`
 * is replaced by a Snappymail-native Settings ViewModel registered
 * via `rl.addSettingsViewModel(...)` at hash route
 * `#/settings/souvera-account`. The previous URL keeps working as a
 * RedirectResponse to the new location.
 *
 * What this test covers
 * ---------------------
 * 1. SettingsController is now a `RedirectResponse` controller that
 *    points at the in-engine hash route.
 * 2. The new ViewModel JS file exists, registers itself with the
 *    Snappymail settings router, owns the "souvera-account" route,
 *    and references the `Sicherheit & Geräte` label.
 * 3. The Knockout HTML template exists and binds to the ViewModel's
 *    public observables.
 * 4. The engine plugin's `index.php` ships both files (`addJs` +
 *    `addTemplate`) and FilterAppData emits every URL the JS reads.
 * 5. The quota pill in `quota.js` now uses `target="_self"` and
 *    appends the engine hash route — single-tab navigation, no
 *    second browser tab.
 * 6. The old NC-chrome assets (`templates/settings.php`,
 *    `js/personal-settings.js`) are gone.
 * 7. info.xml version is bumped to 0.13.3.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void
{
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. SettingsController -> redirect
// ---------------------------------------------------------------
$sc = file_get_contents('/app/lib/Controller/SettingsController.php');
assertTrue(str_contains($sc, 'use OCP\\AppFramework\\Http\\RedirectResponse;'),
    "SettingsController imports OCP\\AppFramework\\Http\\RedirectResponse", $passes, $failures);
assertTrue(preg_match('#public function index\(\)\s*:\s*RedirectResponse#', $sc) === 1,
    "SettingsController::index() returns RedirectResponse", $passes, $failures);
assertTrue(str_contains($sc, '#/settings/souvera-account'),
    "SettingsController redirects to #/settings/souvera-account", $passes, $failures);
assertTrue(str_contains($sc, '#[NoAdminRequired]') && str_contains($sc, '#[NoCSRFRequired]'),
    "SettingsController retains #[NoAdminRequired] and #[NoCSRFRequired]", $passes, $failures);

// Old TemplateResponse-style code is gone
assertTrue(!str_contains($sc, 'TemplateResponse'),
    "SettingsController no longer references TemplateResponse", $passes, $failures);
assertTrue(!str_contains($sc, 'AppPasswordService'),
    "SettingsController no longer pulls AppPasswordService (now in engine plugin)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. settings-account.js (Knockout ViewModel registration)
// ---------------------------------------------------------------
$jsPath = '/app/app/smail/v/current/app/plugins/nextcloud/js/settings-account.js';
assertTrue(file_exists($jsPath), "settings-account.js exists", $passes, $failures);
$js = file_get_contents($jsPath);

assertTrue(str_contains($js, 'rl.addSettingsViewModel'),
    "JS calls rl.addSettingsViewModel(...) — Snappymail's plugin hook for new Settings tabs",
    $passes, $failures);
assertTrue(str_contains($js, "'SettingsSouveraAccount'"),
    "JS registers template 'SettingsSouveraAccount' (matches the HTML file basename)",
    $passes, $failures);
assertTrue(str_contains($js, 'Sicherheit'),
    "JS uses the 'Sicherheit' literal in the tab label", $passes, $failures);
assertTrue(str_contains($js, "'souvera-account'"),
    "JS registers under the 'souvera-account' hash route", $passes, $failures);

// All cfg URLs the JS expects from FilterAppData
foreach ([
    'SmailDashboardModeUrl',
    'SmailDashboardMode',
    'SmailAppPasswordsListUrl',
    'SmailAppPasswordsCreateUrl',
    'SmailAppPasswordsDestroyUrlTemplate',
    'SmailAppPasswordsAvailable',
    'SmailConnectedDevicesListUrl',
    'SmailConnectedDevicesDestroyUrlTemplate',
    'SmailConnectedDevicesSignOutOthersUrl',
] as $k) {
    assertTrue(str_contains($js, $k),
        "JS reads cfg.$k", $passes, $failures);
}

// CSRF header (the existing NC endpoints expect it)
assertTrue(str_contains($js, "'requesttoken'") || str_contains($js, '"requesttoken"'),
    "JS sets the Nextcloud CSRF requesttoken header", $passes, $failures);
assertTrue(str_contains($js, 'OC.requestToken'),
    "JS reads the request token from window.OC", $passes, $failures);

// URL template substitution
assertTrue(str_contains($js, ".replace('__ID__'"),
    "JS substitutes '__ID__' in destroy-URL templates", $passes, $failures);

// ---------------------------------------------------------------
// 3. SettingsSouveraAccount.html (Knockout template)
// ---------------------------------------------------------------
$tplPath = '/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html';
assertTrue(file_exists($tplPath), "SettingsSouveraAccount.html exists", $passes, $failures);
$tpl = file_get_contents($tplPath);

// Section headings (de)
foreach ([
    'Sicherheit &amp; Geräte',     // outer legend
    'Dashboard-Widget',            // section 1
    'App-Passwörter',              // section 2 (umlaut)
    'Verbundene Geräte',           // section 3
] as $needle) {
    assertTrue(str_contains($tpl, $needle),
        "Template contains section heading '$needle'", $passes, $failures);
}

// Knockout bindings against the public ViewModel observables
foreach ([
    'data-bind="checked: dashboardMode',
    'data-bind="text: justCreatedSecret',
    'foreach: appPasswords',
    'foreach: devices',
    'data-bind="click: createAppPassword',
    'data-bind="click: signOutOthers',
    'data-bind="visible: hasDevices()',
] as $needle) {
    assertTrue(str_contains($tpl, $needle),
        "Template has Knockout binding: " . substr($needle, 11) . "…", $passes, $failures);
}

// ---------------------------------------------------------------
// 4. Engine plugin ships JS + template + URLs
// ---------------------------------------------------------------
$plugin = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
assertTrue(str_contains($plugin, "addJs('js/settings-account.js')"),
    "Engine plugin loads js/settings-account.js", $passes, $failures);
assertTrue(str_contains($plugin, "addTemplate('templates/SettingsSouveraAccount.html')"),
    "Engine plugin registers templates/SettingsSouveraAccount.html", $passes, $failures);

// FilterAppData emits all URLs (already partly covered by other tests; here exhaustively)
foreach ([
    'SmailDashboardModeUrl',
    'SmailDashboardMode',
    'SmailAppPasswordsListUrl',
    'SmailAppPasswordsCreateUrl',
    'SmailAppPasswordsDestroyUrlTemplate',
    'SmailAppPasswordsAvailable',
    'SmailConnectedDevicesListUrl',
    'SmailConnectedDevicesDestroyUrlTemplate',
    'SmailConnectedDevicesSignOutOthersUrl',
] as $k) {
    assertTrue(str_contains($plugin, "'$k'"),
        "FilterAppData emits $k", $passes, $failures);
}

// Helper methods exist
assertTrue(str_contains($plugin, 'function resolveDashboardModeForNextcloud'),
    "Engine plugin defines resolveDashboardModeForNextcloud()", $passes, $failures);
assertTrue(str_contains($plugin, 'function isAppPasswordsAvailable'),
    "Engine plugin defines isAppPasswordsAvailable()", $passes, $failures);

// Plugin file still parses
$lint = shell_exec('php -l ' . escapeshellarg('/app/app/smail/v/current/app/plugins/nextcloud/index.php') . ' 2>&1');
assertTrue(str_contains((string)$lint, 'No syntax errors'),
    "Engine plugin index.php passes php -l", $passes, $failures);

// ---------------------------------------------------------------
// 5. quota.js navigates in-tab to the new route
// ---------------------------------------------------------------
$quota = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/quota.js');
assertTrue(str_contains($quota, '#/settings/souvera-account'),
    "quota.js builds URL with hash '#/settings/souvera-account'", $passes, $failures);
assertTrue(str_contains($quota, "el.target = '_self'"),
    "quota.js sets target='_self' (no second tab)", $passes, $failures);

// ---------------------------------------------------------------
// 6. Old NC-chrome assets are gone
// ---------------------------------------------------------------
assertTrue(!file_exists('/app/templates/settings.php'),
    "templates/settings.php removed", $passes, $failures);
assertTrue(!file_exists('/app/js/personal-settings.js'),
    "js/personal-settings.js removed", $passes, $failures);

// ---------------------------------------------------------------
// 7. Version 0.13.3
// ---------------------------------------------------------------
$info = file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(($vm[1] ?? '') === '0.13.3',
    "info.xml <version> == 0.13.3 (got: '" . ($vm[1] ?? '') . "')",
    $passes, $failures);

$changelog = file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($changelog, '[0.13.3]'),
    "CHANGELOG.md contains [0.13.3] heading", $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
