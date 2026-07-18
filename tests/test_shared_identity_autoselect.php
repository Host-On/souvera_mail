<?php
/**
 * Regression test for Souvera Mail v0.14.8 — auto-selection of the
 * Send-As identity when composing a reply/forward from a message that
 * lives inside a "Shared Folders/<email>/..." path.
 *
 * Context
 * -------
 * v0.14.7 fixed the SERVER side: sent copies of messages composed as
 * a Send-As identity now land in the shared mailbox's Sent Items.
 *
 * But the UX gap remained: when a user opens a message inside
 * `Shared Folders/reseller@souvera.eu/INBOX` and hits Reply, the
 * From: dropdown defaulted to the primary identity — the user had to
 * remember to switch to the shared identity every single time.
 *
 * v0.14.8 patches Snappymail's `initOnShow()` in the ComposePopupView
 * (compiled into `static/js/app.js`) so that when the classic
 * to/cc/bcc-based identity heuristic yields no match AND the message
 * being replied to lives in a `Shared Folders/<email>/...` path, the
 * identity whose email matches `<email>` is selected automatically.
 *
 * This is a compiled-JS patch (Snappymail-fork territory). The fix
 * lives right before the `identity = identity || IdentityUserStore()[0]`
 * final fallback so we NEVER override the address-based heuristic.
 */

declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$appJs = '/app/app/smail/v/current/static/js/app.js';
$js = (string) file_get_contents($appJs);
ok($js !== '', "app.js readable", $passes, $failures);

// node --check must accept the patched file.
$out = [];
$rc = 0;
\exec('node --check ' . \escapeshellarg($appJs) . ' 2>&1', $out, $rc);
ok($rc === 0, "app.js parses cleanly with node --check", $passes, $failures);

// ==============================================================
// A — the change is properly marked so upstream doesn't silently
//     overwrite it on a Snappymail bundle refresh
// ==============================================================
ok(\str_contains($js, 'Souvera Mail v0.14.8'),
    "app.js carries a v0.14.8 banner comment", $passes, $failures);
ok(\str_contains($js, 'Send-As identity when replying/forwarding'),
    "Banner explains the Send-As identity auto-select intent",
    $passes, $failures);
ok(\str_contains($js, 'Outlook/Exchange convention'),
    "Banner references the design origin (Outlook/Exchange parity)",
    $passes, $failures);

// ==============================================================
// B — regex + lookup shape
// ==============================================================
ok(\str_contains($js, '/^Shared Folders\\/([^\\/]+)\\//.exec(oLastMessage.folder)'),
    "Uses /^Shared Folders\\/([^\\/]+)\\// regex against oLastMessage.folder",
    $passes, $failures);
ok(\str_contains($js, 'sharedFolderMatch[1].toLowerCase()'),
    "Extracts email from capture group and lowercases it (case-insensitive match)",
    $passes, $failures);
ok(\str_contains($js, 'IdentityUserStore.find(i => (i.email() || \'\').toLowerCase() === sharedEmail)'),
    "Uses IdentityUserStore.find(…) with case-insensitive email compare "
    . "(matches existing `findIdentity` helper's calling convention)",
    $passes, $failures);

// ==============================================================
// C — placement: the new block MUST run
//     • AFTER the To/Cc/Bcc-based heuristic (so we don't override
//       an explicit alias hit)
//     • BEFORE the `identity || IdentityUserStore()[0]` fallback
//       (otherwise the main identity would win first and the shared
//       lookup would never fire)
// ==============================================================
$heuristicPos = \strpos($js, 'findIdentity(oLastMessage.to.concat(oLastMessage.cc, oLastMessage.bcc))');
$sharedPos    = \strpos($js, 'sharedFolderMatch = /^Shared Folders');
$fallbackPos  = \strpos($js, 'identity = identity || IdentityUserStore()[0]');

ok($heuristicPos !== false, "Existing To/Cc/Bcc heuristic still present (no regression)",
    $passes, $failures);
ok($sharedPos !== false,    "New shared-folder identity lookup is present",
    $passes, $failures);
ok($fallbackPos !== false,  "Final `IdentityUserStore()[0]` fallback still present (no regression)",
    $passes, $failures);
