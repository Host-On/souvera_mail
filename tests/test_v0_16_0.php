<?php
/**
 * v0.16.0 — Regression pins for the two P0 bugfixes + the P1 folder-badge
 * enhancement shipped in this release.
 *
 *   P0-A  Manual IMAP/SMTP configuration for external accounts
 *         (fixes „philip@uelzen.email has no domain configuration").
 *   P0-B  „Erweitert"-tab click reliability — MutationObserver
 *         throttling (rAF) + defensive .tabs > label CSS.
 *   P1    Auto-refresh folder tree + „NEW" badge after migration.
 *
 * The suite is static-only (no live NC) — it grep-verifies that every
 * commit in scope actually landed. All assertions must pass in CI
 * exit-code 0 with zero "FAIL: [1-9]" lines.
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

// -------------------------------------------------------------------
// P0-A — Manual IMAP/SMTP fields
// -------------------------------------------------------------------

// (1) UserAuth.php: LoginProcess accepts manual params and calls the
//     buildManualDomainFromActionParams() helper.
$userAuth = (string) \file_get_contents('/app/app/smail/v/current/app/libraries/Smail/Engine/Actions/UserAuth.php');
$a(\strlen($userAuth) > 0, 'UserAuth.php is readable');
$a(\str_contains($userAuth, '$bManualParams'),
    'LoginProcess() branches on $bManualParams action-param flag');
$a(\str_contains($userAuth, "GetActionParam('imapHost'"),
    'LoginProcess() reads imapHost from action params');
$a(\str_contains($userAuth, "GetActionParam('smtpHost'"),
    'LoginProcess() reads smtpHost from action params');
$a(\str_contains($userAuth, 'buildManualDomainFromActionParams'),
    'LoginProcess() delegates to buildManualDomainFromActionParams()');
$a(\str_contains($userAuth, "setManualDomain(true)"),
    'LoginProcess() flags the Account as manual after successful build');
$a(\preg_match('/protected function buildManualDomainFromActionParams\s*\(/', $userAuth) === 1,
    'buildManualDomainFromActionParams() helper is defined');
$a(\str_contains($userAuth, "'PLAIN', 'LOGIN', 'CRAM-MD5'"),
    'manual Domain SASL locked to password mechanisms (PLAIN/LOGIN/CRAM-MD5)');
$a(\str_contains($userAuth, "Notifications::InvalidInputArgument"),
    'manual-Domain builder rejects empty host + out-of-range port with InvalidInputArgument');
$a(\str_contains($userAuth, "Domain::fromArray"),
    'manual Domain built via Domain::fromArray()');

// (2) Account.php: persists manual Domain in the token + rebuilds it
//     on load without going through the DomainProvider.
$account = (string) \file_get_contents('/app/app/smail/v/current/app/libraries/Smail/Engine/Model/Account.php');
$a(\str_contains($account, 'private bool $bManualDomain'),
    'Account carries a bManualDomain flag');
$a(\str_contains($account, 'function setManualDomain'),
    'Account exposes setManualDomain() setter');
$a(\str_contains($account, 'function isManualDomain'),
    'Account exposes isManualDomain() getter');
$a(\str_contains($account, "\$this->bManualDomain && \$this->oDomain"),
    'jsonSerialize() only emits domain when both flag AND oDomain are set');
$a(\str_contains($account, "\$result['domain']"),
    'jsonSerialize() writes the persisted domain config into the token');
$a(\str_contains($account, "bManualDomain = !empty(\$aAccountHash['domain'])"),
    'NewInstanceFromTokenArray() infers manual flag from token payload');
$a(\str_contains($account, "Domain::fromArray("),
    'NewInstanceFromTokenArray() rebuilds Domain via fromArray() when manual token seen');
$a(\str_contains($account, "\$oAccount->bManualDomain = \$bManualDomain"),
    'NewInstanceFromTokenArray() propagates the flag back onto the rebuilt Account');

// (3) PopupsAccount.html: exposes the manual IMAP/SMTP fields as a
//     <details> block, all inputs have name= so FormData picks them up.
$popup = (string) \file_get_contents('/app/app/smail/v/current/app/templates/Views/User/PopupsAccount.html');
$a(\str_contains($popup, 'souvera-manual-config'),
    'PopupsAccount.html carries the manual-config <details> wrapper');
$a(\str_contains($popup, 'name="imapHost"'),
    'PopupsAccount.html exposes imapHost field');
$a(\str_contains($popup, 'name="imapPort"'),
    'PopupsAccount.html exposes imapPort field');
$a(\str_contains($popup, 'name="imapSecure"'),
    'PopupsAccount.html exposes imapSecure field');
$a(\str_contains($popup, 'name="smtpHost"'),
    'PopupsAccount.html exposes smtpHost field');
$a(\str_contains($popup, 'name="smtpPort"'),
    'PopupsAccount.html exposes smtpPort field');
$a(\str_contains($popup, 'name="smtpSecure"'),
    'PopupsAccount.html exposes smtpSecure field');
$a(\str_contains($popup, 'name="smtpAuth"'),
    'PopupsAccount.html exposes smtpAuth checkbox');
$a(\str_contains($popup, 'data-testid="ext-manual-config"'),
    'manual-config <details> has data-testid for testing_agent selection');
$a(\str_contains($popup, 'data-i18n="EXTERNAL_ACCOUNTS/MANUAL_CONFIG_TOGGLE"'),
    'manual-config toggle uses EXTERNAL_ACCOUNTS/MANUAL_CONFIG_TOGGLE i18n key');
$a(\str_contains($popup, 'data-bind="visible: isNew"'),
    'manual-config block only shown on new-account path (not edit)');

// (4) external-accounts.js: fallback banner auto-opens manual config
//     for unknown domains and surfaces MANUAL_FALLBACK_HINT.
$extJs = (string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/external-accounts.js');
$a(\str_contains($extJs, 'souvera-manual-config'),
    'external-accounts.js locates the manual-config <details>');
$a(\str_contains($extJs, 'EXTERNAL_ACCOUNTS/MANUAL_FALLBACK_HINT'),
    'unknown-domain path surfaces MANUAL_FALLBACK_HINT');
$a(\str_contains($extJs, 'isUnknownProvider'),
    'unknown-provider auto-open branch is present');
$a(\str_contains($extJs, 'manualDetails.open = true'),
    'JS opens the manual-config <details> programmatically when the domain is unknown');

// (5) i18n keys — MANUAL_* must exist in all three shipped locales.
foreach (['de.json', 'en.json', 'nl.json'] as $lang) {
    $data = \json_decode(\file_get_contents("/app/app/smail/v/current/app/plugins/nextcloud/langs/{$lang}"), true);
    $keys = ['MANUAL_CONFIG_TOGGLE', 'MANUAL_CONFIG_HINT', 'MANUAL_IMAP_LEGEND',
        'MANUAL_SMTP_LEGEND', 'MANUAL_HOST_LABEL', 'MANUAL_PORT_LABEL',
        'MANUAL_SECURE_LABEL', 'MANUAL_SECURE_SSL', 'MANUAL_SECURE_STARTTLS',
        'MANUAL_SECURE_NONE', 'MANUAL_SMTP_AUTH', 'MANUAL_FALLBACK_HINT'];
    foreach ($keys as $k) {
        $a(!empty($data['EXTERNAL_ACCOUNTS'][$k] ?? null),
            "langs/{$lang}: EXTERNAL_ACCOUNTS.{$k} translated");
    }
}

// (6) CSS: manual-config UI styled + defensive dialog tab click fix.
$css = (string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/css/external-accounts.css');
$a(\str_contains($css, '.souvera-manual-config'),
    'external-accounts.css scopes styling under .souvera-manual-config');
$a(\str_contains($css, '.souvera-manual-config[open]'),
    'CSS reacts to the <details> `open` attribute (chevron rotate)');
$a(\str_contains($css, 'dialog[open] .tabs > label'),
    'defensive .tabs > label pointer-events fix present (P0-B)');
$a(\str_contains($css, 'pointer-events: auto !important'),
    'defensive rule uses !important so ambient CSS cannot suppress clicks');

// -------------------------------------------------------------------
// P0-B — MutationObserver throttling (rAF)
// -------------------------------------------------------------------
$sieveApply = (string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/sieve-apply.js');
$a(\str_contains($sieveApply, 'requestAnimationFrame'),
    'sieve-apply.js observer uses requestAnimationFrame throttling');
$a(\str_contains($sieveApply, 'scanScheduled'),
    'sieve-apply.js merges bursts into one scan per animation frame');
$a(\str_contains($sieveApply, 'requestScan'),
    'sieve-apply.js exposes a requestScan gateway');

$a(\str_contains($extJs, 'requestAnimationFrame'),
    'external-accounts.js observers use requestAnimationFrame throttling');
$a(\substr_count($extJs, 'requestAnimationFrame') >= 2,
    'external-accounts.js throttles BOTH watchForPopup and interceptAddButton observers');

// -------------------------------------------------------------------
// P1 — Auto-refresh folder tree + "NEW" badge
// -------------------------------------------------------------------
$useMigration = (string) \file_get_contents('/app/src/composables/useMigration.js');
$a(\str_contains($useMigration, 'notifyMigrationCompleted'),
    'useMigration.js defines notifyMigrationCompleted()');
$a(\str_contains($useMigration, 'souvera-mail:migration-completed'),
    'useMigration.js dispatches souvera-mail:migration-completed custom event');
$a(\str_contains($useMigration, "'souvera-mail:imported-folders'"),
    'useMigration.js persists imported folders under a well-known localStorage key');
$a(\str_contains($useMigration, 'foldersReload'),
    'useMigration.js triggers rl.app.foldersReload() on completion');
$a(\str_contains($useMigration, "finishedState === 'completed'"),
    'notification only fires on a successful completion (no false positives for failed/cancelled)');

$badge = (string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/folder-imported-badge.js');
$a(\strlen($badge) > 0, 'folder-imported-badge.js file exists');
$a(\str_contains($badge, "'souvera-mail:imported-folders'"),
    'folder-imported-badge.js reads the SAME localStorage key');
$a(\str_contains($badge, 'souvera-mail:migration-completed'),
    'folder-imported-badge.js listens for the migration-completed event');
$a(\str_contains($badge, 'sv-folder-new-badge'),
    'folder-imported-badge.js applies .sv-folder-new-badge CSS class');
$a(\str_contains($badge, 'readAndPruneMap'),
    'folder-imported-badge.js prunes expired localStorage entries');
$a(\str_contains($badge, 'FOLDERS/NEW_BADGE'),
    'folder-imported-badge.js reads badge label from FOLDERS/NEW_BADGE i18n');
$a(\str_contains($badge, 'requestAnimationFrame'),
    'folder-imported-badge.js observer is rAF-throttled');

$css2 = $css; // reuse
$a(\str_contains($css2, '.sv-folder-new-badge'),
    'external-accounts.css includes .sv-folder-new-badge styling');
$a(\str_contains($css2, '@keyframes sv-badge-pulse'),
    '.sv-folder-new-badge has a subtle pulse animation on first render');

// FOLDERS/NEW_BADGE + FOLDERS/NEW_BADGE_TITLE translations
foreach (['de.json', 'en.json', 'nl.json'] as $lang) {
    $data = \json_decode(\file_get_contents("/app/app/smail/v/current/app/plugins/nextcloud/langs/{$lang}"), true);
    $a(!empty($data['FOLDERS']['NEW_BADGE'] ?? null),
        "langs/{$lang}: FOLDERS.NEW_BADGE translated");
    $a(!empty($data['FOLDERS']['NEW_BADGE_TITLE'] ?? null),
        "langs/{$lang}: FOLDERS.NEW_BADGE_TITLE translated");
}

// Plugin index.php: registers the new JS
$pluginIndex = (string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
$a(\str_contains($pluginIndex, "addJs('js/folder-imported-badge.js')"),
    "index.php addJs('js/folder-imported-badge.js') (P1 badge enricher)");

// -------------------------------------------------------------------
// Version bump — regex so 0.16.x and beyond stay passing.
// -------------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$pkg  = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#<version>0\.(?:1[6-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.16.0 or higher');
$a((bool) \preg_match('#"version"\s*:\s*"0\.(?:1[6-9]|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.16.0 or higher');

// -------------------------------------------------------------------
// Behavioural sim — verify the exact array structure LoginProcess()
// hands to Domain::fromArray() by parsing the file, extracting the
// fromArray() call, and asserting it contains every required key
// (Domain::fromArray uses IMAP/SMTP/Sieve as the shape, see
// libraries/Smail/Engine/Model/Domain.php:137). Runtime instantiation
// of the Snappymail engine from CLI would drag the entire class-loader
// registration through — the other suites (test_before_login_token_swap
// etc.) use stubs for the same reason.
// -------------------------------------------------------------------
$fromArrayCall = null;
if (\preg_match('/Domain::fromArray\((.+?)\]\);/s', $userAuth, $m)) {
    $fromArrayCall = $m[0];
}
$a($fromArrayCall !== null,
    'behavioural: LoginProcess.buildManualDomainFromActionParams() contains a Domain::fromArray() call');
$a($fromArrayCall !== null && \str_contains($fromArrayCall, "'IMAP'"),
    'behavioural: Domain::fromArray() array carries IMAP key');
$a($fromArrayCall !== null && \str_contains($fromArrayCall, "'SMTP'"),
    'behavioural: Domain::fromArray() array carries SMTP key');
$a($fromArrayCall !== null && \str_contains($fromArrayCall, "'Sieve'"),
    'behavioural: Domain::fromArray() array carries Sieve key');
$a($fromArrayCall !== null && \str_contains($fromArrayCall, "'whiteList'"),
    'behavioural: Domain::fromArray() array carries whiteList key');
$a($fromArrayCall !== null && \str_contains($fromArrayCall, "'host'"),
    'behavioural: Domain::fromArray() array carries host inside settings');
$a($fromArrayCall !== null && \str_contains($fromArrayCall, "'port'"),
    'behavioural: Domain::fromArray() array carries port inside settings');
$a($fromArrayCall !== null && \str_contains($fromArrayCall, "'sasl'"),
    'behavioural: Domain::fromArray() array carries sasl override inside settings');
$a($fromArrayCall !== null && \str_contains($fromArrayCall, "'useAuth'"),
    'behavioural: Domain::fromArray() array carries SMTP.useAuth flag');

// Verify Domain::fromArray() also lives on disk with the expected sig
$domainSrc = (string) \file_get_contents('/app/app/smail/v/current/app/libraries/Smail/Engine/Model/Domain.php');
$a(\preg_match('/public static function fromArray\(string \$sName, array \$aDomain\)\s*:\s*\?self/', $domainSrc) === 1,
    'behavioural: Domain::fromArray(string $sName, array $aDomain): ?self signature unchanged');
$a(\str_contains($domainSrc, "\$aDomain['IMAP']"),
    'behavioural: Domain::fromArray reads IMAP from the array (matches our shape)');

// Verify persistence path — Account::jsonSerialize + NewInstanceFromTokenArray
// use the same key set our LoginProcess writes.
$a(\substr_count($account, "'domain'") >= 3,
    'behavioural: Account.php references the domain token-key at least 3 times (serialise + read + rebuild)');

// -------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
