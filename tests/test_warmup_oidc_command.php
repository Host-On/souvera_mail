<?php
/**
 * Regression test for the WarmupOidc command added in Souvera Mail 0.13.18.
 *
 * Background (2026-07-01)
 * ----------------------
 * On a fresh Proxmox redeploy the newly-provisioned Stalwart 0.16 rejected
 * every OIDC-JWT-authenticated JMAP/IMAP/SMTP request with a bare
 * `401 Unauthorized`. Root cause: Stalwart's OIDC directory lazily fetches
 * the H2CK/oidc discovery document at `<issuerUrl>/.well-known/openid-
 * configuration`, and Nextcloud's shipped `.htaccess` returns a 301 redirect
 * on that path. Stalwart doesn't follow the redirect, silently caches a
 * failed fetch, and never re-tries until an admin nudges the Directory
 * object.
 *
 * The fix is a new `occ souvera_mail:warmup-oidc` command that:
 *   1. Mints an H2CK/oidc probe JWT.
 *   2. Sends `GET /jmap/session` — HTTP 200 means the cache is warm, exit 0.
 *   3. Otherwise Basic-auths to Stalwart admin JMAP, runs
 *      `x:Directory/query` + `x:Directory/set` on each Oidc directory,
 *      then re-probes and reports.
 *
 * This test pins the command wiring so an accidental rename/removal is
 * caught in CI before it reaches operator deploys.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Command file exists and is well-formed
// ---------------------------------------------------------------
$path = '/app/lib/Command/WarmupOidc.php';
ok(is_file($path),
    "lib/Command/WarmupOidc.php exists",
    $passes, $failures);

$src = (string) @file_get_contents($path);
ok($src !== '',
    "WarmupOidc.php is readable and non-empty",
    $passes, $failures);

ok(str_contains($src, 'namespace OCA\SouveraMail\Command;'),
    "WarmupOidc declares the correct namespace",
    $passes, $failures);

ok(str_contains($src, 'extends Command'),
    "WarmupOidc extends Symfony\\Component\\Console\\Command\\Command",
    $passes, $failures);

ok(str_contains($src, "->setName('souvera_mail:warmup-oidc')"),
    "Command name is 'souvera_mail:warmup-oidc' (verbatim — deployment scripts grep for it)",
    $passes, $failures);

ok(str_contains($src, "->addOption('json'"),
    "Command exposes --json for CI-consumable output",
    $passes, $failures);

ok(str_contains($src, "->addOption('user'"),
    "Command exposes --user override for probe token minting",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. Registered in appinfo/info.xml so occ picks it up
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
ok(str_contains($info, '<command>OCA\SouveraMail\Command\WarmupOidc</command>'),
    "info.xml <commands> registers WarmupOidc so `occ list` surfaces it",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Dependency contract: uses OidcProviderService (for probe JWT),
//    StalwartAdminService (for admin JMAP), StalwartUserContext (for
//    per-user token resolution). No direct HTTP client — that goes
//    through StalwartAdminService.
// ---------------------------------------------------------------
ok(str_contains($src, 'OidcProviderService $oidc'),
    "Ctor injects OidcProviderService (used to mint the probe JWT)",
    $passes, $failures);

ok(str_contains($src, 'StalwartAdminService $stalwart'),
    "Ctor injects StalwartAdminService (used for the /jmap/session probe + admin JMAP)",
    $passes, $failures);

ok(str_contains($src, 'StalwartUserContext $userContext'),
    "Ctor injects StalwartUserContext (used to resolve the per-user Bearer JWT)",
    $passes, $failures);

ok(preg_match('#IUserManager\s+\$userManager#', $src) === 1,
    "Ctor injects IUserManager (used to look up the probe user)",
    $passes, $failures);

// The command MUST NOT reach for IClientService directly — every network
// call flows through StalwartAdminService so cURL / OCP-http concerns
// stay in one place.
ok(!preg_match('#use\s+OCP\\\\Http\\\\Client\\\\IClientService;#', $src),
    "WarmupOidc does not import IClientService (delegates HTTP to StalwartAdminService)",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. StalwartAdminService exposes the new admin+probe surface
// ---------------------------------------------------------------
$adm = (string) file_get_contents('/app/lib/Service/StalwartAdminService.php');

ok(preg_match('#public function jmapCallAsAdmin\(array \$methodCalls, array \$extraCapabilities = \[\]\)\s*:\s*array#', $adm) === 1,
    "StalwartAdminService::jmapCallAsAdmin(array, array): array (Basic-auth JMAP for privileged flows)",
    $passes, $failures);

ok(preg_match('#public function probeSessionAsUser\(string \$bearerToken\)\s*:\s*int#', $adm) === 1,
    "StalwartAdminService::probeSessionAsUser(string): int (returns HTTP status code, does not throw on 4xx)",
    $passes, $failures);

ok(preg_match('#public function getAdminCredentials\(\)\s*:\s*\?array#', $adm) === 1,
    "StalwartAdminService::getAdminCredentials(): ?array — pulls Basic-auth from souvera_central system config",
    $passes, $failures);

ok(str_contains($adm, "SYSTEM_CONFIG_ADMIN_USER = 'souvera_central.stalwart_admin_user'"),
    "Admin username source is the souvera_central.stalwart_admin_user system-config key",
    $passes, $failures);

ok(str_contains($adm, "SYSTEM_CONFIG_ADMIN_PASSWORD = 'souvera_central.stalwart_admin_password'"),
    "Admin password source is the souvera_central.stalwart_admin_password system-config key",
    $passes, $failures);

// Regression: the existing per-user jmapCall() must still exist with the same
// signature — StalwartUserContext + AppPasswordService + QuotaService + Sieve
// all rely on it. This test file's sibling shared-identity sync test also
// asserts the extraCapabilities parameter — we don't duplicate that here.
ok(preg_match('#public function jmapCall\(string \$bearerToken, array \$methodCalls, array \$extraCapabilities = \[\]\)#', $adm) === 1,
    "StalwartAdminService::jmapCall() signature unchanged (regression guard)",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. Admin JMAP payload uses the Stalwart 0.16 x:Directory namespace
// ---------------------------------------------------------------
ok(str_contains($src, "'x:Directory/query'"),
    "WarmupOidc calls the Stalwart-namespaced x:Directory/query method",
    $passes, $failures);

ok(str_contains($src, "'x:Directory/get'"),
    "WarmupOidc calls x:Directory/get to resolve each id and filter by @type (Stalwart 0.16 doesn't support filter on @type)",
    $passes, $failures);

ok(str_contains($src, "'x:Directory/set'"),
    "WarmupOidc calls x:Directory/set to trigger the OIDC re-fetch (flip-flop of issuerUrl — description-only touches don't reset Stalwart's OIDC provider cache)",
    $passes, $failures);

ok(str_contains($src, "'@type'") && str_contains($src, "'Oidc'"),
    "WarmupOidc filters retrieved Directory records by @type == 'Oidc' client-side",
    $passes, $failures);

ok(str_contains($src, "str_ends_with(\$orig, '/')"),
    "WarmupOidc flip-flops issuerUrl by toggling the trailing slash (the smallest change Stalwart still treats as a real update)",
    $passes, $failures);

ok(str_contains($src, "'issuerUrl' => \$flipped") && str_contains($src, "'issuerUrl' => \$orig"),
    "WarmupOidc issues TWO Directory/set updates per OIDC directory: one to flip, one to restore",
    $passes, $failures);

ok(\substr_count($src, "'@type' => 'ReloadSettings'") >= 2,
    "WarmupOidc creates ReloadSettings AFTER each half of the flip-flop (≥2 ReloadSettings calls in the refresh path)",
    $passes, $failures);

ok(str_contains($src, "'@type' => 'InvalidateCaches'"),
    "WarmupOidc also issues an InvalidateCaches action (per-account token cache is separate from OIDC provider cache)",
    $passes, $failures);

// The 401→refresh→retry loop needs a probe-status check equal to 200.
ok(preg_match('#\$status\s*===\s*200#', $src) === 1,
    "Command short-circuits on HTTP 200 (warm cache path)",
    $passes, $failures);

// ---------------------------------------------------------------
// 6. Command must be idempotent — repeated runs on an already-warm
//    server should be a no-op (initial probe returns 200, admin
//    refresh is never invoked). This test pins the code path.
// ---------------------------------------------------------------
ok(preg_match('#if\s*\(\s*\$status\s*===\s*200\s*\)\s*\{\s*\$report\[.ok.\]\s*=\s*true\s*;\s*return\s+\$this->emit#', $src) === 1,
    "Idempotency: initial probe HTTP 200 returns early — no admin write on a warm server",
    $passes, $failures);

// ---------------------------------------------------------------
// 7. `souvera_mail:status` still exists (regression guard for the
//    JSON report format that CI relies on)
// ---------------------------------------------------------------
ok(is_file('/app/lib/Command/Status.php'),
    "regression guard: lib/Command/Status.php still exists",
    $passes, $failures);
ok(is_file('/app/lib/Command/Setup.php'),
    "regression guard: lib/Command/Setup.php still exists",
    $passes, $failures);
ok(is_file('/app/lib/Command/Reset.php'),
    "regression guard: lib/Command/Reset.php still exists",
    $passes, $failures);
ok(is_file('/app/lib/Command/Bootstrap.php'),
    "regression guard: lib/Command/Bootstrap.php still exists",
    $passes, $failures);
ok(is_file('/app/lib/Command/Oidc/RegisterClient.php'),
    "regression guard: lib/Command/Oidc/RegisterClient.php still exists",
    $passes, $failures);

// ---------------------------------------------------------------
// 8. `php -l` syntax gate
// ---------------------------------------------------------------
$lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
ok(is_string($lint) && str_contains($lint, 'No syntax errors detected'),
    "WarmupOidc.php passes `php -l`",
    $passes, $failures);
$lint2 = shell_exec('php -l /app/lib/Service/StalwartAdminService.php 2>&1');
ok(is_string($lint2) && str_contains($lint2, 'No syntax errors detected'),
    "StalwartAdminService.php still passes `php -l` after the admin+probe additions",
    $passes, $failures);

// ---------------------------------------------------------------
// Summary
// ---------------------------------------------------------------
echo "\n";
echo "PASS: " . count($passes) . " assertions\n";
echo "FAIL: " . count($failures) . " assertions\n";
if (!empty($failures)) {
    echo "\nFailures:\n";
    foreach ($failures as $m) { echo " - $m\n"; }
    exit(1);
}
echo "\nALL TESTS PASSED\n";
exit(0);
