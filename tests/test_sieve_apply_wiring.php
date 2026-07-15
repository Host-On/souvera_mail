<?php
declare(strict_types=1);

/**
 * v0.14.37 — "Filter nachträglich anwenden" wiring.
 *
 * Verifies the full delivery chain without needing a live NC:
 *   routes.php  →  SieveApplyController  →  SieveApplyService  →
 *   MiniInterpreter  →  JMAP  →  UI (js/sieve-apply.js registered in
 *   the Nextcloud plugin + endpoints exposed on rl.settings.Nextcloud).
 *
 * The interpreter itself is covered by test_sieve_mini_interpreter.php
 * — this test focuses on the plumbing.
 */

$repo = \dirname(__DIR__);
\spl_autoload_register(static function (string $class) use ($repo): void {
    $prefix = 'OCA\\SouveraMail\\';
    if (!\str_starts_with($class, $prefix)) { return; }
    $rel = \substr($class, \strlen($prefix));
    $path = $repo . '/lib/' . \str_replace('\\', '/', $rel) . '.php';
    if (\is_file($path)) { require $path; }
});
require $repo . '/lib/Sieve/Types.php';

$passes = 0;
$failures = [];
$check = static function (bool $cond, string $msg) use (&$passes, &$failures): void {
    if ($cond) { $passes++; } else { $failures[] = $msg; }
};

// -------------------------------------------------------------------
// 1. Routes are declared and point at the new controller.
// -------------------------------------------------------------------
$routes = require $repo . '/appinfo/routes.php';
$byName = [];
foreach ($routes['routes'] as $r) { $byName[$r['name']] = $r; }

$check(isset($byName['sieveApply#folders']),
    "route sieveApply#folders declared");
$check(($byName['sieveApply#folders']['url'] ?? '') === '/sieve/apply/folders',
    "sieveApply#folders URL is /sieve/apply/folders");
$check(($byName['sieveApply#folders']['verb'] ?? '') === 'GET',
    "sieveApply#folders is GET");

$check(isset($byName['sieveApply#apply']),
    "route sieveApply#apply declared");
$check(($byName['sieveApply#apply']['url'] ?? '') === '/sieve/apply',
    "sieveApply#apply URL is /sieve/apply");
$check(($byName['sieveApply#apply']['verb'] ?? '') === 'POST',
    "sieveApply#apply is POST (has real side-effects: moves, redirects)");

// -------------------------------------------------------------------
// 2. Controller: has the expected shape (NoAdminRequired, JSON out).
// -------------------------------------------------------------------
$ctrlSrc = (string) \file_get_contents($repo . '/lib/Controller/SieveApplyController.php');
$check(\str_contains($ctrlSrc, 'use OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired;'),
    "controller uses NoAdminRequired attribute");
$check(\preg_match('/#\[NoAdminRequired\][\s\S]{0,200}public function folders/', $ctrlSrc) === 1,
    "folders() endpoint is NoAdminRequired");
$check(\preg_match('/#\[NoAdminRequired\][\s\S]{0,200}public function apply/', $ctrlSrc) === 1,
    "apply() endpoint is NoAdminRequired");
$check(\str_contains($ctrlSrc, 'STATUS_UNAUTHORIZED'),
    "controller returns 401 for anonymous callers");
// Audit trail
$check(\str_contains($ctrlSrc, "sieve-apply for user="),
    "controller logs a summary line for audit trail (moves/redirects visible in nextcloud.log)");

// -------------------------------------------------------------------
// 3. Service: JMAP calls we depend on are present, correct capabilities.
// -------------------------------------------------------------------
$svcSrc = (string) \file_get_contents($repo . '/lib/Service/SieveApplyService.php');
foreach (['Mailbox/get', 'Email/query', 'Email/get', 'Email/set',
          'EmailSubmission/set', 'Identity/get'] as $method) {
    $check(\str_contains($svcSrc, $method),
        "service uses JMAP method {$method}");
}
$check(\str_contains($svcSrc, 'urn:ietf:params:jmap:submission'),
    "service requests the JMAP submission capability for EmailSubmission/set");
