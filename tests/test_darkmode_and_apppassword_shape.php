<?php
/**
 * Regression test for the 0.13.20 Settings-tab dark-mode + AppPassword-
 * shape fixes.
 *
 * Two reports on 2026-07-01 (buxte cluster `fccec267`) drove
 * this release:
 *
 * 1) Dark mode: the Settings tab's section titles and the "Verbundene
 *    Geräte" table were invisible in NC 34 dark mode because the CSS
 *    fallback `--sv-fg: #1f2733` bled through onto a dark background.
 *    Only the OS-level `prefers-color-scheme: dark` was checked, not
 *    NC's own explicit dark markers (`body[data-theme-dark]` etc.), so
 *    users who picked Dark from NC personal settings while their OS is
 *    Light got unreadable text.
 *
 * 2) App-Password creation: `Stalwart refused AppPassword creation:
 *    {type:invalidPatch, description:"Invalid property",
 *     properties:["permissions/value"]}`. The 0.13.18 payload
 *    `{@type:Replace, value:[...]}` was wrong on the SHAPE: Stalwart
 *    0.16 wants a MAP `<perm-id> => bool`, not an array of perm-id
 *    strings. Live-verified via exhaustive schema fuzz.
 */
declare(strict_types=1);

$passes = [];
$failures = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ==============================================================
// Part A: Dark-mode CSS selectors
// ==============================================================

$tpl = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html');
ok($tpl !== '',
    "Settings template is readable and non-empty",
    $passes, $failures);

// 1. OS-level media query still present
ok(str_contains($tpl, '@media (prefers-color-scheme: dark)'),
    "OS-level prefers-color-scheme:dark media query is preserved",
    $passes, $failures);

// 2. NC 34 explicit dark markers added (both plain + high-contrast)
ok(str_contains($tpl, 'body[data-theme-dark] .souvera-settings'),
    "NC 34 dark theme selector `body[data-theme-dark]` is present",
    $passes, $failures);
ok(str_contains($tpl, 'body[data-theme-dark-highcontrast] .souvera-settings'),
    "NC 34 high-contrast dark theme selector is present",
    $passes, $failures);
ok(str_contains($tpl, '.theme--dark .souvera-settings'),
    "Legacy `.theme--dark` selector (used by older NC forks + Nextcloud 27–29 pinned setups) is present",
    $passes, $failures);

// 3. In dark mode we override --sv-fg to a LIGHT value so section
//    titles are readable — that was THE bug 2026-07-01.
ok((bool) preg_match(
    '/body\[data-theme-dark\] \.souvera-settings\s*,[^{]*\{\s*(?:[^}]*?\s)*--sv-fg:\s*#e4e6eb/s',
    $tpl
), "NC dark theme override sets --sv-fg to a light hex (#e4e6eb)",
    $passes, $failures);

ok((bool) preg_match(
    '/@media \(prefers-color-scheme: dark\)\s*\{[^}]*?\.souvera-settings\s*\{[^}]*?--sv-fg:\s*#e4e6eb/s',
    $tpl
), "OS-level dark media also sets --sv-fg to the light hex",
    $passes, $failures);

// 4. Muted foreground is also overridden — otherwise "sub"/"muted"
//    labels stay at the light-mode #6c7886 which reads as illegible
//    charcoal on dark surfaces.
ok(str_contains($tpl, '--sv-fg-muted: #9aa4b1'),
    "Dark theme overrides --sv-fg-muted to #9aa4b1 (sub-labels stay readable)",
    $passes, $failures);

// 5. Banners get proper dark-mode variants (the ok banner had a
//    hardcoded #14532d = dark green, which is unreadable on dark
//    background where the banner tint is only 14% alpha).
foreach (['sv-banner-warn', 'sv-banner-ok', 'sv-banner-err', 'sv-secret-user'] as $cls) {
    ok((bool) preg_match(
        "#body\\[data-theme-dark\\]\\s+\\.souvera-settings\\s+\\.{$cls}#",
        $tpl
    ), "Dark theme has explicit override for .{$cls}",
        $passes, $failures);
}

// 6. Sanity: the light-mode default palette (--sv-fg: #1f2733) is
//    still there so we didn't break light mode by accident.
ok(str_contains($tpl, '--sv-fg: var(--main-text, #1f2733)'),
    "Light-mode default palette is preserved (regression guard)",
    $passes, $failures);

// ==============================================================
// Part B: AppPassword permissions wire-format
// ==============================================================

$svc = (string) file_get_contents('/app/lib/Service/AppPasswordService.php');
ok($svc !== '',
    "AppPasswordService.php is readable",
    $passes, $failures);

// 7. Uses the correct MAP shape (not array)
ok((bool) preg_match(
    "#'permissions'\\s*=>\\s*\\\\?array_fill_keys\\(\\s*self::APP_PASSWORD_PERMISSIONS,\\s*true,?\\s*\\)#s",
    $svc
), "Payload uses array_fill_keys(perms, true) → JSON map <perm>:true",
    $passes, $failures);

// 8. Neither of the two rejected shapes leaks back in:
ok(!(bool) preg_match(
    "#'value'\\s*=>\\s*self::APP_PASSWORD_PERMISSIONS#",
    $svc
), "OLD shape `'value' => APP_PASSWORD_PERMISSIONS` is GONE (rejected by Stalwart 0.16 as invalidPatch)",
    $passes, $failures);
ok(!(bool) preg_match(
    "#'permissions'\\s*=>\\s*self::APP_PASSWORD_PERMISSIONS#",
    $svc
), "Even older shape `'permissions' => APP_PASSWORD_PERMISSIONS` (bare list) is GONE",
    $passes, $failures);

// 9. Still uses Replace patch tag
ok(str_contains($svc, "'@type' => 'Replace'"),
    "Payload still declares @type=Replace",
    $passes, $failures);

// 10. Removed the invalid `imapUnsubscribe` perm-id (Stalwart 0.16
//     removed it — subscribe/unsub fold into a single perm).
ok(!str_contains($svc, "'imapUnsubscribe'"),
    "Invalid perm-id `imapUnsubscribe` removed (Stalwart 0.16 does not know it)",
    $passes, $failures);

// 11. Comment documents the trial-and-error map-shape discovery
ok(str_contains($svc, 'MAP of'),
    "Payload comment documents the perm-map shape (protection against future refactors)",
    $passes, $failures);

// ==============================================================
// Part C: Version bump
// ==============================================================

$info = (string) file_get_contents('/app/appinfo/info.xml');
ok((bool) preg_match('#<version>0\.(?:1[4-9]|[2-9]\d)\.\d+</version>#', $info),
    "appinfo/info.xml version is at ≥0.13.20 (this release or later, e.g. 0.14.x)",
    $passes, $failures);

// ==============================================================
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
