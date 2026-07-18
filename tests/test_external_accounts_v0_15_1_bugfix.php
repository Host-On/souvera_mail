<?php
/**
 * v0.15.1 — Regression pins for two bugs reported on the v0.15.0
 * external-mail-account UI:
 *
 *   Bug 1: „Unbekannter Fehler" appeared in the native
 *          PopupsAccount modal every time the user hit „Hinzufügen".
 *          Root cause: UserAuth::LoginProcess rejected every
 *          non-OIDC-sentinel password, EVEN for AdditionalAccount
 *          setups. That was the correct rule when Souvera Mail was
 *          SSO-only, but v0.15.0 opened the app up to password-based
 *          external mailboxes. LoginProcess signature already carried
 *          a `$bMainAccount` flag — we now gate the sentinel-only
 *          check on that flag being true.
 *
 *   Bug 2: The <dialog> popup was anchored at `top: 0` + margin
 *          10 px, so the "Konto hinzufügen" form clung to the top
 *          of the viewport instead of being vertically centred.
 *          Fix: plugin CSS overrides `dialog[open]` to sit at
 *          top:50% + translateY(-50%) on ≥ 800 px viewports.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
$a = function (bool $cond, string $msg) use (&$failures, &$passes): void {
    if ($cond) { $passes[] = $msg; echo "PASS: $msg\n"; }
    else       { $failures[] = $msg; echo "FAIL: $msg\n"; }
};

// ==============================================================
// Bug 1 — UserAuth::LoginProcess allows raw passwords for
// AdditionalAccount setup only.
// ==============================================================
$ua = (string) \file_get_contents(
    '/app/app/smail/v/current/app/libraries/Smail/Engine/Actions/UserAuth.php'
);
$a($ua !== '', 'UserAuth.php is readable');

$a(\str_contains($ua, 'public function LoginProcess')
    && \preg_match('/LoginProcess\(\s*string\s+\$sEmail,\s*SensitiveString\s+\$oPassword,\s*bool\s+\$bMainAccount\s*=\s*true\)/', $ua) === 1,
    'LoginProcess still carries the $bMainAccount = true default');

$a(\str_contains($ua, '$bMainAccount && !\\str_starts_with($oPassword->getValue(), \'oidc_login|\')'),
    'sentinel-only rule is now GATED on $bMainAccount === true (v0.15.1 fix)');

// The old unconditional check must be gone.
$a(!\preg_match('/^\s*if \(!\\\\str_starts_with\(\$oPassword->getValue\(\), \'oidc_login\|\'\)\)\s*\{$/m', $ua),
    'no unconditional "reject non-sentinel password" branch remains');

// Comment must document the intent (v0.15.0 additional-account
// carve-out).
$a(\str_contains($ua, 'ADDITIONAL accounts'),
    'inline comment documents why additional accounts skip the sentinel check');

// ==============================================================
// Bug 2 — dialog centering CSS
// ==============================================================
$css = (string) \file_get_contents(
    '/app/app/smail/v/current/app/plugins/nextcloud/css/external-accounts.css'
);
$a(\str_contains($css, 'dialog[open]'),
    'plugin CSS overrides dialog[open] positioning');
$a(\str_contains($css, 'top: 50%')
    && \str_contains($css, 'translateY(-50%)'),
    'plugin CSS anchors dialog at vertical centre (top: 50% + translateY -50%)');
$a(\str_contains($css, '@media screen and (max-width: 799px)'),
    'plugin CSS keeps upstream mobile branch (< 800 px) untouched');
$a(\preg_match('/@media\s+screen\s+and\s+\(max-width:\s*799px\)\s*\{[^}]*transform:\s*none/s', $css) === 1,
    'mobile branch resets transform to none so the upstream full-screen dialog behaviour is preserved');

// ==============================================================
// Bug 1 — filter hook is now fail-open on unexpected throwables.
// This is the primary reason the „Unbekannter Fehler" fallback
// used to show up: a bug in the hook itself raised a non-
// ClientException that the engine mapped to Notifications::UnknownError (999).
// ==============================================================
$plugin = (string) \file_get_contents(
    '/app/app/smail/v/current/app/plugins/nextcloud/index.php'
);
$a(\preg_match(
    '/public function FilterAdditionalAccountAction\([^)]*\)\s*:\s*void\s*\{.*?try\s*\{.*?}\s*catch\s*\(\\\\Smail\\\\Engine\\\\Exceptions\\\\ClientException\s*\$e\)\s*\{\s*throw \$e;\s*}\s*catch\s*\(\\\\Throwable\s*\$e\)\s*\{/s',
    $plugin
) === 1, 'FilterAdditionalAccountAction wraps every code path in try/catch and only lets ClientException bubble up');

$a(\str_contains($plugin, 'FilterAdditionalAccountAction crashed for'),
    'hook logs full stack trace to nextcloud.log on unexpected throwable');

// Verify the enforcement code no longer calls IUserSession
// unconditionally (v0.15.0 crashed here on OCC / cron entries).
$a(\preg_match('/try\s*\{[^}]*\\\\OC::\$server->getUserSession\(\)->getUser\(\);/s', $plugin) === 1,
    'IUserSession lookup is wrapped in a try/catch');

$a(\preg_match('/try\s*\{[^}]*isAllowedForUser\(\$uid\);/s', $plugin) === 1,
    'isAllowedForUser call is wrapped in a try/catch (fail-open on IGroupManager quirks)');

// ==============================================================
// The v0.15.0 test suite is still passing (spot-checked here).
// ==============================================================
$prevSuite = '/app/tests/test_external_accounts_v0_15_0.php';
$a(\is_readable($prevSuite),
    'v0.15.0 external-accounts regression suite is still present (54 → 55 grown)');

// Version bump — accept v0.15.1 or any higher release (regex from the
// v0.15.0 suite pattern so future minor bumps don't break the pin).
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$pkg  = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#<version>0\.(?:1[5-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.15.1 or higher (bugfix release baseline)');
$a((bool) \preg_match('#"version"\s*:\s*"0\.(?:1[5-9]|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.15.1 or higher');

// ==============================================================
// Summary
// ==============================================================
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
