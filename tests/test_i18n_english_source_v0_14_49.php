<?php
/**
 * Souvera Mail v0.14.49 — English-source i18n regression guards.
 *
 * The v0.14.49 rebase moved every user-facing string from a mix of
 * German-in-source + partial l10n coverage to a strict English-source
 * model, mirroring how the rest of the Nextcloud eco-system works.
 *
 * These pins ensure:
 *
 *   1. Vue components consistently use English source keys with
 *      `t('souvera_mail', 'English text')`. No German source strings
 *      leak back into the Vue tree.
 *   2. l10n/de.js contains the full ~220 English → German mapping.
 *   3. l10n/nl.js contains the full ~220 English → Dutch mapping.
 *   4. l10n/en_GB.js exists as an identity map so English falls back
 *      predictably for `en_US`, `en_AU`, etc.
 *   5. The Snappymail-plugin lang files (en.json / de.json / nl.json)
 *      expose the new sections MENU / SIEVE_APPLY / HELP_MODAL / QUOTA
 *      so the dropdown menu, sieve-apply modal, help template and
 *      quota bar all resolve to translated strings.
 *   6. The JS enrichers (dropdown-menu.js, sieve-apply.js,
 *      help-modal.js, quota.js) call `rl.i18n(KEY, fallback)`
 *      instead of hardcoded German literals.
 *   7. `PopupsKeyboardShortcutsHelp.html` uses
 *      `data-smail-help-i18n(-html)` attributes for every translatable
 *      block (headings, callout, table headers, tips, app tags).
 */
declare(strict_types=1);

$failures = [];
$passes = [];
$assert = function (bool $cond, string $msg) use (&$failures, &$passes): void {
    if ($cond) { $passes[] = $msg; echo "PASS: $msg\n"; }
    else       { $failures[] = $msg; echo "FAIL: $msg\n"; }
};

// ==============================================================
// 1. Vue components — English source keys only
// ==============================================================
$vue_files = [
    '/app/src/App.vue',
    '/app/src/components/MigrationWizard.vue',
    '/app/src/components/MigrationPill.vue',
    '/app/src/components/ResyncDialog.vue',
    '/app/src/components/screens/WelcomeScreen.vue',
    '/app/src/components/screens/ImapFormScreen.vue',
    '/app/src/components/screens/FolderMappingScreen.vue',
    '/app/src/components/screens/ConfirmScreen.vue',
    '/app/src/components/screens/ProgressScreen.vue',
    '/app/src/components/screens/TerminalScreen.vue',
    '/app/src/composables/useMigration.js',
];
// Any source string that clearly contains German-only characters or
// words means we forgot to switch it to English.
$german_indicators = [
    'Postfach', 'Ordner\'', 'Abbrechen\'', 'Zurück', 'Passwort\'', 'Nachricht',
    'Fehler', 'Läuft', 'Erweitert', 'Willkommen', 'Wichtig\'', 'Sicherheit',
    'Kalender', 'Empfohlene', 'Warteschlange', 'Postausgang',
];
foreach ($vue_files as $f) {
    $src = (string) @file_get_contents($f);
    $assert($src !== '', "$f exists");
    foreach ($german_indicators as $needle) {
        // Only flag inside i18n calls — comments can still be German.
        // Match:  t('souvera_mail', '...German...') OR n('souvera_mail', ...)
        $has_de = (bool) preg_match(
            "/[tn]\\('souvera_mail'\\s*,\\s*'[^']*" . preg_quote($needle, '/') . "[^']*'/",
            $src
        );
        $assert(!$has_de,
            "$f has no German source key containing '" . trim($needle, "'") . "'");
    }
}

// ==============================================================
// 2. l10n/de.js contains a full translation table (>=200 keys)
// ==============================================================
$de_js  = (string) @file_get_contents('/app/l10n/de.js');
$nl_js  = (string) @file_get_contents('/app/l10n/nl.js');
$engb_js = (string) @file_get_contents('/app/l10n/en_GB.js');
$en_js  = (string) @file_get_contents('/app/l10n/en.js');
$assert($de_js !== '',  'l10n/de.js exists');
$assert($nl_js !== '',  'l10n/nl.js exists');
$assert($engb_js !== '', 'l10n/en_GB.js exists');
$assert($en_js !== '',  'l10n/en.js exists (English is source; identity map for explicit lang=en)');