// v0.14.42: every Mailbox/*, Email/query|get|set call must add the mail
// capability to the top-level `using` array. Stalwart 0.16 rejects
// method invocations whose required capability is missing from `using`
// with `unknownMethod` (operator report 2026-02-19).
$check(\str_contains($svcSrc, "CAP_MAIL = 'urn:ietf:params:jmap:mail'"),
    "service declares CAP_MAIL constant for Mailbox/*, Email/* JMAP capability");
$check(
    \substr_count($svcSrc, '[self::CAP_MAIL]') >= 5,
    "service passes [CAP_MAIL] on every JMAP call that needs it (fetchMailboxes, listFolders, queryMessageIds, fetchMessageFacts, executeMoves, executeFlagAdds — got: "
        . \substr_count($svcSrc, '[self::CAP_MAIL]') . ')'
);
$check(\str_contains($svcSrc, 'inMailbox'),
    "service filters Email/query by inMailbox");
$check(\str_contains($svcSrc, 'MAX_LIMIT = 5000') && \str_contains($svcSrc, 'DEFAULT_LIMIT = 5000'),
    "service caps limit at 5000 (default 5000 too — server-side apply, safe to bump per operator ok)");
$check(\str_contains($svcSrc, 'includeRedirect'),
    "service takes an includeRedirect flag so callers can dry-run redirects");
$check(\str_contains($svcSrc, 'MiniInterpreter'),
    "service delegates parsing/evaluation to MiniInterpreter (subset engine)");

// Safety: `discard` maps to Trash-move rather than a hard delete.
$check(
    \preg_match('/trashId\s*!==\s*null[\s\S]{0,200}moves\[\$msg->emailId\]\s*=\s*\$trashId/', $svcSrc) === 1,
    "discard action moves to Trash mailbox (safer than Email/set destroy — user can undo)"
);

// -------------------------------------------------------------------
// 4. UI wiring: Nextcloud plugin registers the JS + exposes URLs.
// -------------------------------------------------------------------
$pluginSrc = (string) \file_get_contents($repo . '/app/smail/v/current/app/plugins/nextcloud/index.php');
$check(\str_contains($pluginSrc, "'js/sieve-apply.js'"),
    "plugin index.php registers js/sieve-apply.js");
$check(\str_contains($pluginSrc, 'SmailSieveApplyUrl'),
    "plugin exposes SmailSieveApplyUrl to the browser via rl.settings.Nextcloud");
$check(\str_contains($pluginSrc, 'SmailSieveApplyFoldersUrl'),
    "plugin exposes SmailSieveApplyFoldersUrl to the browser");
$check(\str_contains($pluginSrc, "'souvera_mail.sieveApply.apply'"),
    "SmailSieveApplyUrl is built via urlGen->linkToRoute (not hardcoded)");

// -------------------------------------------------------------------
// 5. JS enricher: exists, uses the config URLs, has data-testid hooks.
// -------------------------------------------------------------------
$jsSrc = (string) \file_get_contents($repo . '/app/smail/v/current/app/plugins/nextcloud/js/sieve-apply.js');
$check($jsSrc !== '', 'sieve-apply.js exists');
$check(\str_contains($jsSrc, 'SmailSieveApplyFoldersUrl')
    && \str_contains($jsSrc, 'SmailSieveApplyUrl'),
    "sieve-apply.js reads the endpoint URLs from rl.settings.Nextcloud");
foreach ([
    'sieve-apply-modal',
    'sieve-apply-folder-select',
    'sieve-apply-run',
    'sieve-apply-cancel',
    'sieve-apply-page-btn',           // v0.14.41 new: settings-page button
    'sieve-apply-toolbar-btn-menu',   // v0.14.41 new: dropdown menu entry
] as $tid) {
    $check(\str_contains($jsSrc, "'data-testid': '{$tid}'")
        || \str_contains($jsSrc, "data-testid=\"{$tid}\"")
        || \str_contains($jsSrc, "'data-testid', '{$tid}'"),
        "sieve-apply.js has data-testid=\"{$tid}\" for automation hooks");
}
$check(\str_contains($jsSrc, 'includeRedirect'),
    "sieve-apply.js posts includeRedirect (defaults true — operator chose 1b)");
