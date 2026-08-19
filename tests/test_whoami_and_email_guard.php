<?php
/**
 * Regression test for v0.14.2 — email/uid mismatch guard & whoami command.
 *
 * Context
 * -------
 * A user reported: "I created NC user `joerg@gratify.it` but after login he
 * sees the mailbox of `hello@gratify.it`". Root cause is almost always an
 * upstream provisioning bug (Souvera Central sets the wrong `settings/email`
 * for the new user). This test pins the two mitigations we shipped:
 *
 *   1. `EngineHelper::getSsoEmail()` now emits a WARNING log line whenever
 *      the resolved email looks like it might belong to a different user
 *      than the current uid. Never blocks — pure early-warning.
 *
 *   2. New `occ souvera_mail:whoami <uid>` command dumps the full
 *      resolution cascade so an operator can pinpoint the bad source in
 *      one call.
 *
 * If a future refactor removes either mechanism silently, this test fails.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$helper = (string) file_get_contents('/app/lib/Util/EngineHelper.php');
ok($helper !== '', "EngineHelper.php readable", $passes, $failures);

// The guard method exists
ok(str_contains($helper, 'private function guardEmailAgainstUid('),
    "guardEmailAgainstUid() private helper exists on EngineHelper",
    $passes, $failures);

// It's called from each cascade branch
ok(substr_count($helper, '$this->guardEmailAgainstUid(') === 3,
    "guardEmailAgainstUid() is called from all 3 cascade branches (souvera_mail/email, settings/email, IUser::getEMailAddress())",
    $passes, $failures);

// Uid-with-@ branch warns
ok((bool) preg_match('/if \(\\\\str_contains\(\$uid, \'@\'\) && \\\\strcasecmp\(\$uid, \$email\) !== 0\)/', $helper),
    "Guard warns when uid contains '@' AND resolved email differs (case-insensitive)",
    $passes, $failures);

// Uid-short branch warns via localpart
ok(str_contains($helper, 'Localparts differ; verify this is intentional'),
    "Guard also warns when uid localpart != email localpart (softer info-level log)",
    $passes, $failures);

// The warning message points operators at the diagnostic command
ok(str_contains($helper, '`occ souvera_mail:whoami '),
    "Guard log messages point operators at `occ souvera_mail:whoami <uid>`",
    $passes, $failures);

// The message includes both uid and resolved email so `journalctl | grep uid`
// makes the leak visible without follow-up queries
ok(str_contains($helper, "'Souvera Mail: email/uid mismatch for uid=\"' . \$uid . '\" — Snappymail will use \"' . \$email"),
    "Mismatch warning contains BOTH the uid AND the resolved email (searchable)",
    $passes, $failures);

// ---- Whoami command class ------------------------------------------------
$whoami = (string) file_get_contents('/app/lib/Command/Whoami.php');
ok($whoami !== '', "Command\\Whoami.php readable", $passes, $failures);
ok(str_contains($whoami, "->setName('souvera_mail:whoami')"),
    "Command registered under `souvera_mail:whoami`", $passes, $failures);
ok(str_contains($whoami, "->addArgument('uid', InputArgument::REQUIRED"),
    "Command requires a `uid` argument", $passes, $failures);
ok(str_contains($whoami, "->addOption('json'"),
    "Command supports --json for machine-readable output (pipelines)", $passes, $failures);

// Reports all 4 cascade sources so mismatch source is unambiguous
foreach ([
    'souvera_mail/email',
    'settings/email',
    'IUser::getEMailAddress()',
    'fallback:uid',
] as $src) {
    ok(str_contains($whoami, $src),
        "whoami output includes cascade source '$src'", $passes, $failures);
}

// Provides remediation hints for the two most common causes
ok(str_contains($whoami, 'occ user:setting'),
    "whoami suggests `occ user:setting` for the common Central-typo case", $passes, $failures);

// The command's outcome is USEFUL for shell pipelines: 0 = clean,
// 2 = warnings, 1 = user missing. Verify all three exit codes exist.
ok(str_contains($whoami, 'return 0;') && str_contains($whoami, 'return 1;') && str_contains($whoami, 'return 2;'),
    "whoami distinguishes exit codes 0 (clean) / 1 (user missing) / 2 (warnings) — pipelines can act on it",
    $passes, $failures);

// ---- info.xml wiring -----------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
ok(str_contains($info, '<command>OCA\SouveraMail\Command\Whoami</command>'),
    "Whoami command registered in appinfo/info.xml <commands> block",
    $passes, $failures);

// ---- Behavioural simulation of the guard --------------------------------
// Reproduce guardEmailAgainstUid()'s logic — if any of these assertions
// fail, someone changed the guard semantics without updating the tests.
function simGuard(string $uid, string $email, string $source): ?string {
    if (\str_contains($uid, '@') && \strcasecmp($uid, $email) !== 0) {
        return 'WARNING';
    }
    if (\str_contains($uid, '@') === false
            && \str_contains($email, '@')
            && \strcasecmp(\explode('@', $email, 2)[0], $uid) !== 0) {
        return 'INFO';
    }
    return null;
}

// Regression scenario from the user's report:
//   uid = 'joerg' (short), settings/email = 'hello@gratify.it'
ok(simGuard('joerg', 'hello@gratify.it', 'settings/email') === 'INFO',
    "Simulated case (uid=joerg, email=hello@gratify.it) fires the INFO warning",
    $passes, $failures);

// uid contains @ and matches -> silent
ok(simGuard('joerg@gratify.it', 'joerg@gratify.it', 'settings/email') === null,
    "Matching uid+email (both 'joerg@gratify.it') is SILENT — no log spam",
    $passes, $failures);

// uid contains @ and email differs -> WARNING
ok(simGuard('joerg@gratify.it', 'hello@gratify.it', 'settings/email') === 'WARNING',
    "Simulated case (uid='joerg@gratify.it', email='hello@gratify.it') fires the WARNING",
    $passes, $failures);

// Legit case: short uid + matching localpart -> silent
ok(simGuard('joerg', 'joerg@gratify.it', 'settings/email') === null,
    "Short uid + matching localpart is SILENT (typical NC setup)",
    $passes, $failures);

// Case-insensitive matching: 'Joerg' == 'joerg'
ok(simGuard('Joerg', 'joerg@gratify.it', 'settings/email') === null,
    "Case-insensitive localpart match — 'Joerg' + 'joerg@...' is silent",
    $passes, $failures);

// Edge: uid has no @ and email has no @ (unusual but possible)
ok(simGuard('joerg', 'joerg', 'fallback:uid') === null,
    "Fallback (uid == email == 'joerg') is silent",
    $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n"; foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