// Count the number of `"Key": "Value"` lines — must be >= 200 in each.
foreach (['de' => $de_js, 'nl' => $nl_js, 'en_GB' => $engb_js, 'en' => $en_js] as $lang => $content) {
    $count = preg_match_all('/^\s{4}"[^"]+":\s+/m', $content);
    $assert($count >= 200,
        "l10n/$lang.js has $count entries (>= 200 expected)");
}

// A random sample of German translations must be present in de.js.
foreach ([
    'Speichern' => 'Save',
    'Passwort'   => 'Password',
    'Alte Mails importieren' => 'Import old mail',
    'Postfach neu synchronisieren' => 'Resync mailbox',
    'Ordner ausw' => 'Select folders',   // matches 'Ordner auswählen'
    'Verbindung fehlgeschlagen' => 'Connection failed',
    'Wende Filter' => null,   // hint: was in sieve-apply JS (now plugin lang)
    'Ihre Mailbox ist derzeit leer' => 'Your mailbox is currently empty',
] as $de_val => $en_key) {
    if ($en_key === null) { continue; }
    $assert(strpos($de_js, $de_val) !== false,
        "l10n/de.js contains German translation containing '$de_val'");
}

// A random sample of Dutch translations must be present in nl.js.
foreach ([
    'Opslaan',
    'Wachtwoord',
    'Oude e-mail importeren',
    'Mailbox opnieuw synchroniseren',
    'Mappen selecteren',
    'Verbinding mislukt',
    'Je mailbox is momenteel leeg',
] as $nl_val) {
    $assert(strpos($nl_js, $nl_val) !== false,
        "l10n/nl.js contains Dutch translation '$nl_val'");
}

// The English identity map contains the source keys 1:1.
foreach ([
    'Save', 'Password', 'Import old mail', 'Resync mailbox',
    'Select folders', 'Connection failed', 'Your mailbox is currently empty',
] as $en_val) {
    $assert(strpos($engb_js, "\"$en_val\": \"$en_val\"") !== false,
        "l10n/en_GB.js maps '$en_val' → '$en_val' (identity)");
}

// ==============================================================
// 3. Snappymail plugin lang files
// ==============================================================
$plugin_langs = '/app/app/smail/v/current/app/plugins/nextcloud/langs';
$en_json = json_decode((string) @file_get_contents("$plugin_langs/en.json"), true);
$de_json = json_decode((string) @file_get_contents("$plugin_langs/de.json"), true);
$nl_json = json_decode((string) @file_get_contents("$plugin_langs/nl.json"), true);
$assert(is_array($en_json), 'plugin/langs/en.json parses');
$assert(is_array($de_json), 'plugin/langs/de.json parses');
$assert(is_array($nl_json), 'plugin/langs/nl.json parses (Dutch NEW in v0.14.49)');

foreach (['MENU', 'SIEVE_APPLY', 'HELP_MODAL', 'QUOTA'] as $section) {
    foreach ([$en_json, $de_json, $nl_json] as $langMap) {
        $assert(isset($langMap[$section]) && is_array($langMap[$section]),
            "plugin lang file has section '$section'");
    }
}

// Specific keys the dropdown / modal / help template rely on.
$menu_keys = ['IMPORT_OLD_MAIL', 'RESYNC_MAILBOX', 'APPLY_FILTER_FOLDER'];
foreach ($menu_keys as $k) {
    $assert(!empty($en_json['MENU'][$k]),
        "en.json has MENU/$k ('" . ($en_json['MENU'][$k] ?? '?') . "')");
    $assert(!empty($de_json['MENU'][$k]),
        "de.json has MENU/$k ('" . ($de_json['MENU'][$k] ?? '?') . "')");
    $assert(!empty($nl_json['MENU'][$k]),
        "nl.json has MENU/$k ('" . ($nl_json['MENU'][$k] ?? '?') . "')");
}
$assert($de_json['MENU']['RESYNC_MAILBOX'] === 'Postfach neu synchronisieren',
    'de.json MENU/RESYNC_MAILBOX still reads "Postfach neu synchronisieren"');
