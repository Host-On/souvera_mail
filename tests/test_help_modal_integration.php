<?php
/**
 * Tests for the extended F1 Help Modal (0.13.22).
 *
 * Souvera Mail 0.13.22 replaces the separate "Hilfe & Anleitung" Settings
 * tab (which never activated properly in Snappymail's ViewModel registry
 * — see 0.13.21 rollback) with an in-place expansion of the vendored
 * Snappymail popup `PopupsKeyboardShortcutsHelp`. Three new Souvera tabs
 * are inserted BEFORE the four upstream keyboard-shortcut tabs:
 *
 *   1. Mail-Client (IMAP / POP3 / SMTP / ManageSieve)
 *   2. Kalender & Kontakte (CalDAV / CardDAV)
 *   3. Shield & Apps
 *
 * A companion JS + CSS plugin (`help-modal.js` + `help-modal.css`) fills
 * the placeholder `data-smail-help="KEY"` spans at runtime from the
 * `Nextcloud` FilterAppData payload and wires copy-to-clipboard buttons.
 *
 * The obsolete `settings-help.js` / `SettingsSouveraHelp.html` files
 * from 0.13.21 are gone — this test asserts they are NOT re-introduced
 * by accident.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Old Settings-tab artefacts must be gone
// ---------------------------------------------------------------
assertTrue(!file_exists('/app/app/smail/v/current/app/plugins/nextcloud/js/settings-help.js'),
    "obsolete settings-help.js is REMOVED (F1 modal replaces it)",
    $passes, $failures);
assertTrue(!file_exists('/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraHelp.html'),
    "obsolete SettingsSouveraHelp.html template is REMOVED",
    $passes, $failures);
assertTrue(!file_exists('/app/tests/test_help_tab_integration.php'),
    "obsolete test_help_tab_integration.php is REMOVED",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. New JS enricher — help-modal.js
// ---------------------------------------------------------------
$jsPath = '/app/app/smail/v/current/app/plugins/nextcloud/js/help-modal.js';
assertTrue(is_file($jsPath), "help-modal.js exists at $jsPath", $passes, $failures);
$js = (string) file_get_contents($jsPath);

// Attaches to the vendored popup DOM id
assertTrue(str_contains($js, "V-PopupsKeyboardShortcutsHelp"),
    "help-modal.js targets popup DOM id V-PopupsKeyboardShortcutsHelp",
    $passes, $failures);

// MutationObserver-based lazy attach (popup is created on first F1)
assertTrue(str_contains($js, 'MutationObserver'),
    "help-modal.js uses MutationObserver to wait for the lazy popup",
    $passes, $failures);

// Reads config from the same FilterAppData payload
assertTrue(str_contains($js, "rl.settings.get('Nextcloud')"),
    "help-modal.js reads Nextcloud FilterAppData", $passes, $failures);

// Re-enrich on every popup open (values stay fresh)
assertTrue((bool) preg_match('#attributeFilter:\s*\[\'open\'\]#', $js),
    "help-modal.js watches the popup's 'open' attribute for re-enrichment",
    $passes, $failures);

// Fills every data-smail-help placeholder
assertTrue(str_contains($js, "'[data-smail-help]'"),
    "help-modal.js selects [data-smail-help] placeholders",
    $passes, $failures);
assertTrue(str_contains($js, "'[data-smail-help-shield-block]'"),
    "help-modal.js toggles [data-smail-help-shield-block] visibility",
    $passes, $failures);
assertTrue(!str_contains($js, "data-smail-help-shield-missing"),
    "help-modal.js no longer references shield-missing (single-branch model)",
    $passes, $failures);
assertTrue(str_contains($js, "'[data-smail-help-shield-link]'"),
    "help-modal.js binds Shield link href", $passes, $failures);

// Copy-to-clipboard: single + pair buttons
assertTrue(str_contains($js, 'data-smail-help-copy') && str_contains($js, 'data-smail-help-copy-pair'),
    "help-modal.js wires both single and pair copy buttons",
    $passes, $failures);
assertTrue(str_contains($js, "navigator.clipboard") && str_contains($js, "execCommand('copy')"),
    "help-modal.js prefers Clipboard API with legacy execCommand fallback",
    $passes, $failures);

// Idempotent event wiring (marker attribute)
assertTrue(str_contains($js, "dataset.smailHelpWired"),
    "help-modal.js is idempotent — buttons only wired once (marker attribute)",
    $passes, $failures);

// Copy-feedback: user sees "✓ Kopiert" flash
assertTrue(str_contains($js, 'Kopiert'),
    "help-modal.js flashes 'Kopiert' feedback on successful copy",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. New CSS module — help-modal.css
// ---------------------------------------------------------------
$cssPath = '/app/app/smail/v/current/app/plugins/nextcloud/css/help-modal.css';
assertTrue(is_file($cssPath), "help-modal.css exists at $cssPath", $passes, $failures);
$css = (string) file_get_contents($cssPath);

// Scoped to the popup — no leakage into the shortcut tabs
assertTrue(substr_count($css, '#V-PopupsKeyboardShortcutsHelp') >= 10,
    "help-modal.css is scoped under #V-PopupsKeyboardShortcutsHelp (≥10 selectors)",
    $passes, $failures);

// Dark-mode selectors present (0.13.20 regression pin)
assertTrue(str_contains($css, 'body[data-theme-dark]'),
    "help-modal.css targets body[data-theme-dark] for NC dark mode",
    $passes, $failures);
assertTrue(str_contains($css, 'body[data-theme-dark-highcontrast]'),
    "help-modal.css targets body[data-theme-dark-highcontrast]",
    $passes, $failures);
assertTrue(str_contains($css, '.theme--dark'),
    "help-modal.css targets .theme--dark", $passes, $failures);

// Copy-done feedback class
assertTrue(str_contains($css, 'sv-help-copy-done'),
    "help-modal.css styles the .sv-help-copy-done success flash",
    $passes, $failures);

// ---------------------------------------------------------------
// 3b. CSS layout regressions (2026-02-17 — customer feedback:
//     50% modal width + IP addresses broken mid-digit)
// ---------------------------------------------------------------
// Modal must be wide enough for 7 tab labels on a single row
assertTrue((bool) preg_match('#\#V-PopupsKeyboardShortcutsHelp\s*\{[^}]*max-width\s*:\s*min\(\s*1100px#s', $css)
    || (bool) preg_match('#\#V-PopupsKeyboardShortcutsHelp\s*\{[^}]*width\s*:\s*min\(\s*1100px#s', $css),
    "help-modal.css bumps the popup width to accommodate 7 tab labels",
    $passes, $failures);

// Tab labels: no mid-word breaks ("Nachrichte\nnliste")
assertTrue((bool) preg_match('#\.tabs\s*>\s*label\s*\{\s*white-space:\s*nowrap#', $css),
    "help-modal.css sets .tabs > label { white-space: nowrap } so labels don't break mid-word",
    $passes, $failures);

// Inline code (IP:port etc.) must NOT be word-break: break-all — only the
// dedicated .sv-help-url class breaks long DAV URLs.
assertTrue((bool) preg_match('#\.sv-help-table\s+code\s*\{[^}]*word-break\s*:\s*normal#s', $css),
    "help-modal.css: short values (IP/port) are word-break:normal — no more '10.2/0.0.1/29' mid-digit breaks",
    $passes, $failures);
assertTrue((bool) preg_match('#\.sv-help-table\s+code\s*\{[^}]*white-space\s*:\s*nowrap#s', $css),
    "help-modal.css: short config values render on a single line (white-space: nowrap)",
    $passes, $failures);
assertTrue((bool) preg_match('#code\.sv-help-url\s*\{[^}]*word-break\s*:\s*break-all#s', $css),
    "help-modal.css: only .sv-help-url (CalDAV/CardDAV URLs) uses word-break: break-all",
    $passes, $failures);

// Dead CSS gone (no more shield-missing rules)
assertTrue(!str_contains($css, 'sv-help-shield-missing'),
    "help-modal.css: obsolete .sv-help-shield-missing rules are gone",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. Vendored template — PopupsKeyboardShortcutsHelp.html
// ---------------------------------------------------------------
$tplPath = '/app/app/smail/v/current/app/templates/Views/User/PopupsKeyboardShortcutsHelp.html';
$tpl = (string) file_get_contents($tplPath);

// Title is now generic "Hilfe" (no longer i18n-bound to SHORTCUTS_HELP)
assertTrue(str_contains($tpl, '<h3>Hilfe</h3>'),
    "template header title changed to '<h3>Hilfe</h3>' (general help modal)",
    $passes, $failures);
assertTrue(!str_contains($tpl, 'SHORTCUTS_HELP/LEGEND_SHORTCUTS_HELP'),
    "old i18n title key SHORTCUTS_HELP/LEGEND_SHORTCUTS_HELP is removed",
    $passes, $failures);

// New Souvera tabs
foreach ([
    'tab-help-mailclient' => 'Mail-Client',
    'tab-help-caldav' => 'Kalender &amp; Kontakte',
    'tab-help-shieldapps' => 'Shield &amp; Apps',
] as $id => $label) {
    assertTrue(str_contains($tpl, 'id="' . $id . '"'),
        "template has new radio input '$id'", $passes, $failures);
    assertTrue(str_contains($tpl, $label),
        "template has label text '$label'", $passes, $failures);
}

// Existing shortcut tabs still there
foreach (['tab-help1', 'tab-help2', 'tab-help3', 'tab-help4'] as $id) {
    assertTrue(str_contains($tpl, 'id="' . $id . '"'),
        "existing shortcut tab '$id' preserved", $passes, $failures);
}
assertTrue(str_contains($tpl, 'SHORTCUTS_HELP/TAB_MAILBOX'),
    "existing i18n key SHORTCUTS_HELP/TAB_MAILBOX preserved",
    $passes, $failures);
assertTrue(str_contains($tpl, 'SHORTCUTS_HELP/TAB_COMPOSE'),
    "existing i18n key SHORTCUTS_HELP/TAB_COMPOSE preserved",
    $passes, $failures);

// Tab-navigation JS relies on all radios sharing the same name
$radios = substr_count($tpl, 'name="helptabs"');
assertTrue($radios >= 7,
    "template has ≥7 radios with name='helptabs' (3 Souvera + 4 shortcut). got: $radios",
    $passes, $failures);

// Exactly ONE default-checked tab (the very first Souvera tab)
$checkedCount = preg_match_all('#type="radio"\s+name="helptabs"\s+id="[^"]+"\s+checked\b#', $tpl);
assertTrue($checkedCount === 1,
    "exactly one tab is default-checked (got: $checkedCount)",
    $passes, $failures);
assertTrue((bool) preg_match('#id="tab-help-mailclient"\s+checked#', $tpl),
    "the Mail-Client tab is the default-selected one on modal open",
    $passes, $failures);

// Placeholder spans for every FilterAppData key
$keys = [
    'SmailHelpDomain', 'SmailHelpEmail',
    'SmailHelpImapHost', 'SmailHelpImapPort', 'SmailHelpImapSsl',
    'SmailHelpPop3Host', 'SmailHelpPop3Port', 'SmailHelpPop3Ssl',
    'SmailHelpSmtpHost', 'SmailHelpSmtpPort', 'SmailHelpSmtpSsl',
    'SmailHelpSieveHost', 'SmailHelpSievePort', 'SmailHelpSieveSsl',
    'SmailHelpCalDavUrl', 'SmailHelpCardDavUrl',
];
foreach ($keys as $k) {
    assertTrue(str_contains($tpl, 'data-smail-help="' . $k . '"'),
        "template has placeholder for key '$k'", $passes, $failures);
}

// Shield block wiring — single-branch (available OR fully hidden).
// End users must never see raw operator commands (no shell access).
assertTrue(str_contains($tpl, 'data-smail-help-shield-block'),
    "template has [data-smail-help-shield-block] (dedicated available branch)",
    $passes, $failures);
assertTrue(!str_contains($tpl, 'data-smail-help-shield-missing'),
    "template REMOVED the missing-shield branch (customers have no shell / occ access)",
    $passes, $failures);
assertTrue(str_contains($tpl, 'data-smail-help-shield-link'),
    "template has [data-smail-help-shield-link] for href injection",
    $passes, $failures);
assertTrue(!str_contains($tpl, 'occ config:app:set'),
    "template contains ZERO occ commands (customers have no shell access)",
    $passes, $failures);
assertTrue(str_contains($tpl, 'data-smail-help-shield-block hidden'),
    "shield block starts hidden — only unhidden by JS when SmailHelpShieldUrl is present",
    $passes, $failures);

// Copy buttons
$singleCopies = preg_match_all('#data-smail-help-copy="#', $tpl);
$pairCopies = preg_match_all('#data-smail-help-copy-pair="#', $tpl);
assertTrue($singleCopies >= 3,
    "template has ≥3 single-value copy buttons (got: $singleCopies)",
    $passes, $failures);
assertTrue($pairCopies >= 4,
    "template has ≥4 host:port pair copy buttons (got: $pairCopies)",
    $passes, $failures);

// Mobile apps mentioned
foreach (['K-9 Mail', 'Thunderbird', 'Apple Mail', 'DAVx⁵', 'FairEmail', 'Outlook'] as $app) {
    assertTrue(str_contains($tpl, $app),
        "template mentions mobile app '$app'", $passes, $failures);
}

// ---------------------------------------------------------------
// 5. Plugin wiring (Init())
// ---------------------------------------------------------------
$plugin = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');

assertTrue(str_contains($plugin, "\$this->addJs('js/help-modal.js')"),
    "plugin Init() registers js/help-modal.js",
    $passes, $failures);
assertTrue(str_contains($plugin, "\$this->addCss('css/help-modal.css')"),
    "plugin Init() registers css/help-modal.css via addCss",
    $passes, $failures);

// Obsolete registrations gone
assertTrue(!str_contains($plugin, "js/settings-help.js"),
    "plugin no longer registers obsolete js/settings-help.js",
    $passes, $failures);
assertTrue(!str_contains($plugin, "SettingsSouveraHelp.html"),
    "plugin no longer registers obsolete SettingsSouveraHelp.html",
    $passes, $failures);

// buildHelpData() still exists (data source unchanged)
assertTrue(str_contains($plugin, 'protected function buildHelpData('),
    "buildHelpData() PHP helper still exists (data source unchanged)",
    $passes, $failures);

// Signature now includes IURLGenerator (needed for Shield auto-link)
assertTrue((bool) preg_match(
    '#buildHelpData\(\s*string \$sUID,\s*string \$sWebDAV,\s*\\\\OCP\\\\IUser \$ocUser,\s*\\\\OCP\\\\IURLGenerator \$oUrlGen\s*\)#',
    $plugin
), "buildHelpData() signature now takes (uid, webdav, IUser, IURLGenerator)",
    $passes, $failures);

// Auto-Shield resolver: probes IAppManager::isEnabledForUser('souvera_shield', …)
assertTrue(str_contains($plugin, "IAppManager"),
    "buildHelpData() references IAppManager for Shield auto-detection",
    $passes, $failures);
assertTrue((bool) preg_match(
    "#isEnabledForUser\(\s*'souvera_shield'\s*,\s*\\\$ocUser\s*\)#",
    $plugin
), "buildHelpData() checks IAppManager::isEnabledForUser('souvera_shield', \$ocUser)",
    $passes, $failures);
assertTrue((bool) preg_match(
    "#linkToRoute\(\s*'souvera_shield\.page\.index'\s*\)#",
    $plugin
), "buildHelpData() links to the souvera_shield.page.index route when the app is enabled",
    $passes, $failures);
assertTrue(str_contains($plugin, "getAbsoluteURL"),
    "buildHelpData() calls getAbsoluteURL on the Shield route so the link works from any client",
    $passes, $failures);

// The app-config override still works as an optional escape hatch
assertTrue(str_contains($plugin, "getValueString('souvera_mail', 'shield_url'"),
    "buildHelpData() keeps the app-config `souvera_mail.shield_url` override as a fallback",
    $passes, $failures);

foreach ($keys as $k) {
    assertTrue(str_contains($plugin, "'{$k}'"),
        "buildHelpData() still emits '{$k}'", $passes, $failures);
}
assertTrue(str_contains($plugin, "'SmailHelpShieldUrl'"),
    "buildHelpData() still emits 'SmailHelpShieldUrl'", $passes, $failures);

// ---------------------------------------------------------------
// 6. Version bump + CHANGELOG regression
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(version_compare($vm[1] ?? '0', '0.13.22', '>='),
    "info.xml <version> >= 0.13.22 (got: '" . ($vm[1] ?? '') . "')",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($cl, '[0.13.22]'),
    "CHANGELOG has a [0.13.22] section", $passes, $failures);
assertTrue(str_contains($cl, 'F1') || str_contains($cl, 'PopupsKeyboardShortcutsHelp')
        || str_contains($cl, 'Modal') || str_contains($cl, 'modal'),
    "CHANGELOG mentions the F1 / Modal rebuild",
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
