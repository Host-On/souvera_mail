<?php
/**
 * Souvera Mail v0.15.0 — External POP3/IMAP/SMTP account support.
 *
 * Regression pins for the entire feature stack:
 *   1. Contract document exists and defines the ExternalAccountsConfigService FQN.
 *   2. Consumer service (ExternalAccountsConfig) gracefully degrades when Central
 *      is not installed → feature is OFF, safe defaults returned.
 *   3. OCC commands registered and loadable (Status, ListAccounts, Revoke).
 *   4. REST routes registered (status, preset, providers, consent).
 *   5. Plugin hook `filter.action-params` wired for DoAccount* enforcement.
 *   6. Provider presets contain the top-10 German + international providers.
 *   7. i18n keys (EXTERNAL_ACCOUNTS/*, HELP_MODAL/TAB_EXTERNAL, EXT_*)
 *      present in EN, DE, NL.
 *   8. F1 help modal has the new "External mailboxes" tab.
 *   9. JS enricher `external-accounts.js` uses i18n helper (no hardcoded strings).
 *  10. EngineHelper syncs the Central master switch AND per-user group check.
 *  11. Version bump to 0.15.0 in package.json + info.xml.
 *  12. New OCC commands referenced in appinfo/info.xml <commands>.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
$a = function (bool $cond, string $msg) use (&$failures, &$passes): void {
    if ($cond) { $passes[] = $msg; echo "PASS: $msg\n"; }
    else { $failures[] = $msg; echo "FAIL: $msg\n"; }
};

// ==============================================================
// 1. Contract document
// ==============================================================
$doc = (string) @file_get_contents('/app/docs/SHARED_EXTERNAL_ACCOUNTS.md');
$a($doc !== '', 'docs/SHARED_EXTERNAL_ACCOUNTS.md exists');
foreach ([
    'OCA\\SouveraCentral\\Service\\ExternalAccountsConfigService',
    'isEnabled()',
    'getAllowedGroups()',
    'getMaxAccountsPerUser()',
    'isMigrationHandoffEnabled()',
    'isSmtpFailGuardEnabled()',
    'isConsentRequired()',
    'isAllowedForUser',
    'snapshot()',
    'souvera_central:external:enable',
    'souvera_central:external:disable',
    'souvera_central:external:status',
    'souvera_central:external:configure',
] as $needle) {
    $a(\str_contains($doc, $needle), "contract mentions '$needle'");
}

// ==============================================================
// 2. Consumer service — files exist and PHP-lint clean
// ==============================================================
foreach ([
    '/app/lib/Service/ExternalAccountsConfig.php',
    '/app/lib/Service/ExternalAccountsFailGuard.php',
    '/app/lib/Service/ExternalAccountsProviderPresets.php',
    '/app/lib/Controller/ExternalAccountsController.php',
    '/app/lib/Command/External/Status.php',
    '/app/lib/Command/External/ListAccounts.php',
    '/app/lib/Command/External/Revoke.php',
] as $f) {
    $a(\is_readable($f), "consumer file exists: $f");
    $lintResult = \shell_exec("php -l " . \escapeshellarg($f) . " 2>&1");
    $a(\is_string($lintResult) && \str_contains($lintResult, 'No syntax errors'),
        "$f is lint-clean");
}

// ==============================================================
// 3. Consumer service class shape
// ==============================================================
$svc = (string) \file_get_contents('/app/lib/Service/ExternalAccountsConfig.php');
$a(\str_contains($svc, 'ExternalAccountsConfig'),
    'ExternalAccountsConfig class defined');
$a(\str_contains($svc, "'OCA\\\\SouveraCentral\\\\Service\\\\ExternalAccountsConfigService'"),
    'CENTRAL_SERVICE_FQN constant points to the documented Central FQN');
foreach (['isEnabled', 'getAllowedGroups', 'getMaxAccountsPerUser',
    'isMigrationHandoffEnabled', 'isSmtpFailGuardEnabled', 'isConsentRequired',
    'isAllowedForUser', 'snapshot'] as $m) {
    $a(\str_contains($svc, "public function $m"),
        "ExternalAccountsConfig::$m() is public");
}
$a(\str_contains($svc, "return (bool) self::DEFAULTS['enabled']"),
    'isEnabled returns safe default (false) when Central is unavailable');
$a(\preg_match("/'max_per_user'\\s*=>\\s*3/", $svc) === 1,
    'safe default cap = 3');

// ==============================================================
// 4. Fail-guard service
// ==============================================================
$guard = (string) \file_get_contents('/app/lib/Service/ExternalAccountsFailGuard.php');
foreach (['recordFailure', 'recordSuccess', 'status', 'listDeactivated', 'reset'] as $m) {
    $a(\str_contains($guard, "public function $m"),
        "ExternalAccountsFailGuard::$m() is public");
}
$a(\str_contains($guard, 'WINDOW_S   = 86400'),
    'FailGuard window is 24 hours (86400 s)');
$a(\str_contains($guard, 'LIMIT      = 3'),
    'FailGuard threshold is 3 consecutive failures');
$a(\str_contains($guard, "'ext_smtp_fail.'"),
    'FailGuard stores state under a namespaced appconfig prefix');

// ==============================================================
// 5. Provider presets
// ==============================================================
$presets = (string) \file_get_contents('/app/lib/Service/ExternalAccountsProviderPresets.php');
foreach ([
    "'web.de'", "'gmx.de'", "'t-online.de'", "'freenet.de'", "'1und1.de'",
    "'mail.de'", "'posteo.de'", "'mailbox.org'",
    "'gmail.com'", "'outlook.com'", "'yahoo.com'",
] as $p) {
    $a(\str_contains($presets, $p), "provider preset table includes $p");
}
$a(\str_contains($presets, 'GMAIL_APP_PASSWORD'),
    'Gmail preset carries GMAIL_APP_PASSWORD warning marker');
$a(\str_contains($presets, 'OUTLOOK_MODERN_AUTH'),
    'Outlook preset carries OUTLOOK_MODERN_AUTH warning marker');
$a(\str_contains($presets, "'secureimap.t-online.de'"),
    't-online.de IMAP host uses secureimap.t-online.de (not imap.t-online.de)');

// ==============================================================
// 6. OCC commands registered in info.xml
// ==============================================================
$info = (string) \file_get_contents('/app/appinfo/info.xml');
foreach ([
    'OCA\\SouveraMail\\Command\\External\\Status',
    'OCA\\SouveraMail\\Command\\External\\ListAccounts',
    'OCA\\SouveraMail\\Command\\External\\Revoke',
] as $cmd) {
    $a(\str_contains($info, $cmd), "info.xml registers command $cmd");
}

// Command definitions themselves
foreach ([
    '/app/lib/Command/External/Status.php'       => 'souvera_mail:external:status',
    '/app/lib/Command/External/ListAccounts.php' => 'souvera_mail:external:list',
    '/app/lib/Command/External/Revoke.php'       => 'souvera_mail:external:revoke',
] as $f => $name) {
    $body = (string) \file_get_contents($f);
    $a(\str_contains($body, "setName('$name')"),
        "$f sets command name '$name'");
}

$statusCmd = (string) \file_get_contents('/app/lib/Command/External/Status.php');
$a(\str_contains($statusCmd, 'central_present')
    || \str_contains($statusCmd, 'souvera_central is not installed'),
    'external:status hints about missing souvera_central for operators');

$revokeCmd = (string) \file_get_contents('/app/lib/Command/External/Revoke.php');
$a(\str_contains($revokeCmd, "--revoke"),
    'external:revoke has --revoke option');
$a(\str_contains($revokeCmd, "--reset"),
    'external:revoke has --reset option');
$a(\str_contains($revokeCmd, "--confirm"),
    'external:revoke requires --confirm as safety belt');

// ==============================================================
// 7. REST routes
// ==============================================================
$routes = (string) \file_get_contents('/app/appinfo/routes.php');
foreach ([
    'externalAccounts#status'          => '/external/status',
    'externalAccounts#preset'          => '/external/preset',
    'externalAccounts#providers'       => '/external/providers',
    'externalAccounts#recordConsent'   => '/external/consent',
] as $name => $url) {
    $a(\str_contains($routes, "'name' => '$name'"),
        "routes.php exposes '$name'");
    $a(\str_contains($routes, "'url' => '$url'"),
        "routes.php maps '$name' to '$url'");
}

// ==============================================================
// 8. Plugin hook wired
// ==============================================================
$plugin = (string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
$a(\str_contains($plugin, "'filter.action-params', 'FilterAdditionalAccountAction'"),
    'plugin registers filter.action-params → FilterAdditionalAccountAction');
$a(\str_contains($plugin, 'DoAccountSetup')
    && \str_contains($plugin, 'DoAccountDelete')
    && \str_contains($plugin, 'DoAccountSwitch'),
    'FilterAdditionalAccountAction handles DoAccountSetup, DoAccountDelete, DoAccountSwitch');
$a(\str_contains($plugin, 'getMaxAccountsPerUser'),
    'plugin enforces per-user cap via Central config');
$a(\str_contains($plugin, 'isAllowedForUser'),
    'plugin re-checks group restriction (belt-and-braces)');
$a(\str_contains($plugin, "'category' => 'external_accounts'"),
    'plugin writes audit-log entries under category=external_accounts');

// New JS + CSS registered
$a(\str_contains($plugin, "\$this->addJs('js/external-accounts.js')"),
    'plugin registers js/external-accounts.js');
$a(\str_contains($plugin, "\$this->addCss('css/external-accounts.css')"),
    'plugin registers css/external-accounts.css');

// FilterAppData exposes the new endpoints
$a(\str_contains($plugin, 'SmailExternalStatusUrl'),
    'FilterAppData surfaces SmailExternalStatusUrl for the JS enricher');
$a(\str_contains($plugin, 'SmailExternalPresetUrl'),
    'FilterAppData surfaces SmailExternalPresetUrl');
$a(\str_contains($plugin, 'SmailExternalConsentUrl'),
    'FilterAppData surfaces SmailExternalConsentUrl');
$a(\str_contains($plugin, 'SmailHelpExtMaxPerUser'),
    'FilterAppData surfaces SmailHelpExtMaxPerUser (renders in F1 help modal)');

// ==============================================================
// 9. EngineHelper sync
// ==============================================================
$eh = (string) \file_get_contents('/app/lib/Util/EngineHelper.php');
$a(\str_contains($eh, 'ExternalAccountsConfig'),
    'EngineHelper imports the ExternalAccountsConfig service');
$a(\str_contains($eh, "Set('webmail', 'allow_additional_accounts'"),
    'EngineHelper writes webmail.allow_additional_accounts on every request');
$a(\str_contains($eh, 'isAllowedForUser'),
    'EngineHelper applies per-user group check in startApp()');

// ==============================================================
// 10. i18n keys in EN / DE / NL plugin lang files
// ==============================================================
foreach (['en', 'de', 'nl'] as $lang) {
    $p = "/app/app/smail/v/current/app/plugins/nextcloud/langs/$lang.json";
    $data = \json_decode((string) \file_get_contents($p), true);
    $a(\is_array($data), "$lang.json parses");
    // EXTERNAL_ACCOUNTS section
    foreach ([
        'CONSENT_TITLE', 'CONSENT_BODY', 'CONSENT_ACCEPT', 'CONSENT_CANCEL',
        'WARN_GMAIL', 'WARN_OUTLOOK', 'WARN_YAHOO',
        'CAP_REACHED', 'FEATURE_DISABLED',
    ] as $k) {
        $a(!empty($data['EXTERNAL_ACCOUNTS'][$k] ?? ''),
            "$lang.json → EXTERNAL_ACCOUNTS/$k is populated");
    }
    // HELP_MODAL/EXT_* section
    foreach ([
        'TAB_EXTERNAL', 'EXT_LEAD', 'EXT_HOW_TITLE',
        'EXT_HOW_STEP_1', 'EXT_HOW_STEP_2', 'EXT_HOW_STEP_3',
        'EXT_HOW_STEP_4', 'EXT_HOW_STEP_5',
        'EXT_PRIVACY_TITLE', 'EXT_PRIVACY_BODY',
        'EXT_LIMITS_TITLE', 'EXT_LIMITS_MAX', 'EXT_LIMITS_GUARD',
        'EXT_PROVIDERS_TITLE',
        'EXT_GMAIL_NOTE', 'EXT_OUTLOOK_NOTE', 'EXT_WEBDE_NOTE', 'EXT_TONLINE_NOTE',
    ] as $k) {
        $a(!empty($data['HELP_MODAL'][$k] ?? ''),
            "$lang.json → HELP_MODAL/$k is populated");
    }
}

// German + Dutch translations differ from English (sanity check)
$en = \json_decode((string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/langs/en.json'), true);
$de = \json_decode((string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/langs/de.json'), true);
$nl = \json_decode((string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/langs/nl.json'), true);
$a($en['HELP_MODAL']['TAB_EXTERNAL'] !== $de['HELP_MODAL']['TAB_EXTERNAL'],
    'EN and DE translations for HELP_MODAL/TAB_EXTERNAL differ');
$a($en['HELP_MODAL']['TAB_EXTERNAL'] !== $nl['HELP_MODAL']['TAB_EXTERNAL'],
    'EN and NL translations for HELP_MODAL/TAB_EXTERNAL differ');
$a($de['EXTERNAL_ACCOUNTS']['CONSENT_ACCEPT'] === 'Akzeptieren und fortfahren',
    'DE CONSENT_ACCEPT reads "Akzeptieren und fortfahren"');
$a($nl['EXTERNAL_ACCOUNTS']['CONSENT_ACCEPT'] === 'Accepteren en doorgaan',
    'NL CONSENT_ACCEPT reads "Accepteren en doorgaan"');

// ==============================================================
// 11. F1 help modal template
// ==============================================================
$tpl = (string) \file_get_contents('/app/app/smail/v/current/app/templates/Views/User/PopupsKeyboardShortcutsHelp.html');
$a(\str_contains($tpl, 'id="tab-help-external"'),
    'help modal has new tab "tab-help-external"');
$a(\str_contains($tpl, 'HELP_MODAL/TAB_EXTERNAL'),
    'help modal tab label uses HELP_MODAL/TAB_EXTERNAL i18n key');
foreach ([
    'HELP_MODAL/EXT_LEAD',
    'HELP_MODAL/EXT_HOW_TITLE',
    'HELP_MODAL/EXT_HOW_STEP_1',
    'HELP_MODAL/EXT_PRIVACY_TITLE',
    'HELP_MODAL/EXT_LIMITS_MAX',
    'HELP_MODAL/EXT_LIMITS_GUARD',
    'HELP_MODAL/EXT_GMAIL_NOTE',
    'HELP_MODAL/EXT_OUTLOOK_NOTE',
    'HELP_MODAL/EXT_WEBDE_NOTE',
    'HELP_MODAL/EXT_TONLINE_NOTE',
    'SmailHelpExtMaxPerUser',
] as $k) {
    $a(\str_contains($tpl, $k), "help modal template references $k");
}

// ==============================================================
// 12. JS enricher — safe helpers, endpoints, no hardcoded German
// ==============================================================
$js  = (string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/external-accounts.js');
$a(\str_contains($js, 'var i18n = function'),
    'external-accounts.js defines safe i18n helper');
$a(\str_contains($js, 'STATUS_URL'),
    'external-accounts.js caches feature-state URL');
$a(\str_contains($js, "'EXTERNAL_ACCOUNTS/CONSENT_TITLE'"),
    'external-accounts.js shows consent modal via i18n key CONSENT_TITLE');
$a(\str_contains($js, "'EXTERNAL_ACCOUNTS/WARN_GMAIL'"),
    'external-accounts.js renders Gmail warning via i18n');
$a(\str_contains($js, "'EXTERNAL_ACCOUNTS/WARN_OUTLOOK'"),
    'external-accounts.js renders Outlook warning via i18n');
$a(\str_contains($js, 'fetchFeatureState'),
    'external-accounts.js gates UI on GET /external/status');
$a(\str_contains($js, 'fetchPreset'),
    'external-accounts.js resolves provider preset lazily on email blur');
$a(\str_contains($js, 'recordConsent')
    && \str_contains($js, 'CONSENT_URL'),
    'external-accounts.js POSTs to /external/consent');
$a(!\str_contains($js, 'Postfach hinzu')
    && !\str_contains($js, 'externe Postf'),
    'external-accounts.js contains no hardcoded German literals (all via i18n)');

// ==============================================================
// 13. Version bumps
// ==============================================================
$pkg = (string) \file_get_contents('/app/package.json');
$a(\str_contains($pkg, '"version": "0.15.0"'),
    'package.json version bumped to 0.15.0');
$a(\str_contains($info, '<version>0.15.0</version>'),
    'appinfo/info.xml version bumped to 0.15.0');

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
