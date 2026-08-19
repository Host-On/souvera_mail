<?php
/**
 * Regression test for Souvera Mail v0.14.5 — Mailbox Access Guard.
 *
 * Context
 * -------
 * Reported at SEG Marburg (2026-02-18): a fresh Nextcloud user
 * `joerg@gratify.it` logs into Souvera Mail and sees the mailbox of
 * `hello@gratify.it` (or in general, whichever mailbox Stalwart
 * happens to map their OIDC token to when THEIR mailbox does not
 * exist yet). Root cause is upstream (Central hadn't provisioned the
 * mailbox), but our app has to fail-closed as defense in depth.
 *
 * v0.14.5 adds {@see MailboxAccessGuard} + {@see MailboxAccessDenied}
 * and wires the guard into {@see EngineHelper::startApp()} BEFORE the
 * engine's `LoginProcess` call.
 *
 * This test pins:
 *  - the two new files exist and compile
 *  - EngineHelper calls the guard BEFORE LoginProcess
 *  - the guard denies on every "not safe" branch:
 *      · Stalwart not configured
 *      · email resolution failure
 *      · bearer generation failure
 *      · Stalwart unreachable
 *      · HTTP 401 / 403
 *      · unexpected HTTP status
 *      · missing username in response body
 *      · username mismatch (case-insensitive)
 *  - the guard emits a `critical` log line on the mismatch branch so
 *    operators find it in `nextcloud.log` without user reports
 *  - the pure helper `extractAuthenticatedIdentity()` behaves for
 *    both wire-format shapes (top-level `username` + fallback via
 *    `accounts.<primaryAccount>.name`)
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ==============================================================
// A — files exist and are syntactically valid
// ==============================================================
$guardPath = '/app/lib/Service/MailboxAccessGuard.php';
$deniedPath = '/app/lib/Service/MailboxAccessDenied.php';
$helperPath = '/app/lib/Util/EngineHelper.php';
$adminPath  = '/app/lib/Service/StalwartAdminService.php';

$guard   = (string) file_get_contents($guardPath);
$denied  = (string) file_get_contents($deniedPath);
$helper  = (string) file_get_contents($helperPath);
$admin   = (string) file_get_contents($adminPath);

ok($guard  !== '', "MailboxAccessGuard.php readable",   $passes, $failures);
ok($denied !== '', "MailboxAccessDenied.php readable",  $passes, $failures);
ok($helper !== '', "EngineHelper.php readable",         $passes, $failures);
ok($admin  !== '', "StalwartAdminService.php readable", $passes, $failures);

foreach ([$guardPath, $deniedPath, $helperPath, $adminPath] as $p) {
    $out = [];
    $rc = 0;
    \exec("php -l " . \escapeshellarg($p) . " 2>&1", $out, $rc);
    ok($rc === 0, "php -l clean on " . \basename($p), $passes, $failures);
}

// ==============================================================
// B — namespace + class shape
// ==============================================================
ok(\str_contains($guard, 'namespace OCA\\SouveraMail\\Service;'),
    "MailboxAccessGuard lives in OCA\\SouveraMail\\Service", $passes, $failures);
ok(\str_contains($denied, 'class MailboxAccessDenied extends \\RuntimeException'),
    "MailboxAccessDenied extends \\RuntimeException (narrow catch surface)", $passes, $failures);
ok(\str_contains($guard, 'public function assertMailboxOwnership(string $userId): void'),
    "MailboxAccessGuard exposes assertMailboxOwnership(string \$userId): void",
    $passes, $failures);
ok(\str_contains($guard, 'public static function extractAuthenticatedIdentity('),
    "MailboxAccessGuard exposes a static extractAuthenticatedIdentity() helper",
    $passes, $failures);

// Constructor wires the two supporting services + a logger
ok(\str_contains($guard, 'private StalwartAdminService $stalwart'),
    "Guard depends on StalwartAdminService (session probe endpoint)",
    $passes, $failures);
ok(\str_contains($guard, 'private StalwartUserContext $userContext'),
    "Guard depends on StalwartUserContext (email + bearer resolution)",
    $passes, $failures);
ok(\str_contains($guard, 'private LoggerInterface $logger'),
    "Guard receives a PSR-3 logger for the critical mismatch line",
    $passes, $failures);

// ==============================================================
// C — every deny branch throws MailboxAccessDenied
// ==============================================================
ok(\substr_count($guard, 'throw new MailboxAccessDenied(') >= 7,
    "Guard has AT LEAST 7 throw-MailboxAccessDenied sites (not-configured, resolveEmail, resolveBearer, session fetch, 401/403, unexpected status, missing identity, mismatch)",
    $passes, $failures);

foreach ([
    // not configured
    'stalwart_api_url' => "Stalwart is not configured on this Nextcloud",
    // 401 / 403 => "Most likely no mailbox has been provisioned yet"
    'unprovisioned hint' => 'no mailbox has been provisioned yet',
    // unexpected HTTP status
    'unexpected status wording' => 'unexpected status',
    // missing username in body
    'schema mismatch wording' => 'did not carry an identifiable account name',
    // ownership mismatch
    'ownership mismatch wording' => 'Mailbox ownership mismatch for user',
] as $label => $needle) {
    ok(\str_contains($guard, $needle),
        "Guard error text carries the '{$label}' fingerprint", $passes, $failures);
}

// Critical log for the mismatch branch (this is the "loud" line
// operators grep for in nextcloud.log)
ok((bool) \preg_match('#\$this->logger->critical\(\s*\n\s*\'Souvera Mail: MAILBOX OWNERSHIP MISMATCH#', $guard),
    "Guard emits logger->critical() on the ownership-mismatch branch",
    $passes, $failures);

// Fail-closed on Stalwart unreachable => warning + throw
ok(\str_contains($guard, "'Souvera Mail: Stalwart session fetch failed during ownership check for uid='"),
    "Guard warns before throwing when Stalwart is unreachable (fail-closed)",
    $passes, $failures);
ok(\str_contains($guard, 'Refusing login until the check can complete'),
    "Guard's unreachable-Stalwart error message refuses login explicitly (no silent fallback)",
    $passes, $failures);

// ==============================================================
// D — EngineHelper wires the guard BEFORE LoginProcess
// ==============================================================
ok(\str_contains($helper, 'use OCA\\SouveraMail\\Service\\MailboxAccessDenied;'),
    "EngineHelper imports MailboxAccessDenied", $passes, $failures);
ok(\str_contains($helper, 'use OCA\\SouveraMail\\Service\\MailboxAccessGuard;'),
    "EngineHelper imports MailboxAccessGuard", $passes, $failures);
ok(\str_contains($helper, 'private MailboxAccessGuard $mailboxGuard'),
    "EngineHelper's constructor accepts a MailboxAccessGuard dep", $passes, $failures);

$guardCallPos = \strpos($helper, '$this->mailboxGuard->assertMailboxOwnership(');
$loginCallPos = \strpos($helper, '$oActions->LoginProcess(');
ok($guardCallPos !== false, "EngineHelper calls the guard's assertMailboxOwnership()", $passes, $failures);
ok($loginCallPos !== false, "EngineHelper still calls LoginProcess (engine handshake)", $passes, $failures);
ok($guardCallPos !== false && $loginCallPos !== false && $guardCallPos < $loginCallPos,
    "EngineHelper calls the guard BEFORE LoginProcess (block-and-return pattern)",
    $passes, $failures);

// v0.14.6: the guard MUST also run before the `getMainAccountFromToken(false)`
// call that decides whether $doLogin should be true. In v0.14.5 the guard was
// nested inside `if ($doLogin && ...)`, which meant it never ran on any
// request where Snappymail's engine had already rebuilt a MainAccount from
// the NC session — i.e. every request after the first. See SEG Marburg
// live-incident follow-up (2026-02-19).
$mainAccountCheckPos = \strpos($helper, '$oActions->getMainAccountFromToken(false)');
ok($mainAccountCheckPos !== false,
    "EngineHelper still consults getMainAccountFromToken(false) for doLogin", $passes, $failures);
ok($mainAccountCheckPos !== false && $guardCallPos !== false && $guardCallPos < $mainAccountCheckPos,
    "Guard runs BEFORE getMainAccountFromToken() — i.e. on every request, "
    . "not only when \$doLogin=true (v0.14.6 SEG Marburg follow-up fix)",
    $passes, $failures);

// On a deny, the engine's auth cookies MUST be purged so a hard reload
// can't just re-populate a MainAccount from stale state.
ok((bool) \preg_match('#catch\s*\(\s*MailboxAccessDenied\s+\$e\s*\)[^{}]*\{.*?\$oActions->Logout\(\s*true\s*\)#s', $helper),
    "MailboxAccessDenied handler purges Snappymail auth cookies via Logout(true)",
    $passes, $failures);

// The catch handler around assertMailboxOwnership catches the narrow type
ok(\str_contains($helper, 'catch (MailboxAccessDenied $e)'),
    "EngineHelper catches MailboxAccessDenied specifically (not Throwable — programmer errors still surface)",
    $passes, $failures);

// A 'critical' operator log is emitted when the guard denies
ok((bool) \preg_match('#logger->critical\(\s*\n?\s*\'Souvera Mail: mailbox access denied#', $helper),
    "EngineHelper logs `mailbox access denied` at critical level (operator-facing)",
    $passes, $failures);

// The 403 branch: when $handle is true, we abort with an HTTP 403 body
// so the browser sees a clear error, not another user's mailbox
$blockStart = $guardCallPos;
$blockEnd   = $loginCallPos;
$block = \substr($helper, $blockStart, $blockEnd - $blockStart);
ok(\str_contains($block, "header('Content-Type: text/plain; charset=utf-8', true, 403)"),
    "On engine-handling requests the guard aborts with HTTP 403", $passes, $failures);
ok(\str_contains($block, 'exit;'),
    "The 403 branch terminates the request (no fallthrough to LoginProcess)", $passes, $failures);

// LoginProcess is NEVER reached on the deny branch — the guard's
// catch block ends with a `return;` (session-less requests) or an
// `exit;` (engine-handling requests). Both cases prevent fallthrough
// to LoginProcess, which is already asserted above via `guardCallPos < loginCallPos`.
ok(\str_contains($block, "return;"),
    "Deny branch returns from startApp() (no fallthrough to LoginProcess)",
    $passes, $failures);

// ==============================================================
// E — StalwartAdminService::fetchSessionAsUser contract
// ==============================================================
ok((bool) \preg_match('#public function fetchSessionAsUser\(string \$bearerToken\)\s*:\s*array#', $admin),
    "StalwartAdminService::fetchSessionAsUser(string): array is present",
    $passes, $failures);
ok(\str_contains($admin, "'nextcloud' => ['allow_local_address' => true]"),
    "fetchSessionAsUser allows local addresses (Stalwart runs on the same cluster)",
    $passes, $failures);
ok(\str_contains($admin, "'http_errors' => false"),
    "fetchSessionAsUser suppresses Guzzle's HTTP-status exceptions (the caller inspects status)",
    $passes, $failures);

// ==============================================================
// F — behavioural simulation of the pure static helper
// ==============================================================
require_once $guardPath;

use OCA\SouveraMail\Service\MailboxAccessGuard;

// F-1: top-level `username` wins
$body = [
    'username' => 'joerg@gratify.it',
    'accounts' => ['acc-1' => ['name' => 'someone-else@example.com']],
    'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'acc-1'],
];
ok(MailboxAccessGuard::extractAuthenticatedIdentity($body) === 'joerg@gratify.it',
    "F-1: top-level `username` is returned when present", $passes, $failures);

// F-2: fallback to accounts.<primary>.name when username missing
$body = [
    'accounts' => ['acc-42' => ['name' => 'joerg@gratify.it']],
    'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'acc-42'],
];
ok(MailboxAccessGuard::extractAuthenticatedIdentity($body) === 'joerg@gratify.it',
    "F-2: fallback via primaryAccounts → accounts[<id>].name",
    $passes, $failures);

// F-3: empty body → null
ok(MailboxAccessGuard::extractAuthenticatedIdentity([]) === null,
    "F-3: empty body returns null (guard treats null as deny)",
    $passes, $failures);

// F-4: primaryAccounts without matching accounts entry → null
$body = [
    'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'acc-ghost'],
    'accounts' => ['acc-real' => ['name' => 'somebody@example.com']],
];
ok(MailboxAccessGuard::extractAuthenticatedIdentity($body) === null,
    "F-4: primaryAccounts pointing at non-existent account → null",
    $passes, $failures);

// F-5: primary account entry without a `name` field → null
$body = [
    'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'acc-1'],
    'accounts' => ['acc-1' => ['isPersonal' => true]],
];
ok(MailboxAccessGuard::extractAuthenticatedIdentity($body) === null,
    "F-5: account entry missing `name` → null (schema mismatch)",
    $passes, $failures);

// F-6: username as empty string → treat as missing, fall through to accounts
$body = [
    'username' => '',
    'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'acc-1'],
    'accounts' => ['acc-1' => ['name' => 'joerg@gratify.it']],
];
ok(MailboxAccessGuard::extractAuthenticatedIdentity($body) === 'joerg@gratify.it',
    "F-6: empty-string username falls back to accounts lookup",
    $passes, $failures);

// F-7: case-insensitive compare is the caller's job — the extractor returns
// the raw string, which the caller passes to strcasecmp(). Verify this
// is documented in the source, so a future refactor does not lower-case
// the value here and break the mismatch message operators grep for.
ok(\str_contains($guard, '\\strcasecmp($reportedIdentity, $expectedEmail)'),
    "assertMailboxOwnership compares case-insensitively (strcasecmp)",
    $passes, $failures);

// ==============================================================
// G — info.xml version bumped to 0.14.5
// ==============================================================
$info = (string) file_get_contents('/app/appinfo/info.xml');
ok((bool) \preg_match('#<version>0\.(?:1[4-9]|[2-9]\d)\.\d+</version>#', $info),
    "info.xml version bumped to 0.14.5 (or later)", $passes, $failures);

// ==============================================================
// H — CHANGELOG.md documents the fix
// ==============================================================
$changelog = (string) file_get_contents('/app/CHANGELOG.md');
ok((bool) \preg_match('#\[0\.14\.5\]#', $changelog),
    "CHANGELOG.md has a [0.14.5] section", $passes, $failures);
ok(\stripos($changelog, 'MailboxAccessGuard') !== false || \stripos($changelog, 'mailbox access guard') !== false || \stripos($changelog, 'ownership') !== false,
    "CHANGELOG [0.14.5] references the ownership guard by name",
    $passes, $failures);

// ==============================================================
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n"; foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
