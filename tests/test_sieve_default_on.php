<?php
/**
 * Regression test for the Filters / Sieve UX bug fixed in Souvera Mail 0.13.8.
 *
 * Symptom (live env, NC34 + Stalwart 0.16, 2026-07-01)
 * -----------------------------------------------------
 * Opening Settings → Filters in the engine produced "ERROR 1" — the
 * frontend's catch-all for `Notifications::RequestError = 1`, returned
 * when the backend's `DoFilters()` action calls `FalseResponse()`.
 * The root cause was in the engine's Actions::Capa() resolver:
 *
 *     $aResult[Capa::SIEVE->value] = $bAdmin
 *         || ($oAccount && $oAccount->Domain()->SieveSettings()->enabled);
 *
 * `SieveSettings()->enabled` mirrors the `Sieve.enabled` key in the
 * engine's domain config, which `DomainConfigService::buildDomainConfig()`
 * derives from the `--sieve` flag of `occ souvera_mail:setup`. The flag
 * was declared as `VALUE_NONE` (boolean opt-IN flag, default false), so
 * any operator who didn't pass `--sieve` shipped a config with Sieve
 * disabled — the engine then refused every Filters request with the
 * generic ERROR 1.
 *
 * Fix
 * ---
 * Stalwart 0.16 ships ManageSieve on port 4190 natively, with the
 * exact same OAUTHBEARER mechanism as IMAP/SMTP and the exact same
 * H2CK JWT — there is no scenario where it makes sense to ship a
 * mail-server profile with IMAP+SMTP on but Sieve off when targeting
 * Stalwart. The `--sieve` flag is now `VALUE_NEGATABLE` and defaults
 * to `true`. Operators who want Sieve off explicitly pass `--no-sieve`.
 *
 * This test pins both the CLI shape and the wiring through to the
 * engine domain config.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Setup command — --sieve is now NEGATABLE and defaults to true
// ---------------------------------------------------------------
$src = file_get_contents('/app/lib/Command/Setup.php');

assertTrue(str_contains($src, "InputOption::VALUE_NEGATABLE"),
    "Setup.php uses Symfony's VALUE_NEGATABLE for the --sieve flag", $passes, $failures);
assertTrue((bool) preg_match("#addOption\('sieve',\s*null,\s*InputOption::VALUE_NEGATABLE,[^,]+,\s*true\s*\)#", $src),
    "Setup.php --sieve defaults to true (Stalwart 0.16 native ManageSieve, OAUTHBEARER)",
    $passes, $failures);
assertTrue(!preg_match("#addOption\('sieve',\s*null,\s*InputOption::VALUE_NONE#", $src),
    "Setup.php --sieve is NOT VALUE_NONE any more (the old opt-in default that caused ERROR 1)",
    $passes, $failures);

// The execute() resolution must honour the default for callers that pass
// neither --sieve nor --no-sieve.
assertTrue(str_contains($src, "\$input->getOption('sieve') ?? true"),
    "Setup::execute() defaults Sieve enablement to true when the flag is not specified",
    $passes, $failures);

// Help text must explain that Stalwart needs no extra config
assertTrue(str_contains($src, 'Stalwart') && str_contains($src, '--no-sieve'),
    "Setup.php --sieve help text mentions Stalwart + the --no-sieve opt-out",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. DomainConfigService — Sieve.enabled still flows through unchanged
// ---------------------------------------------------------------
$dom = file_get_contents('/app/lib/Service/DomainConfigService.php');
assertTrue((bool) preg_match("#'enabled'\s*=>\s*\\\$sieve\b#", $dom),
    "DomainConfigService writes the \$sieve flag into Sieve.enabled (unchanged)",
    $passes, $failures);
assertTrue((bool) preg_match("#'sasl'\s*=>\s*\\\$oauthSasl#", $dom),
    "DomainConfigService still advertises OAUTHBEARER/XOAUTH2 for the Sieve listener",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Behavioural simulation — drive the resolution logic with stubs
// ---------------------------------------------------------------
//
// Re-inline the new resolution expression and drive it with the
// three possible Symfony Console states for VALUE_NEGATABLE:
//   - user passed --sieve            → getOption returns true
//   - user passed --no-sieve         → getOption returns false
//   - user passed neither            → getOption returns the default (true)

function simResolveSieveEnabled(mixed $optionValue): bool {
    return (bool) ($optionValue ?? true);
}

assertTrue(simResolveSieveEnabled(true) === true,
    "3a: --sieve passed explicitly → Sieve enabled", $passes, $failures);
assertTrue(simResolveSieveEnabled(false) === false,
    "3b: --no-sieve passed explicitly → Sieve disabled", $passes, $failures);
assertTrue(simResolveSieveEnabled(null) === true,
    "3c: neither flag passed → Sieve enabled (the default-on bug fix)",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. CHANGELOG mentions the fix
// ---------------------------------------------------------------
$changelog = file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($changelog, '[0.13.8]'),
    "CHANGELOG.md contains a [0.13.8] entry for the Filters fix", $passes, $failures);
assertTrue(str_contains($changelog, 'Sieve') && str_contains($changelog, 'ERROR 1'),
    "CHANGELOG.md 0.13.8 entry explains the ERROR 1 / Sieve symptom",
    $passes, $failures);

// info.xml version is bumped
$info = file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(version_compare($vm[1] ?? '0.0.0', '0.13.8', '>='),
    "info.xml <version> >= 0.13.8 (got: '" . ($vm[1] ?? '') . "')",
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
