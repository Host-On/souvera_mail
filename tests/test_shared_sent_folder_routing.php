<?php
/**
 * Regression test for Souvera Mail v0.14.7 — smart "Send-As" Sent-folder
 * routing in Snappymail's engine.
 *
 * Context
 * -------
 * Reported at SEG (2026-02-19): user logs in with their primary mailbox
 * (e.g. hello@souvera.eu), composes as identity `reseller@souvera.eu`
 * whose per-identity "Sent folder" is set to (Standard), and clicks
 * Send. The mail goes out but Snappymail shows:
 *
 *   "Die Nachricht wurde gesendet, konnte aber nicht im Gesendet-Ordner
 *    gespeichert werden.  TRYCREATE Mailbox does not exist."
 *
 * Root cause: with (Standard) selected, the frontend sends the account
 * owner's own Sent folder as `saveFolder`. That either does not exist
 * or, worse, silently receives the sent copy — the user then wonders
 * why the shared mailbox's Sent folder never fills up.
 *
 * v0.14.7 modifies `DoSendMessage()` in Snappymail's bundled
 * `Smail\Engine\Actions\Messages` trait to detect Send-As traffic
 * (identity email ≠ authenticated account email) and try well-known
 * shared-namespace candidates FIRST, ahead of the client-provided
 * saveFolder:
 *
 *   1. Shared Folders/<identityEmail>/Sent Items    ← Stalwart default
 *   2. Shared Folders/<identityEmail>/Sent
 *   3. Shared Folders/<identityEmail>/Gesendete Elemente
 *   4. Shared Folders/<identityEmail>/Gesendet
 *   5. <client saveFolder>                         ← safe last-resort
 *
 * IMAP APPEND against non-existent mailboxes fails with
 * `NO [TRYCREATE]` BEFORE the message literal is transmitted, so the
 * stream stays untouched between candidates.
 */

declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$msgPath = '/app/app/smail/v/current/app/libraries/Smail/Engine/Actions/Messages.php';
$msg = (string) file_get_contents($msgPath);
ok($msg !== '', "Messages.php readable", $passes, $failures);

$out = [];
$rc = 0;
\exec('php -l ' . \escapeshellarg($msgPath) . ' 2>&1', $out, $rc);
ok($rc === 0, "Messages.php has no PHP parse errors", $passes, $failures);

// ==============================================================
// A — the change is properly marked with a version banner so a
//     future engine upgrade cannot silently overwrite it
// ==============================================================
ok(\str_contains($msg, 'Souvera Mail v0.14.7'),
    "Messages.php carries a v0.14.7 banner comment (protects against upstream reintroduction)",
    $passes, $failures);
ok(\str_contains($msg, 'intelligent Sent-folder'),
    "Banner explains this is the intelligent Sent-folder routing block",
    $passes, $failures);
ok(\str_contains($msg, 'TRYCREATE Mailbox does not exist')
    || \str_contains($msg, 'TRYCREATE'),
    "Banner references the TRYCREATE user-visible error (traceability to the ticket)",
    $passes, $failures);

// ==============================================================
// B — identity detection: derive Send-As email from `from`, compare
//     against the authenticated account email
// ==============================================================
ok(\str_contains($msg, "\\Smail\\Mail\\Mime\\Email::Parse((string) \$this->GetActionParam('from', ''))"),
    "Uses Mime\\Email::Parse to extract the identity address from the `from` request param",
    $passes, $failures);
ok(\str_contains($msg, '\\Smail\\Engine\\IDN::emailToAscii'),
    "Normalises identity + account emails through IDN::emailToAscii before compare",
    $passes, $failures);
ok(\str_contains($msg, '\\strcasecmp($sSendAsIdentityEmail, $sAccountEmail) !== 0'),
    "Case-insensitive compare of identity vs account email is the decision gate",
    $passes, $failures);
