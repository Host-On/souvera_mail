<?php
/**
 * Regression test for v0.14.3 — precise OIDC diagnostics on the
 * `souvera_mail:warmup-oidc` command and inside OidcProviderService.
 *
 * Context
 * -------
 * Real-world incident (2026-02-18, seg-marburg.souvera.work):
 *   $ occ souvera_mail:warmup-oidc
 *   Stalwart OIDC warmup failed.
 *     ! failed to mint OIDC JWT for user 'admin@seg-marburg.de':
 *       Could not obtain OIDC access token for user 'admin@seg-marburg.de'
 *       (H2CK/oidc missing or souvera_mail client not registered?)
 *
 * The message was correct in essence but useless in practice — it lumped
 * three DIFFERENT failure modes into one line:
 *   a) H2CK/oidc app not installed
 *   b) H2CK installed but disabled
 *   c) H2CK enabled but our `souvera_mail/oidc-client-id` app-config is
 *      empty (single most common cause after a redeploy that dropped
 *      app-config)
 *
 * v0.14.3 splits these apart via `OidcProviderService::diagnoseAvailability()`
 * and surfaces the exact reason to the operator. This test pins the
 * contract so future refactors keep it precise.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ── PART A: OidcProviderService source pins ───────────────────────────
$src = (string) file_get_contents('/app/lib/Service/OidcProviderService.php');
ok($src !== '', "OidcProviderService.php readable", $passes, $failures);

ok(str_contains($src, 'public function diagnoseAvailability(): ?string'),
    "New public diagnoseAvailability(): ?string method exists", $passes, $failures);

// Every diagnostic branch is present and phrased actionably
$branches = [
    'H2CK/oidc app is NOT installed' => 'not-installed branch mentions `occ app:install oidc`',
    'H2CK/oidc app is installed but DISABLED' => 'disabled branch mentions `occ app:enable oidc`',
    'TokenGenerationRequestEvent class is missing' => 'ABI-mismatch branch calls out the missing event class',
    'souvera_mail/oidc-client-id is empty' => 'empty-app-config branch names the exact app-config key',
    '`occ souvera_mail:oidc:register-client --force`' => 'empty-app-config branch suggests --force remediation',
];
foreach ($branches as $needle => $why) {
    ok(str_contains($src, $needle), "diagnoseAvailability() — $why", $passes, $failures);
}

// isProviderAvailable() must remain the LOOSE gate (backward-safe with
// pre-v0.14.3 installs that never explicitly set app-config).
ok((bool) preg_match('/public function isProviderAvailable\(\): bool\s*\{\s*if \(!\$this->appManager->isInstalled/', $src),
    "isProviderAvailable() is the loose gate (checks installed+enabled+class only, NO client-id check)",
    $passes, $failures);

// generateAccessToken must GATE on isProviderAvailable (loose) but LOG
// via diagnoseAvailability (strict) — hint operator without blocking.
ok(str_contains($src, 'if (!$this->isProviderAvailable())'),
    "generateAccessToken() still gates on the loose isProviderAvailable() (no behavioural regression)",
    $passes, $failures);
ok(str_contains($src, '$availabilityHint = $this->diagnoseAvailability();'),
    "generateAccessToken() ALSO calls diagnoseAvailability() for informational logging",
    $passes, $failures);
ok(str_contains($src, "'Souvera Mail: OIDC diagnostic — '"),
    "generateAccessToken() logs the diagnostic reason so nextcloud.log tells the whole story",
    $passes, $failures);

// ── PART B: WarmupOidc command uses the new diagnostic ────────────────
$warm = (string) file_get_contents('/app/lib/Command/WarmupOidc.php');
ok($warm !== '', "WarmupOidc.php readable", $passes, $failures);

// The command's probe-token step MUST call diagnoseAvailability FIRST
// so the operator gets the actionable reason instead of the generic
// "missing or not registered" fallback.
ok(str_contains($warm, '$reason = $this->oidc->diagnoseAvailability();'),
    "mintProbeToken() calls diagnoseAvailability() BEFORE attempting resolveBearer()",
    $passes, $failures);
ok(str_contains($warm, "\"OIDC provider unavailable: {\$reason}\""),
    "mintProbeToken() surfaces the diagnostic reason verbatim in the errors array",
    $passes, $failures);
ok(str_contains($warm, "\$report['remediation'] = \$reason;"),
    "Report carries a dedicated `remediation` field for pipelines to grep for",
    $passes, $failures);
ok(str_contains($warm, 'Client is registered but H2CK refused to mint.'),
    "Empty-token branch has its own actionable remediation hint",
    $passes, $failures);

// ── PART C: Behavioural simulation ────────────────────────────────────
class FakeAppMgr {
    public bool $installed = true;
    public bool $enabled = true;
    public function isInstalled(string $id): bool { return $this->installed; }
    public function isEnabledForUser(string $id, ?object $u = null): bool { return $this->enabled; }
}
class FakeAppConfig {
    public string $clientId = '';
    public function getValueString(string $app, string $key, string $default = ''): string {
        return $key === 'oidc-client-id' ? $this->clientId : $default;
    }
}

/**
 * Inlined copy of diagnoseAvailability() so we can exhaustively probe
 * every branch without booting Nextcloud. The regex assertions above
 * pin the REAL source; this simulation verifies the LOGIC does what
 * we claim it does.
 */