$check(\str_contains($jsSrc, 'folderInformationMultiplyList'),
    "sieve-apply.js pings Snappymail to re-read the folder counts after success");
// v0.14.49: SMTP resend warning moved out of hardcoded JS into the
// SIEVE_APPLY/EXPLAIN i18n key. English source lives in plugin en.json
// (mentions "re-sent via SMTP"); German translation in de.json still
// says "ERNEUT per SMTP".
$sieveEn = @\file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/langs/en.json');
$sieveDe = @\file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/langs/de.json');
$check(\str_contains($jsSrc, "SIEVE_APPLY/EXPLAIN")
    && $sieveEn !== false && \stripos($sieveEn, 're-sent via SMTP') !== false
    && $sieveDe !== false && \str_contains($sieveDe, 'ERNEUT per SMTP'),
    "sieve-apply modal warns the operator that redirect resends via SMTP (via SIEVE_APPLY/EXPLAIN i18n key; DE keeps 'ERNEUT per SMTP')");

// v0.14.41: primary injection point is the Filter SETTINGS PAGE
// (not the popup) — operator asked for the button next to
// "Skript hinzufügen". Popup-footer injection was removed because
// it required clicking on the script name first — extra step the
// operator dislikes.
$check(\str_contains($jsSrc, 'PAGE_ANCHOR_SEL')
    && \str_contains($jsSrc, "SETTINGS_FILTERS/BUTTON_ADD_SCRIPT"),
    "sieve-apply.js targets the 'Skript hinzufügen' button on the filter settings page (v0.14.41 — moved from popup to page)");
$check(\str_contains($jsSrc, 'souvera-sieve-apply-page-btn'),
    "sieve-apply.js gives the page button a stable id (idempotent injection under MutationObserver)");
$check(\str_contains($jsSrc, 'filter-settings-page button injected'),
    "sieve-apply.js logs a diagnostic breadcrumb when the page button is successfully injected");
// v0.14.39 kept: dropdown-menu injection still present as backup entry point.
$check(\str_contains($jsSrc, 'top-system-dropdown-id'),
    "sieve-apply.js keeps the top-right dropdown menu entry too (belt-and-suspenders — v0.14.41 has BOTH entry points)");
$check(\str_contains($jsSrc, "'data-icon', '🔎'"),
    "sieve-apply.js uses 🔎 as menu icon");
$check(\str_contains($jsSrc, 'sv-sieve-apply-menu'),
    "sieve-apply.js tags the injected menu <li> with data-sv-sieve-apply-menu for idempotency");
$check(\str_contains($jsSrc, "souvera-mail:open-sieve-apply"),
    "sieve-apply.js exposes a `souvera-mail:open-sieve-apply` custom event");
// Negative guards: old fragile selectors must not sneak back.
$check(!\str_contains($jsSrc, '.b-popups-sieve-script'),
    "sieve-apply.js does NOT target the non-existent `.b-popups-sieve-script` class (v0.14.37 mistake)");
$check(!\str_contains($jsSrc, 'souvera-sieve-apply-popup-btn'),
    "sieve-apply.js no longer injects into the popup footer (v0.14.40 approach — operator preferred settings-page placement)");

// -------------------------------------------------------------------
// 6. `php -l` clean on the new PHP files.
// -------------------------------------------------------------------
foreach ([
    'lib/Sieve/MiniInterpreter.php',
    'lib/Sieve/Types.php',
    'lib/Service/SieveApplyService.php',
    'lib/Controller/SieveApplyController.php',
] as $f) {
    $out = [];
    $rc = 0;
    \exec('php -l ' . \escapeshellarg($repo . '/' . $f) . ' 2>&1', $out, $rc);
    $check($rc === 0, "php -l clean on {$f}: " . \implode(' | ', $out));
}

// -------------------------------------------------------------------
// Report
// -------------------------------------------------------------------
$total = $passes + \count($failures);
echo "Passed: {$passes}/{$total}\n";
if ($failures !== []) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
