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

// Copy-feedback: user sees "✓ Copied" (English source) — German translation
// lives in the plugin lang file de.json (HELP_MODAL/COPIED).
$deLang = @file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/langs/de.json');
assertTrue(
    str_contains($js, "HELP_MODAL/COPIED")
    && ($deLang !== false && str_contains($deLang, 'Kopiert')),
    "help-modal.js flashes 'Kopiert' feedback on successful copy (via HELP_MODAL/COPIED i18n key + de.json translation)",
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
//     tab-content only 50% wide + IP addresses broken mid-digit)
// ---------------------------------------------------------------
// Modal must be wide enough for 4 tab labels on a single row
assertTrue((bool) preg_match('#\#V-PopupsKeyboardShortcutsHelp\s*\{[^}]*max-width\s*:\s*min\(\s*1100px#s', $css)
    || (bool) preg_match('#\#V-PopupsKeyboardShortcutsHelp\s*\{[^}]*width\s*:\s*min\(\s*1100px#s', $css),
    "help-modal.css bumps the popup width to accommodate all tabs",
    $passes, $failures);

// CRITICAL: `.tabs > .tab-content` must span full width (fix for 50% artefact)
assertTrue((bool) preg_match('#\.tabs\s*>\s*\.tab-content\s*\{[^}]*width\s*:\s*100%#s', $css),
    "help-modal.css: .tabs > .tab-content { width: 100% } — no more 50% artefact",
    $passes, $failures);
assertTrue((bool) preg_match('#\.tabs\s*>\s*\.tab-content\s*\{[^}]*min-width\s*:\s*0#s', $css),
    "help-modal.css: .tabs > .tab-content { min-width: 0 } — allows CSS-grid shrinkage",
    $passes, $failures);

// .sv-help-tab wrapper: full width, box-sizing set
assertTrue((bool) preg_match('#\.sv-help-tab\s*\{[^}]*width\s*:\s*100%#s', $css),
    "help-modal.css: .sv-help-tab { width: 100% } — Souvera tabs fill the popup",
    $passes, $failures);

// Every table inside a Souvera tab spans full width
assertTrue((bool) preg_match('#\.sv-help-tab\s+table\s*\{[^}]*width\s*:\s*100%#s', $css),
    "help-modal.css: .sv-help-tab table { width: 100% } — tables stop shrinking to intrinsic width",
    $passes, $failures);

// Tab labels: no mid-word breaks
assertTrue((bool) preg_match('#\.tabs\s*>\s*label\s*\{\s*white-space:\s*nowrap#', $css),
    "help-modal.css sets .tabs > label { white-space: nowrap } so labels don't break mid-word",
    $passes, $failures);

// Config-table column widths — 1st (label) & 3rd (button) hug, 2nd (value) grows
assertTrue((bool) preg_match('#\.sv-help-table\s+td:nth-child\(1\)\s*\{[^}]*width\s*:\s*1%#s', $css),
    "help-modal.css: config-table label column hugs its natural width",
    $passes, $failures);
assertTrue((bool) preg_match('#\.sv-help-table\s+td:nth-child\(3\)\s*\{[^}]*width\s*:\s*1%#s', $css),
    "help-modal.css: config-table button column hugs the copy-button width",
    $passes, $failures);

// CRITICAL vertical-alignment: rowspan="3" copy buttons must render mid-cell
foreach ([1, 2, 3] as $col) {
    assertTrue((bool) preg_match(
        '#\.sv-help-table\s+td:nth-child\(' . $col . '\)\s*\{[^}]*vertical-align\s*:\s*middle#s',
        $css
    ), "help-modal.css: .sv-help-table td:nth-child($col) is vertical-align: middle (rowspan=3 copy buttons centred)",
        $passes, $failures);
}

// Inline code (IP:port etc.) must NOT be word-break: break-all
assertTrue((bool) preg_match('#\.sv-help-table\s+code\s*\{[^}]*word-break\s*:\s*normal#s', $css),
    "help-modal.css: short values (IP/port) are word-break:normal — no more '10.2/0.0.1/29' mid-digit breaks",
    $passes, $failures);
assertTrue((bool) preg_match('#\.sv-help-table\s+code\s*\{[^}]*white-space\s*:\s*nowrap#s', $css),
    "help-modal.css: short config values render on a single line (white-space: nowrap)",
    $passes, $failures);
assertTrue((bool) preg_match('#code\.sv-help-url\s*\{[^}]*word-break\s*:\s*break-all#s', $css),
    "help-modal.css: only .sv-help-url (CalDAV/CardDAV URLs) uses word-break: break-all",
    $passes, $failures);

// Shortcut-grid styling (2-column responsive layout for the unified tab)
assertTrue((bool) preg_match('#\.sv-help-shortcut-grid\s*\{[^}]*display\s*:\s*grid#s', $css),
    "help-modal.css: .sv-help-shortcut-grid uses CSS grid",
    $passes, $failures);
assertTrue((bool) preg_match('#\.sv-help-shortcut-grid\s*\{[^}]*grid-template-columns\s*:\s*repeat\(auto-fill#s', $css),
    "help-modal.css: shortcut grid uses auto-fill for responsive columns",
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
assertTrue(str_contains($tpl, 'data-smail-help-i18n="HELP_MODAL/HEADING"'),
    "template header title uses HELP_MODAL/HEADING i18n key (was '<h3>Hilfe</h3>')",
    $passes, $failures);
assertTrue(!str_contains($tpl, 'SHORTCUTS_HELP/LEGEND_SHORTCUTS_HELP'),
    "old i18n title key SHORTCUTS_HELP/LEGEND_SHORTCUTS_HELP is removed",
    $passes, $failures);

// New Souvera tabs — English defaults are rendered directly in the template
// with `data-smail-help-i18n` attributes for translation into DE/NL at runtime.
foreach ([
    'tab-help-mailclient' => ['HELP_MODAL/TAB_MAILCLIENT', 'Mail-Client', 'Mail client'],
    'tab-help-caldav' => ['HELP_MODAL/TAB_CALDAV', 'Kalender & Kontakte', 'Calendar &amp; Contacts'],
    'tab-help-shieldapps' => ['HELP_MODAL/TAB_SHIELDAPPS', 'Shield & Apps', 'Shield &amp; Apps'],
] as $id => $meta) {
    assertTrue(str_contains($tpl, 'id="' . $id . '"'),
        "template has new radio input '$id'", $passes, $failures);
    [$i18nKey, $deExpect, $enExpect] = $meta;
    assertTrue(str_contains($tpl, 'data-smail-help-i18n="' . $i18nKey . '"'),
        "template exposes '$i18nKey' i18n attribute for tab label", $passes, $failures);
    assertTrue(str_contains($tpl, $enExpect),
        "template ships English default label '$enExpect' for '$id'", $passes, $failures);
    assertTrue($deLang !== false && str_contains($deLang, $deExpect),
        "plugin de.json contains German label '$deExpect' for '$i18nKey'", $passes, $failures);
}

// Existing shortcut tabs are now CONSOLIDATED into a single "Tastenkürzel" tab
assertTrue(str_contains($tpl, 'id="tab-help-shortcuts"'),
    "template has consolidated 'Tastenkürzel' tab (id=tab-help-shortcuts)",
    $passes, $failures);
foreach (['tab-help1', 'tab-help2', 'tab-help3', 'tab-help4'] as $obsolete) {
    assertTrue(!str_contains($tpl, 'id="' . $obsolete . '"'),
        "obsolete radio '$obsolete' is REMOVED (4 shortcut tabs → 1)",
        $passes, $failures);
}

// All four shortcut categories are now section headings inside the unified tab
foreach ([
    'SHORTCUTS_HELP/TAB_MAILBOX',
    'SHORTCUTS_HELP/TAB_MESSAGE_LIST',
    'SHORTCUTS_HELP/TAB_MESSAGE_VIEW',
    'SHORTCUTS_HELP/TAB_COMPOSE',
] as $i18nKey) {
    assertTrue(str_contains($tpl, 'data-i18n="' . $i18nKey . '"'),
        "shortcut category i18n key '$i18nKey' preserved (now as section heading)",
        $passes, $failures);
}
assertTrue(str_contains($tpl, 'SHORTCUTS_HELP/LABEL_OPEN_COMPOSE_POPUP'),
    "compose shortcut rows preserved inside the unified tab",
    $passes, $failures);
assertTrue(str_contains($tpl, 'sv-help-shortcut-grid'),
    "template uses .sv-help-shortcut-grid wrapper (2-column responsive layout)",
    $passes, $failures);
$shortcutBlocks = preg_match_all('#class="sv-help-shortcut-block"#', $tpl);
assertTrue($shortcutBlocks === 4,
    "template has exactly 4 shortcut blocks (Postfach + Liste + Ansicht + Verfassen). got: $shortcutBlocks",
    $passes, $failures);

// Tab-navigation JS relies on all radios sharing the same name
$radios = substr_count($tpl, 'name="helptabs"');
assertTrue($radios === 5,
    "template has exactly 5 radios with name='helptabs' (v0.15.0: 4 Souvera tabs + 1 unified Tastenkürzel). got: $radios",
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
// 4b. App-Passwort explainer on the Mail-Client tab
// ---------------------------------------------------------------
// Correct navigation path — customers must be told the actual UI route
// ("Einstellungen → Sicherheit & Geräte"), not just the sub-tab name.
assertTrue(str_contains($tpl, 'sv-help-callout'),
    "template has a .sv-help-callout box on the Mail-Client tab for the App-Passwort explainer",
    $passes, $failures);
assertTrue(str_contains($tpl, 'sv-help-steps'),
    "template numbers the App-Passwort creation steps in a <ol class='sv-help-steps'>",
    $passes, $failures);
assertTrue($deLang !== false && str_contains($deLang, '<strong>Einstellungen</strong>'),
    "plugin de.json step points to 'Einstellungen' in bold (CALLOUT_STEP_1)",
    $passes, $failures);
assertTrue($deLang !== false && str_contains($deLang, '<strong>Sicherheit &amp; Geräte</strong>'),
    "plugin de.json step names 'Sicherheit & Geräte' in bold (CALLOUT_STEP_2)",
    $passes, $failures);
assertTrue($deLang !== false && (bool) preg_match('#pro\s+Gerät#iu', $deLang),
    "plugin de.json stresses one App-Passwort PER device for revocability (CALLOUT_HINT)",
    $passes, $failures);
assertTrue($deLang !== false && str_contains($deLang, 'einmalig'),
    "plugin de.json warns the App-Passwort is only shown ONCE ('einmalig')",
    $passes, $failures);
assertTrue(!str_contains($tpl, 'unter „Sicherheit & Geräte"')
    && !str_contains($tpl, 'unter „Sicherheit &amp; Geräte"'),
    "template no longer uses the misleading 'unter „Sicherheit & Geräte\"' phrasing",
    $passes, $failures);

// Passwort row in the config table cross-references the explainer.
// English source: "Password" row body -> "Your personal <strong>app password</strong>…".
// German shipped via HELP_MODAL/PASSWORD_DETAIL in plugin de.json.
assertTrue(str_contains($tpl, 'data-smail-help-i18n-html="HELP_MODAL/PASSWORD_DETAIL"'),
    "template Password row uses HELP_MODAL/PASSWORD_DETAIL i18n key",
    $passes, $failures);
assertTrue(
    $deLang !== false
    && (bool) preg_match('#<strong>App-Passwort</strong>#', $deLang),
    "plugin de.json PASSWORD_DETAIL translation reinforces: use the App-Passwort, not the login one",
    $passes, $failures);

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

// Defensive wrapper: FilterAppData routes through safeBuildHelpData()
// so a Help-data glitch NEVER breaks the entire Snappymail boot.
assertTrue(str_contains($plugin, 'protected function safeBuildHelpData('),
    "plugin has a defensive safeBuildHelpData() wrapper",
    $passes, $failures);
assertTrue(str_contains($plugin, '$this->safeBuildHelpData($sUID, $sWebDAV, $ocUser, $oUrlGen)'),
    "FilterAppData routes through safeBuildHelpData (NOT direct buildHelpData)",
    $passes, $failures);
assertTrue((bool) preg_match(
    '#safeBuildHelpData[\s\S]{0,300}try\s*\{[\s\S]{0,200}return \$this->buildHelpData\([\s\S]{0,50}catch\s*\(\\\\?Throwable#',
    $plugin
), "safeBuildHelpData() wraps buildHelpData() in try/catch(Throwable) — graceful degradation",
    $passes, $failures);
// Fallback payload includes every Help key so the JS side never null-crashes
foreach (['SmailHelpImapHost', 'SmailHelpShieldUrl', 'SmailHelpCalDavUrl', 'SmailHelpDomain'] as $k) {
    assertTrue((bool) preg_match(
        '#safeBuildHelpData[\s\S]{0,1500}\'' . $k . '\'\s*=>\s*\'\'#s',
        $plugin
    ), "safeBuildHelpData() fallback emits '$k' => '' on failure",
        $passes, $failures);
}

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

// CRITICAL — internal cluster IPs (e.g. 10.0.0.10) must NEVER surface to
// end users. Overwrite IMAP/POP3/SMTP/Sieve host with the public FQDN
// extracted from the WebDAV base URL (same host the user reaches NC on).
assertTrue(str_contains($plugin, "\$parsedHost = \\parse_url(\$sWebDAV, PHP_URL_HOST)"),
    "buildHelpData() extracts the public FQDN via parse_url(webdav, PHP_URL_HOST)",
    $passes, $failures);
assertTrue((bool) preg_match('#if\s*\(\s*\$imapHost\s*!==\s*\'\'\s*\)\s*\{\s*\$imapHost\s*=\s*\$publicHost;\s*\}#', $plugin),
    "buildHelpData() overrides IMAP host with the public FQDN (hides internal IP)",
    $passes, $failures);
assertTrue((bool) preg_match('#if\s*\(\s*\$smtpHost\s*!==\s*\'\'\s*\)\s*\{\s*\$smtpHost\s*=\s*\$publicHost;\s*\}#', $plugin),
    "buildHelpData() overrides SMTP host with the public FQDN",
    $passes, $failures);
assertTrue((bool) preg_match('#if\s*\(\s*\$sieveHost\s*!==\s*\'\'\s*\)\s*\{\s*\$sieveHost\s*=\s*\$publicHost;\s*\}#', $plugin),
    "buildHelpData() overrides Sieve host with the public FQDN",
    $passes, $failures);
// POP3 host is derived from imapHost AFTER the public-host override —
// pin that the derivation appears BELOW the override block.
assertTrue((bool) preg_match(
    '#\$sieveHost\s*=\s*\$publicHost;\s*\}\s*\}\s*//[^\n]*\n(?:\s*//[^\n]*\n)*\s*\$pop3Host\s*=\s*\$imapHost;#',
    $plugin
), "buildHelpData() re-derives POP3 host AFTER the public-host override",
    $passes, $failures);

// SmailHelpDomain: now surfaces the public FQDN (was mail-domain earlier)
assertTrue((bool) preg_match(
    "#'SmailHelpDomain'\s*=>\s*\\\$publicHost\s*!==\s*''\s*\?\s*\\\$publicHost\s*:\s*\\\$domain#",
    $plugin
), "buildHelpData() prefers the public FQDN for SmailHelpDomain, falls back to the mail domain",
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
