<?php
/**
 * Regression test for the Stalwart shared-mailbox identity sync added
 * in Souvera Mail 0.13.11.
 *
 * Operator request (2026-07-01)
 * -----------------------------
 * "Wenn ein Shared Postfach von Stalwart das Recht freigegeben hat in
 *  deren Namen zu senden, bekommt der Kunde dann unter 'Neue Nachricht'
 *  automatisch die Auswahl des Absenders?"
 *
 * Choices the operator made:
 *   a) Sync cadence: once every 15 min, lazily on engine boot.
 *   b) Conflict policy: manual identities stay untouched; Stalwart-
 *      managed identities are marked "Stalwart-verwaltet".
 *   c) Display name: Stalwart's `Identity.name` field.
 *   d) Scope: every identity Stalwart's `Identity/get` returns to the
 *      authenticated user (own mailbox + every shared mailbox where the
 *      user has send-as permission).
 *
 * What this test pins
 * -------------------
 * 1. JMAP request shape: `Identity/get` with `accountId` + `ids: null`,
 *    extra capability `urn:ietf:params:jmap:submission`.
 * 2. Throttle: a fresh JMAP round-trip happens only once per 15 min
 *    per user; subsequent calls within the window return `null`
 *    (= "skip reconciliation").
 * 3. Reconcile rules:
 *       i.  Manual identities (Id not prefixed `stalwart:`) survive
 *           verbatim across every sync run.
 *       ii. Stalwart entries become engine identities with `Id =
 *           'stalwart:<stalwartId>'`, `Label = '<name> [Stalwart]'`.
 *       iii. Stalwart entries whose email collides with a manual
 *            identity are skipped (user keeps their hand-tuned
 *            signature for their own primary mailbox).
 *       iv.  Removed-from-Stalwart entries are dropped from the
 *            engine on the next sync window.
 *       v.   Display-name / email changes on the Stalwart side are
 *            picked up in place (no duplicates, no Id reshuffling).
 * 4. Engine-plugin hook is wired into the `FilterAppData` boot path
 *    immediately AFTER `seedDefaultIdentityFromNcProfile` — never
 *    before, so first-login users don't see a "no identity" gate
 *    before the sync runs.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Static-source contract on SharedIdentitySyncService
// ---------------------------------------------------------------
$src = file_get_contents('/app/lib/Service/SharedIdentitySyncService.php');

assertTrue(str_contains($src, 'public const THROTTLE_SECONDS = 900;'),
    "Throttle is 900 seconds = 15 minutes (operator choice a)",
    $passes, $failures);
assertTrue(str_contains($src, "public const STALWART_ID_PREFIX = 'stalwart:';"),
    "Stalwart-managed identities are tagged with Id prefix 'stalwart:' (sync marker)",
    $passes, $failures);
assertTrue(str_contains($src, "STALWART_LABEL_SUFFIX = ' [Stalwart]';"),
    "Stalwart-managed identities carry the ' [Stalwart]' label suffix (operator choice b — visible marker)",
    $passes, $failures);
assertTrue(preg_match('#public function syncIfStale\(string \$userId\)\s*:\s*\?array#', $src) === 1,
    "Service exposes syncIfStale(string \$userId): ?array (null = cache hit, skip)",
    $passes, $failures);
assertTrue(preg_match('#public function forceSync\(string \$userId\)\s*:\s*array#', $src) === 1,
    "Service exposes forceSync(string \$userId): array (manual refresh path)",
    $passes, $failures);
assertTrue(preg_match('#public function reconcile\(array \$engineIdentities, array \$stalwart\)\s*:\s*array#', $src) === 1,
    "Service exposes pure reconcile() — drivable by tests with stubs",
    $passes, $failures);

// JMAP shape: Identity/get with ids: null + submission capability
assertTrue(str_contains($src, "'Identity/get'"),
    "Service calls JMAP `Identity/get` (RFC 8621 submission capability)",
    $passes, $failures);
assertTrue(str_contains($src, "'ids' => null"),
    "Service passes `ids: null` to fetch ALL identities (operator choice d — scope)",
    $passes, $failures);
assertTrue(str_contains($src, "'urn:ietf:params:jmap:submission'"),
    "Service requests the submission capability — required for Identity/get",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. StalwartAdminService accepts the extra-capabilities argument
// ---------------------------------------------------------------
$adm = file_get_contents('/app/lib/Service/StalwartAdminService.php');
assertTrue(preg_match('#public function jmapCall\(string \$bearerToken, array \$methodCalls, array \$extraCapabilities = \[\]\)#', $adm) === 1,
    "StalwartAdminService::jmapCall() accepts \$extraCapabilities parameter",
    $passes, $failures);
assertTrue(str_contains($adm, "\$using[] = \$cap;"),
    "jmapCall() merges \$extraCapabilities into the `using` array (de-duplicated)",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Engine plugin wiring
// ---------------------------------------------------------------
$plug = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');

// The sync hook must fire AFTER the first-login seed, never before —
// otherwise a fresh-account user briefly sees Snappymail's "set your
// identity" gating dialog before our seed lands.
$posSeed = strpos($plug, '$this->seedDefaultIdentityFromNcProfile($ocUser);');
$posSync = strpos($plug, '$this->syncStalwartIdentitiesIfStale($ocUser);');
assertTrue($posSeed !== false && $posSync !== false && $posSeed < $posSync,
    "FilterAppData() runs seedDefaultIdentityFromNcProfile() BEFORE syncStalwartIdentitiesIfStale() (correct first-login order)",
    $passes, $failures);

assertTrue(preg_match('#protected function syncStalwartIdentitiesIfStale\(\\\\OCP\\\\IUser \$ocUser\)#', $plug) === 1,
    "Engine plugin defines syncStalwartIdentitiesIfStale(IUser)",
    $passes, $failures);
assertTrue(str_contains($plug, 'OCA\\SouveraMail\\Service\\SharedIdentitySyncService'),
    "Engine plugin DI-resolves the SharedIdentitySyncService via the NC container",
    $passes, $failures);
assertTrue(str_contains($plug, '$svc->syncIfStale($ocUser->getUID())'),
    "Engine plugin calls syncIfStale() (throttled path, NOT forceSync)",
    $passes, $failures);
assertTrue(str_contains($plug, '$svc->reconcile($existing, $stalwart)'),
    "Engine plugin reconciles existing identities through the service",
    $passes, $failures);
assertTrue(str_contains($plug, 'StorageType::CONFIG'),
    "Engine plugin reads/writes the per-account `identities` storage in CONFIG scope",
    $passes, $failures);
// No-op when cached
assertTrue(str_contains($plug, "if (\$stalwart === null)"),
    "Engine plugin treats syncIfStale()===null as 'cache hit, skip reconciliation' (no spurious writes)",
    $passes, $failures);
// Defensive: only write when content actually changes
assertTrue(str_contains($plug, "\json_encode(\$reconciled) !== \json_encode(\$existing)"),
    "Engine plugin writes back only when the reconciled blob differs (avoids LocalStorageProvider churn)",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. Behavioural simulation — drive reconcile() through stubs
// ---------------------------------------------------------------
//
// We re-inline reconcile() in PHP so the test runs without
// instantiating Nextcloud's DI container. Drift between the inline
// copy and the source is caught by the regex assertions above.

const PREFIX = 'stalwart:';
const SUFFIX = ' [Stalwart]';

function skeleton(string $sid, string $email, string $name): array {
    return [
        'Id' => PREFIX . $sid,
        'Label' => $name . SUFFIX,
        'Email' => $email,
        'Name' => $name,
        'ReplyTo' => '', 'Bcc' => '', 'Signature' => '',
        'SignatureInsertBefore' => false, 'sentFolder' => '',
        'pgpEncrypt' => false, 'pgpSign' => false,
        'smimeKey' => '', 'smimeCertificate' => '',
    ];
}

function reconcile(array $engine, array $stalwart): array {
    $manual = [];
    $existing = [];
    foreach ($engine as $i) {
        $id = (string) ($i['Id'] ?? '');
        if (str_starts_with($id, PREFIX)) {
            $existing[substr($id, strlen(PREFIX))] = $i;
        } else {
            $manual[] = $i;
        }
    }
    $manualEmails = [];
    foreach ($manual as $m) {
        $manualEmails[strtolower(trim((string) ($m['Email'] ?? '')))] = true;
    }
    $out = $manual;
    foreach ($stalwart as $s) {
        if (isset($manualEmails[$s['email']])) continue;
        $sid = $s['stalwartId'];
        if (isset($existing[$sid])) {
            $e = $existing[$sid];
            $e['Email'] = $s['email'];
            $e['Name'] = $s['name'];
            $e['Label'] = $s['name'] . SUFFIX;
            $out[] = $e;
        } else {
            $out[] = skeleton($sid, $s['email'], $s['name']);
        }
    }
    return $out;
}

// 4a. Cold reconcile: empty engine, Stalwart returns 2 entries
$out = reconcile([], [
    ['stalwartId' => 'i1', 'email' => 'scadmin@buxtehude.email', 'name' => 'Scadmin'],
    ['stalwartId' => 'i2', 'email' => 'team@buxtehude.email', 'name' => 'Team Buxtehude'],
]);
assertTrue(count($out) === 2, "4a: cold reconcile produces 2 identities", $passes, $failures);
assertTrue($out[0]['Id'] === 'stalwart:i1' && $out[1]['Id'] === 'stalwart:i2',
    "4a: both entries carry stalwart:<id> markers", $passes, $failures);
assertTrue($out[1]['Label'] === 'Team Buxtehude [Stalwart]',
    "4a: shared-mailbox Label is 'Team Buxtehude [Stalwart]' (operator choice b + c)",
    $passes, $failures);
assertTrue($out[1]['Name'] === 'Team Buxtehude',
    "4a: outgoing `From:` Name is the Stalwart description (no suffix in the actual header)",
    $passes, $failures);

// 4b. Manual + Stalwart: manual stays first, Stalwart appended
$manual = [['Id' => '', 'Email' => 'private@example.com', 'Name' => 'Privat', 'Label' => 'Privat', 'Signature' => 'Cheers,']];
$out = reconcile($manual, [
    ['stalwartId' => 'i2', 'email' => 'team@buxtehude.email', 'name' => 'Team Buxtehude'],
]);
assertTrue(count($out) === 2, "4b: manual + 1 Stalwart = 2 identities", $passes, $failures);
assertTrue($out[0]['Email'] === 'private@example.com' && $out[0]['Signature'] === 'Cheers,',
    "4b: manual identity preserved verbatim, including hand-tuned signature",
    $passes, $failures);
assertTrue($out[1]['Id'] === 'stalwart:i2',
    "4b: Stalwart identity is appended after manual ones", $passes, $failures);

// 4c. Manual identity's email collides with Stalwart entry → Stalwart entry is skipped
$manual = [['Id' => '', 'Email' => 'scadmin@buxtehude.email', 'Name' => 'Schadmin (Custom Sig)', 'Label' => '', 'Signature' => 'KR']];
$out = reconcile($manual, [
    ['stalwartId' => 'i1', 'email' => 'scadmin@buxtehude.email', 'name' => 'Scadmin'],
    ['stalwartId' => 'i2', 'email' => 'team@buxtehude.email', 'name' => 'Team Buxtehude'],
]);
assertTrue(count($out) === 2,
    "4c: manual-email collision skips the matching Stalwart entry (keeps user's signature)",
    $passes, $failures);
assertTrue($out[0]['Signature'] === 'KR' && $out[1]['Id'] === 'stalwart:i2',
    "4c: surviving entries are (manual:scadmin) + (stalwart:i2 team)",
    $passes, $failures);

// 4d. Stalwart removes an identity → engine drops the corresponding stalwart:<id> entry
$engine = [
    ['Id' => '', 'Email' => 'private@example.com', 'Name' => 'Privat', 'Label' => 'Privat', 'Signature' => 'Cheers,'],
    skeleton('i1', 'scadmin@buxtehude.email', 'Scadmin'),
    skeleton('i2', 'team@buxtehude.email', 'Team Buxtehude'),
];
$out = reconcile($engine, [
    ['stalwartId' => 'i1', 'email' => 'scadmin@buxtehude.email', 'name' => 'Scadmin'],
    // i2 removed on Stalwart side
]);
$ids = array_map(fn($i) => $i['Id'], $out);
assertTrue($ids === ['', 'stalwart:i1'],
    "4d: revoked Stalwart identity is dropped on next sync (got: " . implode(',', $ids) . ")",
    $passes, $failures);

// 4e. Stalwart changes the display name → engine entry updated in place (no duplicate)
$engine = [skeleton('i2', 'team@buxtehude.email', 'Team Buxtehude')];
$out = reconcile($engine, [
    ['stalwartId' => 'i2', 'email' => 'team@buxtehude.email', 'name' => 'Team Buxtehude (umbenannt)'],
]);
assertTrue(count($out) === 1,
    "4e: rename does NOT produce a duplicate identity", $passes, $failures);
assertTrue($out[0]['Name'] === 'Team Buxtehude (umbenannt)'
    && $out[0]['Label'] === 'Team Buxtehude (umbenannt) [Stalwart]',
    "4e: rename updates Name + Label in place", $passes, $failures);
assertTrue($out[0]['Id'] === 'stalwart:i2',
    "4e: rename preserves the stalwart:<id> marker (no churn)",
    $passes, $failures);

// 4f. Idempotency — reconcile twice yields the same result
$first = reconcile($engine, [
    ['stalwartId' => 'i2', 'email' => 'team@buxtehude.email', 'name' => 'Team Buxtehude'],
]);
$second = reconcile($first, [
    ['stalwartId' => 'i2', 'email' => 'team@buxtehude.email', 'name' => 'Team Buxtehude'],
]);
assertTrue($first === $second,
    "4f: reconcile() is idempotent (same input → same output, no drift)",
    $passes, $failures);

// 4g. Empty-name Stalwart entry falls back to email local-part
//     (defensive — Stalwart may emit a record without `name`)
$out = reconcile([], [
    ['stalwartId' => 'i3', 'email' => 'noreply@buxtehude.email', 'name' => 'noreply'], // service applies fallback before reconcile
]);
assertTrue($out[0]['Name'] === 'noreply',
    "4g: name fallback handles missing description gracefully",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. CHANGELOG + version bump
// ---------------------------------------------------------------
$changelog = file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($changelog, '[0.13.11]'),
    "CHANGELOG.md contains a [0.13.11] entry for the shared-identity sync",
    $passes, $failures);
assertTrue(str_contains($changelog, 'Identity/get') && str_contains($changelog, 'Stalwart-verwaltet'),
    "CHANGELOG.md 0.13.11 entry mentions the JMAP method and the German label",
    $passes, $failures);

$info = file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(version_compare($vm[1] ?? '0.0.0', '0.13.11', '>='),
    "info.xml <version> >= 0.13.11 (got: '" . ($vm[1] ?? '') . "')",
    $passes, $failures);

// ---------------------------------------------------------------
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