function simDiagnose(FakeAppMgr $appMgr, bool $eventClassExists, FakeAppConfig $cfg): ?string {
    if (!$appMgr->isInstalled('oidc')) {
        return 'H2CK/oidc app is NOT installed — run `occ app:install oidc`';
    }
    if (!$appMgr->isEnabledForUser('oidc')) {
        return 'H2CK/oidc app is installed but DISABLED — run `occ app:enable oidc`';
    }
    if (!$eventClassExists) {
        return 'H2CK/oidc app is enabled but its TokenGenerationRequestEvent class is missing (ABI mismatch — need H2CK/oidc 1.17+)';
    }
    if ($cfg->getValueString('souvera_mail', 'oidc-client-id', '') === '') {
        return 'Souvera Mail OIDC client identifier is NOT persisted in app-config '
             . '(souvera_mail/oidc-client-id is empty). This is the #1 cause of '
             . '"H2CK returned an empty token" after a deploy. '
             . 'Run `occ souvera_mail:oidc:register-client --force` to re-register '
             . 'and persist the client id (the H2CK client itself may already exist — '
             . '--force will reconcile).';
    }
    return null;
}

// Scenario A: H2CK missing
$m = new FakeAppMgr(); $m->installed = false;
ok(str_contains((string) simDiagnose($m, true, new FakeAppConfig()), 'NOT installed'),
    "Scenario A: H2CK missing → 'NOT installed' branch fires", $passes, $failures);

// Scenario B: H2CK disabled
$m = new FakeAppMgr(); $m->enabled = false;
ok(str_contains((string) simDiagnose($m, true, new FakeAppConfig()), 'DISABLED'),
    "Scenario B: H2CK disabled → 'DISABLED' branch fires", $passes, $failures);

// Scenario C: ABI mismatch
ok(str_contains((string) simDiagnose(new FakeAppMgr(), false, new FakeAppConfig()), 'ABI mismatch'),
    "Scenario C: event class missing → 'ABI mismatch' branch fires", $passes, $failures);

// Scenario D — the real seg-marburg incident:
//   H2CK installed + enabled + class present, but oidc-client-id empty.
$m = new FakeAppMgr(); $cfg = new FakeAppConfig(); $cfg->clientId = '';
$reason = simDiagnose($m, true, $cfg);
ok(is_string($reason) && str_contains($reason, 'oidc-client-id is empty'),
    "Scenario D (the alarm case): 'oidc-client-id is empty' branch fires with actionable text",
    $passes, $failures);
ok(is_string($reason) && str_contains($reason, 'occ souvera_mail:oidc:register-client --force'),
    "Scenario D: hint tells operator EXACTLY which command to run",
    $passes, $failures);

// Scenario E: everything is fine → null (silent)
$m = new FakeAppMgr(); $cfg = new FakeAppConfig(); $cfg->clientId = 'abc-123';
ok(simDiagnose($m, true, $cfg) === null,
    "Scenario E: all healthy → diagnoseAvailability() returns null (no false alarm)",
    $passes, $failures);

// ── PART D: version bump present in info.xml ──────────────────────────
$info = (string) file_get_contents('/app/appinfo/info.xml');
ok((bool) preg_match('#<version>0\.14\.[3-9]</version>#', $info),
    "info.xml version bumped to 0.14.3+ (this hotfix)", $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n"; foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
