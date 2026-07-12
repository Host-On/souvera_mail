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
$check(\str_contains($svcSrc, 'inMailbox'),
    "service filters Email/query by inMailbox");
$check(\str_contains($svcSrc, 'MAX_LIMIT = 5000') && \str_contains($svcSrc, 'DEFAULT_LIMIT = 2000'),
    "service caps limit at 5000 (default 2000)");
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
    'sieve-apply-toolbar-btn',
    'sieve-apply-modal',
    'sieve-apply-folder-select',
    'sieve-apply-run',
    'sieve-apply-cancel',
] as $tid) {
    $check(\str_contains($jsSrc, "'data-testid': '{$tid}'")
        || \str_contains($jsSrc, "data-testid=\"{$tid}\"") ,
        "sieve-apply.js has data-testid=\"{$tid}\" for automation hooks");
}
$check(\str_contains($jsSrc, 'includeRedirect'),
    "sieve-apply.js posts includeRedirect (defaults true — operator chose 1b)");
$check(\str_contains($jsSrc, 'folderInformationMultiplyList'),
    "sieve-apply.js pings Snappymail to re-read the folder counts after success");
$check(\str_contains($jsSrc, 'ERNEUT per SMTP'),
    "sieve-apply.js warns the operator that redirect resends via SMTP (avoids surprise)");
// v0.14.37b: after operator reported "Find keinen Button" — verify the
// selector actually matches Snappymail's real popup DOM. The dialog is
// created by buildViewModel() as `<dialog id="V-<TemplateId>">`, and
// the SieveScript popup's template id is `SieveScript` (see
// static/js/sieve.js SieveScriptPopupView → super('SieveScript')).
$check(\str_contains($jsSrc, '#V-SieveScript'),
    "sieve-apply.js targets the correct dialog id `#V-SieveScript` (v0.14.37b fix — was `.b-popups-sieve-script` which does not exist)");
$check(\str_contains($jsSrc, "querySelector('footer')"),
    "sieve-apply.js looks up the plain <footer> element inside the dialog (PopupsSieveScript.html renders a bare `<footer>`, not a class)");

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