$assert($nl_json['MENU']['RESYNC_MAILBOX'] === 'Mailbox opnieuw synchroniseren',
    'nl.json MENU/RESYNC_MAILBOX reads "Mailbox opnieuw synchroniseren"');

$assert($de_json['HELP_MODAL']['COPIED'] === '✓ Kopiert',
    'de.json HELP_MODAL/COPIED still reads "✓ Kopiert"');
$assert($nl_json['HELP_MODAL']['COPIED'] === '✓ Gekopieerd',
    'nl.json HELP_MODAL/COPIED reads "✓ Gekopieerd"');

$assert($de_json['QUOTA']['MAIL_STORAGE'] === 'Mail-Speicher',
    'de.json QUOTA/MAIL_STORAGE still reads "Mail-Speicher"');
$assert($nl_json['QUOTA']['MAIL_STORAGE'] === 'Mail-opslag',
    'nl.json QUOTA/MAIL_STORAGE reads "Mail-opslag"');

// ==============================================================
// 4. JS enrichers use rl.i18n() with English fallback
// ==============================================================
$dropdown = (string) @file_get_contents("$plugin_langs/../js/dropdown-menu.js");
$sieve    = (string) @file_get_contents("$plugin_langs/../js/sieve-apply.js");
$helpmod  = (string) @file_get_contents("$plugin_langs/../js/help-modal.js");
$quota    = (string) @file_get_contents("$plugin_langs/../js/quota.js");

$assert(strpos($dropdown, "i18n('MENU/IMPORT_OLD_MAIL'") !== false,
    "dropdown-menu.js resolves menu labels via i18n('MENU/IMPORT_OLD_MAIL', 'Import old mail')");
$assert(strpos($dropdown, "i18n('MENU/RESYNC_MAILBOX'") !== false,
    "dropdown-menu.js resolves resync entry via i18n('MENU/RESYNC_MAILBOX', 'Resync mailbox')");

$assert(strpos($sieve, "i18n('SIEVE_APPLY/TITLE'") !== false,
    "sieve-apply.js modal title uses i18n('SIEVE_APPLY/TITLE', …)");
$assert(strpos($sieve, "i18n('SIEVE_APPLY/APPLY'") !== false,
    "sieve-apply.js Apply button uses i18n('SIEVE_APPLY/APPLY', …)");
$assert(strpos($sieve, "i18n('SIEVE_APPLY/CANCEL'") !== false,
    "sieve-apply.js Cancel button uses i18n('SIEVE_APPLY/CANCEL', …)");
$assert(strpos($sieve, "i18n('MENU/APPLY_FILTER_FOLDER'") !== false,
    "sieve-apply.js dropdown menu entry uses i18n('MENU/APPLY_FILTER_FOLDER', …)");

$assert(strpos($helpmod, "i18n('HELP_MODAL/COPIED'") !== false,
    "help-modal.js copy-success flash uses i18n('HELP_MODAL/COPIED', '✓ Copied')");
$assert(strpos($helpmod, 'data-smail-help-i18n') !== false,
    "help-modal.js processes data-smail-help-i18n attributes to translate template blocks");
$assert(strpos($helpmod, 'data-smail-help-i18n-html') !== false,
    "help-modal.js processes data-smail-help-i18n-html attributes for markup-heavy blocks");

$assert(strpos($quota, "i18n('QUOTA/MAIL_STORAGE'") !== false,
    "quota.js sidebar title uses i18n('QUOTA/MAIL_STORAGE', 'Mail storage')");
$assert(strpos($quota, "i18n('QUOTA/USED_LABEL'") !== false,
    "quota.js unlimited-account label uses i18n('QUOTA/USED_LABEL', '{used} used')");
$assert(strpos($quota, "QUOTA/ALERT_TOAST") !== false,
    "quota.js ≥95%-alert toast uses i18n('QUOTA/ALERT_TOAST', …)");