// Non-fatal on parse failure — a bad `from` header must never break
// SMTP send success; we just fall through to the classic behaviour.
ok((bool) \preg_match('#catch\s*\(\s*\\\\Throwable\s+\$eIdent\s*\)\s*\{\s*\n\s*\$this->logException\(\$eIdent#s', $msg),
    "Identity-detect catch is non-fatal (logException, no rethrow) — bad From: never breaks send",
    $passes, $failures);

// ==============================================================
// C — candidate list has the expected shape and order
// ==============================================================
foreach ([
    "Shared Folders/' . \$sSendAsIdentityEmail . '/Sent Items",
    "Shared Folders/' . \$sSendAsIdentityEmail . '/Sent",
    "Shared Folders/' . \$sSendAsIdentityEmail . '/Gesendete Elemente",
    "Shared Folders/' . \$sSendAsIdentityEmail . '/Gesendet",
] as $needle) {
    ok(\str_contains($msg, $needle),
        "Candidate present: {$needle}", $passes, $failures);
}

// Order matters: Sent Items must come before Sent (Stalwart default)
$posSentItems = \strpos($msg, "'Shared Folders/' . \$sSendAsIdentityEmail . '/Sent Items'");
$posSent = \strpos($msg, "'Shared Folders/' . \$sSendAsIdentityEmail . '/Sent'");
$posGesElem = \strpos($msg, "'Shared Folders/' . \$sSendAsIdentityEmail . '/Gesendete Elemente'");
$posGesendet = \strpos($msg, "'Shared Folders/' . \$sSendAsIdentityEmail . '/Gesendet'");
ok($posSentItems !== false && $posSent !== false && $posSentItems < $posSent,
    "'Sent Items' is tried before 'Sent' (Stalwart provisions Sent Items by default)",
    $passes, $failures);
ok($posSent !== false && $posGesElem !== false && $posSent < $posGesElem,
    "'Sent' is tried before 'Gesendete Elemente' (ASCII wins over UTF-8 shot-in-the-dark)",
    $passes, $failures);
ok($posGesElem !== false && $posGesendet !== false && $posGesElem < $posGesendet,
    "'Gesendete Elemente' is tried before 'Gesendet' (Outlook naming precedes short IMAP naming)",
    $passes, $failures);

// Client-provided saveFolder is appended LAST — this is critical:
// respects users who set the per-identity Sent folder to a custom
// path via the UI (e.g. their own team's shared drafts folder).
ok(\str_contains($msg, '$aSaveCandidates[] = $sSaveFolder;'),
    "Client-provided saveFolder is appended LAST as safe fallback",
    $passes, $failures);
// And it lives AFTER the shared candidates were assembled (so shared
// paths get first crack).
$posClientSave = \strpos($msg, '$aSaveCandidates[] = $sSaveFolder;');
ok($posSentItems !== false && $posClientSave !== false && $posSentItems < $posClientSave,
    "Shared candidates are built BEFORE the client saveFolder is appended",
    $passes, $failures);

// ==============================================================
// D — attempt loop: dedup + break on success + fall-through catch
// ==============================================================
ok(\str_contains($msg, "\\in_array(\$sCandidate, \$aTried, true)"),
    "Attempt loop dedups against \$aTried (no double-APPEND on collisions)",
    $passes, $failures);
ok((bool) \preg_match('#\$bAppended\s*=\s*true;\s*\n\s*\$oException\s*=\s*null;\s*\n#', $msg),
    "First successful APPEND flips \$bAppended=true AND clears \$oException",
    $passes, $failures);
ok((bool) \preg_match('#\$bAppended\s*=\s*true;.*?break;#s', $msg),
    "Loop breaks on first successful APPEND (no double-save)",
    $passes, $failures);
ok((bool) \preg_match('#catch\s*\(\s*\\\\Throwable\s+\$eAppend\s*\)\s*\{\s*\n\s*\$oException\s*=\s*\$eAppend;\s*\n\s*\}#', $msg),
    "Loop catch stores each failure into \$oException and falls through to the next candidate",
    $passes, $failures);

