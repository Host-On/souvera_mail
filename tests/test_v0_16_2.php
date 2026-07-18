<?php
/**
 * v0.16.2 — Regression pin for post-auth CAPABILITY refresh.
 *
 * Operator-reported bug after v0.16.1 shipped:
 *   „Ihr Mailserver unterstützt das Sortieren von Nachrichten nicht"
 * ...on freshly-connected external mailboxes whose Dovecot / Cyrus
 * DOES support SORT — they just don't advertise it pre-auth.
 *
 * Root cause: ImapClient::Login() only invoked setCapabilities() on
 * the AUTHENTICATE OK reply. If the reply omitted the [CAPABILITY …]
 * response-code (common on Dovecot with `imap_capability` narrowed
 * for security), the stale pre-auth capability list stayed in the
 * cache and the frontend's `FolderUserStore.hasCapability('SORT')`
 * returned false.
 *
 * Fix: force-refresh via an explicit CAPABILITY command when the
 * OK reply carried no capability block.
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

$imapSrc = (string) \file_get_contents('/app/app/smail/v/current/app/libraries/Smail/Mail/Imap/ImapClient.php');

// -----------------------------------------------------------------
// 1. Verify the setCapabilities($oResponse) call is still there.
// -----------------------------------------------------------------
$a(\str_contains($imapSrc, '$this->setCapabilities($oResponse)'),
    'ImapClient::Login() still calls setCapabilities() on the auth OK reply');

// -----------------------------------------------------------------
// 2. Verify the post-auth force-refresh branch exists AND lives
//    AFTER setCapabilities() (order matters — otherwise we'd
//    refetch, then overwrite with the empty OK reply, back to
//    square one).
// -----------------------------------------------------------------
$a(\str_contains($imapSrc, "!\$oResponse->getCapabilityResult()"),
    'ImapClient::Login() branches on getCapabilityResult() (was the OK reply capability-carrying?)');
$a(\str_contains($imapSrc, "\$this->aCapa = null;\n\t\t\t\t\$this->aCapaRaw = null;"),
    'ImapClient::Login() force-invalidates both cached capability lists before refetch');
$a(\str_contains($imapSrc, "\$this->aCapaRaw = null;\n\t\t\t\t\$this->Capability();"),
    'ImapClient::Login() explicitly reissues CAPABILITY after invalidation');

// setCapabilities MUST come BEFORE the refresh branch — otherwise the
// SASL-IR-style servers that DO carry [CAPABILITY …] in their OK reply
// would still refetch (wastes a roundtrip), and worse: the branch's
// invalidation would happen on the stale state.
$posSet    = \strpos($imapSrc, '$this->setCapabilities($oResponse)');
$posBranch = \strpos($imapSrc, "!\$oResponse->getCapabilityResult()");
$a($posSet !== false && $posBranch !== false && $posSet < $posBranch,
    'setCapabilities() runs BEFORE the getCapabilityResult() branch (ordering matters)');

// -----------------------------------------------------------------
// 3. The Capability() method itself hasn't been altered (still
//    re-issues an explicit CAPABILITY command when aCapaRaw is
//    null — that's the mechanism our refresh branch relies on).
// -----------------------------------------------------------------
$a(\preg_match('/public function Capability\(\)\s*:\s*\?array\s*\{\s*if \(!\$this->aCapaRaw\)/', $imapSrc) === 1,
    'Capability() re-issues an explicit CAPABILITY command when aCapaRaw is null');

// -----------------------------------------------------------------
// 4. STARTTLS path already invalidates aCapaRaw (line 88-89) —
//    keep that intact so post-STARTTLS pre-auth caps are fresh.
// -----------------------------------------------------------------
$a(\preg_match('/StartTLS\(\)[^}]+?SendRequestGetResponse\(\'STARTTLS\'\)[^}]+?\$this->aCapa = null;\s*\$this->aCapaRaw = null;/s', $imapSrc) === 1,
    'STARTTLS still invalidates both cached capability lists (pre-existing behaviour intact)');

// -----------------------------------------------------------------
// 5. Version bump
// -----------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$pkg  = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#<version>0\.(?:1[6-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.16.2 or higher');
$a((bool) \preg_match('#"version"\s*:\s*"0\.(?:1[6-9]|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.16.2 or higher');

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