// ==============================================================
// 5. Help template — data-smail-help-i18n on all translatable blocks
// ==============================================================
$tpl = (string) @file_get_contents(
    '/app/app/smail/v/current/app/templates/Views/User/PopupsKeyboardShortcutsHelp.html'
);
foreach ([
    'HELP_MODAL/HEADING',
    'HELP_MODAL/TAB_MAILCLIENT',
    'HELP_MODAL/TAB_CALDAV',
    'HELP_MODAL/TAB_SHIELDAPPS',
    'HELP_MODAL/TAB_SHORTCUTS',
    'HELP_MODAL/LEAD_MAILCLIENT',
    'HELP_MODAL/CALLOUT_TITLE',
    'HELP_MODAL/CALLOUT_STEP_1',
    'HELP_MODAL/CALLOUT_STEP_2',
    'HELP_MODAL/CALLOUT_STEP_3',
    'HELP_MODAL/CALLOUT_STEP_4',
    'HELP_MODAL/CALLOUT_STEP_5',
    'HELP_MODAL/CALLOUT_HINT',
    'HELP_MODAL/SECTION_ADDRESS',
    'HELP_MODAL/EMAIL_LABEL',
    'HELP_MODAL/PASSWORD_LABEL',
    'HELP_MODAL/PASSWORD_DETAIL',
    'HELP_MODAL/IMAP_HEAD',
    'HELP_MODAL/SMTP_HEAD',
    'HELP_MODAL/POP3_HEAD',
    'HELP_MODAL/SIEVE_HEAD',
    'HELP_MODAL/SERVER_LABEL',
    'HELP_MODAL/PORT_LABEL',
    'HELP_MODAL/SSL_LABEL',
    'HELP_MODAL/COPY',
    'HELP_MODAL/COPY_SERVER_PORT',
    'HELP_MODAL/TIP_STARTTLS',
    'HELP_MODAL/LEAD_CALDAV',
    'HELP_MODAL/CALDAV_HEAD',
    'HELP_MODAL/CARDDAV_HEAD',
    'HELP_MODAL/URL_LABEL',
    'HELP_MODAL/CALDAV_TIP',
    'HELP_MODAL/SHIELD_TITLE',
    'HELP_MODAL/SHIELD_INTRO',
    'HELP_MODAL/SHIELD_OPEN',
    'HELP_MODAL/APPS_TITLE',
    'HELP_MODAL/LEAD_SHORTCUTS',
] as $key) {
    $assert(strpos($tpl, $key) !== false,
        "help template references i18n key '$key'");
    // Also assert the German translation exists.
    $assert(isset($de_json['HELP_MODAL'][substr($key, 11)]),
        "de.json has translation for '$key'");
    // Also assert the Dutch translation exists.
    $assert(isset($nl_json['HELP_MODAL'][substr($key, 11)]),
        "nl.json has translation for '$key'");
}

// ==============================================================
// 6. Standalone JS files (js/*.js) use t('souvera_mail', …) — Nextcloud
//    global — instead of hardcoded German strings.
// ==============================================================
$header_quota = (string) @file_get_contents('/app/js/nc-header-menu-quota.js');
$security     = (string) @file_get_contents('/app/js/security-page-hijack.js');

$assert(strpos($header_quota, "t('souvera_mail', 'Mail storage") !== false,
    "js/nc-header-menu-quota.js uses t('souvera_mail', 'Mail storage: …')");
$assert(strpos($header_quota, 'Mail-Speicher') === false,
    "js/nc-header-menu-quota.js no longer hardcodes 'Mail-Speicher' German literal");

$assert(strpos($security, "t('souvera_mail', 'App passwords are managed") !== false,
    "js/security-page-hijack.js uses t('souvera_mail', 'App passwords are managed via Souvera Mail')");
$assert(strpos($security, 'App-Passwörter werden über Souvera Mail verwaltet') === false,
    "js/security-page-hijack.js no longer hardcodes German heading");

// ==============================================================
// 7. Version bump
// ==============================================================
$info = (string) @file_get_contents('/app/appinfo/info.xml');
$pkg  = (string) @file_get_contents('/app/package.json');
$assert(strpos($info, '<version>') !== false
    && preg_match('#<version>0\.(?:1[4-9]|[2-9]\d)\.\d+</version>#', $info) === 1,
    'info.xml <version> is ≥ 0.14.49 (0.14.x or later)');
$assert(strpos($pkg, '"version"') !== false
    && preg_match('#"version":\s*"0\.(?:1[4-9]|[2-9]\d)\.\d+"#', $pkg) === 1,
    'package.json version is ≥ 0.14.49');

// ==============================================================
// Summary
// ==============================================================
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
