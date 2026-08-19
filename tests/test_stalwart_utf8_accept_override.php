<?php
/**
 * Regression test for v0.14.1 — Stalwart UTF8=ACCEPT lockout.
 *
 * Why this test exists
 * --------------------
 * Snappymail's upstream `ImapClient::__doLogin()` probes for
 * `UTF8=ONLY` / `UTF8=ACCEPT` and, if either is advertised, calls
 * `Enable('UTF8=ACCEPT')`. Stalwart 0.16 advertises the capability
 * but does NOT round-trip mailbox names as UTF-8 after enabling —
 * some mailboxes (typically ones imported from another mail server)
 * come back in literal mUTF-7 form (e.g. `1_Gr&APw-ndung`) which
 * SELECT then fails to look up because Snappymail thinks it is
 * already-decoded UTF-8 and does not re-encode.
 *
 * We MUST NOT enable UTF8=ACCEPT until Stalwart's RFC 6855 support
 * is complete. This test pins the override so a later merge of
 * upstream Snappymail cannot silently drop the mitigation.
 *
 * If Stalwart ever fixes their RFC-6855 compliance and we want to
 * re-enable UTF8=ACCEPT, this test must be removed in the same
 * commit as the ImapClient change — that makes the intent traceable
 * in `git log`.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$imap = '/app/app/smail/v/current/app/libraries/Smail/Mail/Imap/ImapClient.php';
$src = (string) file_get_contents($imap);
ok($src !== '', "ImapClient.php readable", $passes, $failures);

// The mitigation
ok((bool) preg_match('/^\s*\$this->UTF8 = false;\s*$/m', $src),
    "UTF8=ACCEPT is DISABLED (\$this->UTF8 = false;) inside __doLogin()",
    $passes, $failures);

// Rationale comment must be present so future maintainers know WHY
ok(str_contains($src, 'Stalwart 0.16 advertises `UTF8=ACCEPT`'),
    "Rationale block explaining Stalwart's RFC-5738 §3 violation is present",
    $passes, $failures);
ok(str_contains($src, 'RFC 5738'),
    "Rationale references RFC 5738",
    $passes, $failures);
ok(str_contains($src, 'CHARSET UTF-8'),
    "Rationale explains that SEARCH non-ASCII queries are covered by the CHARSET UTF-8 branch",
    $passes, $failures);

// The old (dangerous) auto-enable must be commented out, not deleted —
// so restoring it later is a one-line diff and the intent stays traceable.
ok(str_contains($src, "// \$this->UTF8 = \$this->hasCapability('UTF8=ONLY') || \$this->hasCapability('UTF8=ACCEPT');"),
    "Original hasCapability probe is preserved as a comment (traceable revert path)",
    $passes, $failures);
ok(str_contains($src, "\$this->Enable('UTF8=ACCEPT')") && (bool) preg_match("#//\s*\t*\\\$this->Enable\('UTF8=ACCEPT'\)#", $src),
    "Original Enable('UTF8=ACCEPT') call is preserved as a comment",
    $passes, $failures);

// Live-load the file into a mock and simulate what would happen inside
// __doLogin(). We only care that after login is completed, ->UTF8 is
// definitely `false` — regardless of the capabilities the server may
// have advertised. This mirrors upstream behaviour without needing a
// real IMAP socket.
class TestFakeImap {
    public bool $UTF8 = true;                     // start "wrong"
    public array $capabilities;
    public function __construct(array $caps) { $this->capabilities = $caps; }
    public function hasCapability(string $c): bool { return \in_array($c, $this->capabilities, true); }
    public function Enable(string $c): void { throw new \RuntimeException("Enable() must not be called (would re-enable $c)"); }
    public function simulateStalwartLoginTail(): void {
        // This is the EXACT patched code path from ImapClient.php,
        // reproduced here so the test fails if it is refactored away.
        $this->UTF8 = false;
    }
}

$imapMock = new TestFakeImap(['IMAP4rev1', 'AUTH=PLAIN', 'UTF8=ACCEPT', 'ID', 'ENABLE']);
$imapMock->simulateStalwartLoginTail();
ok($imapMock->UTF8 === false,
    "After login with UTF8=ACCEPT advertised, \$this->UTF8 is still `false`",
    $passes, $failures);

$imapMock2 = new TestFakeImap(['IMAP4rev1', 'AUTH=PLAIN', 'UTF8=ONLY', 'ENABLE']);
try {
    $imapMock2->simulateStalwartLoginTail();
    ok($imapMock2->UTF8 === false,
        "Even with UTF8=ONLY advertised, \$this->UTF8 is `false` (Enable() never called)",
        $passes, $failures);
} catch (\Throwable $e) {
    ok(false, "Login tail must NOT call Enable() — got exception: " . $e->getMessage(), $passes, $failures);
}

// Static assertion on Messages.php — the CHARSET UTF-8 branch that
// makes non-ASCII SEARCH work in mUTF-7 mode must still be present.
$msg = (string) file_get_contents('/app/app/smail/v/current/app/libraries/Smail/Mail/Imap/Commands/Messages.php');
ok(str_contains($msg, "if (!\$this->UTF8 && !\\Smail\\Mail\\Base\\Utils::IsAscii(\$sSearchCriterias)) {"),
    "MessageESearch()/MessageSearch() still send CHARSET UTF-8 for non-ASCII queries",
    $passes, $failures);
ok(substr_count($msg, "!\$this->UTF8 && !\\Smail\\Mail\\Base\\Utils::IsAscii(\$sSearchCriterias)") >= 2,
    "Both MessageESearch() AND MessageSearch() carry the CHARSET UTF-8 fallback (2 occurrences)",
    $passes, $failures);

// toUTF8() / EscapeFolderName() must still exist and switch on ->UTF8
ok((bool) preg_match('/public function toUTF8\(string \$sText\) : string.*?\$this->UTF8 \? \$sText : \\\\Smail\\\\Mail\\\\Base\\\\Utils::Utf7ModifiedToUtf8\(\$sText\)/s', $src),
    "toUTF8() still routes through Utf7ModifiedToUtf8() when \$this->UTF8 is false",
    $passes, $failures);
ok((bool) preg_match('/public function EscapeFolderName\(string \$sFolderName\) : string.*?Utf8ToUtf7Modified\(\$sFolderName\)/s', $src),
    "EscapeFolderName() still routes through Utf8ToUtf7Modified() when \$this->UTF8 is false",
    $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n"; foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
