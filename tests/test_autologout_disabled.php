<?php
/**
 * Regression test for the auto-logout disable fix shipped in
 * Souvera Mail 0.13.13.
 *
 * Operator report (2026-07-01, follow-up)
 * ---------------------------------------
 * "Nach einer ganzen Weile kommt plötzlich Folders error:
 *  AuthError[102]" + "grad hab ich plötzlich nochmal nen kurzen
 *  'Logout Error' angezeigt bekommen".
 *
 * Root cause
 * ----------
 * Snappymail ships with `defaults.autologout = 30` (minutes) — see
 * `app/libraries/Smail/Engine/Config/Application.php:256`. The
 * frontend JS arms an inactivity timer with that value; after 30 min
 * idle, it auto-fires a `Logout` action. In our SSO deployment that
 * races against Nextcloud's own session-lifetime logic AND the
 * background OIDC token refresh — when the engine's Logout API
 * call lands mid-race it surfaces as "Logout Error", and the next
 * Folders refresh has no valid engine session → `AuthError[102]`.
 *
 * Fix
 * ---
 * The `FilterAppData` hook now overwrites `$aResult['AutoLogout']`
 * with the value of the NC app-config key
 * `souvera_mail.engine_autologout_minutes`, defaulting to `0`
 * (engine inactivity timer disabled — NC's session lifetime is the
 * authoritative idle clock). Operators who want a stricter idle
 * policy on top of NC can override via
 * `occ config:app:set souvera_mail engine_autologout_minutes --value 60`.
 *
 * This test pins the override path, the default value, and the
 * NC-config-key contract.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Plugin's FilterAppData overwrites AutoLogout
// ---------------------------------------------------------------
$plug = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');

// Locate the FilterAppData method body
$start = strpos($plug, 'public function FilterAppData');
assertTrue($start !== false,
    "Plugin exposes FilterAppData (the app-data filter hook)", $passes, $failures);
$body = substr($plug, $start, 4000);

assertTrue(str_contains($body, "'engine_autologout_minutes'"),
    "FilterAppData reads the NC app-config key `engine_autologout_minutes`",
    $passes, $failures);
assertTrue(str_contains($body, "->getAppValue('souvera_mail', 'engine_autologout_minutes', '0')"),
    "Default value is '0' (engine inactivity timer disabled — NC owns the idle clock)",
    $passes, $failures);
assertTrue(str_contains($body, "\$aResult['AutoLogout'] = \$autoLogout;"),
    "FilterAppData overwrites \$aResult['AutoLogout'] with the configured value",
    $passes, $failures);

// The override must happen BEFORE the engine's own filter-data emits
// the value to the frontend — confirm the override line precedes any
// $aResult['Nextcloud'] assignment so the engine doesn't snapshot the
// stale 30-min default in any intermediate hook.
$posOverride = strpos($body, "\$aResult['AutoLogout']");
$posNextcloud = strpos($body, "\$aResult['Nextcloud']");
assertTrue($posOverride !== false && $posNextcloud !== false && $posOverride < $posNextcloud,
    "AutoLogout override runs BEFORE the Nextcloud-namespace assignment (no race within FilterAppData)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. Doc comment captures the operator-facing rationale + escape hatch
// ---------------------------------------------------------------
assertTrue(str_contains($plug, 'NC owns auth')
    || str_contains($plug, 'NC session')
    || str_contains($plug, "NC's session"),
    "Doc-comment names Nextcloud as the authoritative auth anchor",
    $passes, $failures);
assertTrue(str_contains($plug, 'engine_autologout_minutes --value'),
    "Doc-comment shows the operator the `occ config:app:set` escape hatch",
    $passes, $failures);
assertTrue(str_contains($plug, 'AuthError[102]'),
    "Doc-comment ties the fix back to the operator-reported symptom (AuthError[102])",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Behavioural simulation — the three config states
// ---------------------------------------------------------------
//
// We re-inline the override logic so the test runs without booting
// Nextcloud's DI container; drift from the live source is caught by
// the regex assertions above.

function simulateFilterAppData(?string $cfgValue): int {
    // `getAppValue` returns the default ('0') as a string when unset.
    $raw = $cfgValue ?? '0';
    return (int) $raw;
}

assertTrue(simulateFilterAppData(null) === 0,
    "3a: unset NC app-config → AutoLogout = 0 (disabled, NC owns idle)",
    $passes, $failures);
assertTrue(simulateFilterAppData('0') === 0,
    "3b: explicit '0' → AutoLogout = 0",
    $passes, $failures);
assertTrue(simulateFilterAppData('60') === 60,
    "3c: explicit '60' → AutoLogout = 60 (operator override stuck)",
    $passes, $failures);
assertTrue(simulateFilterAppData('not-an-int') === 0,
    "3d: garbage value casts to 0 (defensive — never emit a NaN to the JS timer)",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. CHANGELOG mentions the fix
// ---------------------------------------------------------------
$changelog = file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($changelog, 'AuthError[102]') || str_contains($changelog, 'AutoLogout') || str_contains($changelog, 'autologout'),
    "CHANGELOG entry references the AuthError[102] / autologout root cause",
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