ok($heuristicPos !== false && $sharedPos !== false && $heuristicPos < $sharedPos,
    "Shared-folder lookup runs AFTER the classic To/Cc/Bcc heuristic",
    $passes, $failures);
ok($sharedPos !== false && $fallbackPos !== false && $sharedPos < $fallbackPos,
    "Shared-folder lookup runs BEFORE the `IdentityUserStore()[0]` fallback",
    $passes, $failures);

// ==============================================================
// D — the shared lookup only fires when `!identity` — otherwise
//     an explicit alias in To/Cc/Bcc would be silently overridden
// ==============================================================
ok((bool) \preg_match('#if\s*\(\s*!identity\s*&&\s*oLastMessage\s*&&\s*oLastMessage\.folder\s*\)#', $js),
    "Guard `if (!identity && oLastMessage && oLastMessage.folder)` respects the classic heuristic first",
    $passes, $failures);

// ==============================================================
// E — behavioural simulation via node
//     Extract the regex + lookup shape, feed test inputs, verify.
// ==============================================================
$nodeScript = <<<'JS'
const cases = [
  // [folderPath, expectedSharedEmail-or-null]
  ["Shared Folders/reseller@souvera.eu/INBOX", "reseller@souvera.eu"],
  ["Shared Folders/reseller@souvera.eu/Sent Items", "reseller@souvera.eu"],
  ["Shared Folders/team-vertrieb@souvera.eu/INBOX", "team-vertrieb@souvera.eu"],
  ["Shared Folders/Reseller@Souvera.EU/INBOX",  "reseller@souvera.eu"], // case-insensitive
  ["INBOX", null],
  ["Sent", null],
  ["Shared Folders/", null], // no email segment
  ["Other Users/reseller@souvera.eu/INBOX", null], // different namespace prefix — intentionally NOT matched
];
let fails = 0;
for (const [folder, expected] of cases) {
  const m = /^Shared Folders\/([^\/]+)\//.exec(folder);
  const got = m ? m[1].toLowerCase() : null;
  if (got !== expected) {
    console.error("FAIL:", JSON.stringify(folder), "expected", expected, "got", got);
    fails++;
  }
}
if (fails === 0) console.log("OK");
process.exit(fails === 0 ? 0 : 1);
JS;
$scriptPath = '/tmp/test_shared_identity_regex.js';
\file_put_contents($scriptPath, $nodeScript);
$out2 = [];
$rc2 = 0;
\exec('node ' . \escapeshellarg($scriptPath) . ' 2>&1', $out2, $rc2);
ok($rc2 === 0 && \in_array('OK', $out2, true),
    "Behavioural sim: /^Shared Folders\\/([^\\/]+)\\// extracts the correct email "
    . "for 4 positive cases and rejects 4 negative cases including 'Other Users/' namespace",
    $passes, $failures);
@\unlink($scriptPath);

// ==============================================================
// F — the existing `findIdentity` helper is UNCHANGED (regression
//     guard against accidental overwrite)
// ==============================================================
ok(\str_contains($js, 'findIdentity = addresses => {'),
    "Original findIdentity helper still present with its arrow-function signature",
    $passes, $failures);
ok(\str_contains($js, 'IdentityUserStore.find(item => addresses.includes(item.email()))'),
    "Original findIdentity body still uses IdentityUserStore.find(…) — same API our new code calls",
    $passes, $failures);

// ==============================================================
// G — version bump + changelog markers
// ==============================================================
$info = (string) file_get_contents('/app/appinfo/info.xml');
ok((bool) \preg_match('#<version>0\.(?:1[4-9]|[2-9]\d)\.\d+</version>#', $info),
    "info.xml version bumped to 0.14.8 (or later)", $passes, $failures);

$changelog = (string) file_get_contents('/app/CHANGELOG.md');
ok((bool) \preg_match('#\[0\.14\.8\]#', $changelog),
    "CHANGELOG.md has a [0.14.8] section", $passes, $failures);
ok((\stripos($changelog, 'Send-As') !== false && \stripos($changelog, 'ident') !== false)
    || (\stripos($changelog, 'shared') !== false && \stripos($changelog, 'reply') !== false)
    || (\stripos($changelog, 'auto-select') !== false),
    "CHANGELOG [0.14.8] describes the auto-identity feature",
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