// ==============================================================
// E — success log for observability
// ==============================================================
ok(\str_contains($msg, "'Send-As: saved sent copy for identity \"'"),
    "Emits a `Send-As: saved sent copy` info log when a shared candidate wins",
    $passes, $failures);
ok(\str_contains($msg, "\\LOG_INFO,\n\t\t\t\t\t\t\t\t\t\t'SOUVERA'"),
    "Log line is tagged with the 'SOUVERA' facility for grep-ability",
    $passes, $failures);
// Log only fires when the shared candidate WON — not when the client
// saveFolder was used (that's the boring baseline).
ok(\str_contains($msg, "\$sCandidate !== \$sSaveFolder"),
    "Success log is gated on \$sCandidate !== \$sSaveFolder (only log when we actually rerouted)",
    $passes, $failures);

// ==============================================================
// F — upstream fallback: Settings.SentFolder still tried if EVERY
//     candidate + client saveFolder failed
// ==============================================================
ok((bool) \preg_match('#if\s*\(\s*!\$bAppended\s*\)\s*\{[^{}]*?SettingsProvider#s', $msg),
    "If nothing appended, LAST-ditch attempt against Settings.SentFolder is preserved (upstream behaviour)",
    $passes, $failures);
ok(\str_contains($msg, "!\\in_array(\$sSentFolder, \$aTried, true)"),
    "Settings.SentFolder retry dedups against \$aTried too",
    $passes, $failures);
ok(\str_contains($msg, 'throw new ClientException(Notifications::CantSaveMessage->value'),
    "If even Settings.SentFolder fails, throws CantSaveMessage (unchanged behaviour)",
    $passes, $failures);

// ==============================================================
// G — the classic doubled-catch anti-pattern is gone (the old code
//     shadowed the outer $oException inside a catch(_) $oException,
//     which is legal PHP but confusing; the new loop is explicit)
// ==============================================================
// Regression guard: the OLD flow only did a SINGLE fallback attempt
// against SentFolder. The new flow tries ALL shared candidates plus
// SentFolder — so at least 5 APPEND call sites must remain.
// We test that `MessageAppendStream` in the send path lives inside
// a foreach loop (the new pattern).
$sendBlockStart = \strpos($msg, 'Souvera Mail v0.14.7');
$sendBlockEnd = \strpos($msg, 'if (\is_resource($rAppendMessageStream)', $sendBlockStart);
ok($sendBlockStart !== false && $sendBlockEnd !== false,
    "v0.14.7 block is properly bounded (banner start → BCC stream close)",
    $passes, $failures);
$sendBlock = \substr($msg, $sendBlockStart, $sendBlockEnd - $sendBlockStart);
ok(\substr_count($sendBlock, 'MessageAppendStream(') >= 2,
    "v0.14.7 block still issues MessageAppendStream at least twice (loop + SentFolder fallback)",
    $passes, $failures);
ok(\str_contains($sendBlock, 'foreach ($aSaveCandidates as $sCandidate)'),
    "Attempts are driven by an explicit foreach over the candidate list",
    $passes, $failures);

// ==============================================================
// H — version bump + changelog markers
// ==============================================================
$info = (string) file_get_contents('/app/appinfo/info.xml');
ok((bool) \preg_match('#<version>0\.14\.(7|[8-9]|\d{2,})</version>#', $info),
    "info.xml version bumped to 0.14.7 (or later)", $passes, $failures);

$changelog = (string) file_get_contents('/app/CHANGELOG.md');
ok((bool) \preg_match('#\[0\.14\.7\]#', $changelog),
    "CHANGELOG.md has a [0.14.7] section", $passes, $failures);
ok((\stripos($changelog, 'Send-As') !== false)
    || (\stripos($changelog, 'TRYCREATE') !== false)
    || (\stripos($changelog, 'shared') !== false && \stripos($changelog, 'Sent') !== false),
    "CHANGELOG [0.14.7] references the Send-As Sent-routing feature",
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
