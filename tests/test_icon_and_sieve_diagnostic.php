<?php
/**
 * Regression test for the icon refresh + Sieve "Error 352" diagnostic
 * improvements shipped in Souvera Mail 0.13.12.
 *
 * Context (2026-07-01)
 * --------------------
 * After the 0.13.8 fix flipped Sieve from "ERROR 1" (= Sieve not
 * enabled in the engine domain config) to a real ManageSieve connect
 * attempt, the operator now hits "Error 352" — engine's
 * `Notifications::CantGetFilters`, thrown when the ManageSieve
 * connect-or-login step itself fails. The actual error path inside
 * Stalwart varies (CAPABILITY mismatch, SSL mode mismatch, no
 * OAUTHBEARER mechanism advertised on the ManageSieve listener, …)
 * and only the operator can read Stalwart's log.
 *
 * This release ships three operator-facing improvements:
 *
 *   1. A clean monochrome SVG nav icon (replaces the ugly rasterised
 *      `logo-white-64x64.png`). Nextcloud can recolour it for the
 *      active theme.
 *   2. `--sieve-ssl` default flipped `ssl` → `starttls` — matches the
 *      IANA RFC 5804 port-4190 contract and Stalwart's out-of-the-box
 *      ManageSieve listener.
 *   3. `occ souvera_mail:status` now surfaces the full Sieve config
 *      (host/port/ssl/sasl) so the operator can correlate "Error 352"
 *      with the exact triple the engine is dialling.
 *
 * These changes do NOT fix every possible source of Error 352 — that
 * depends on Stalwart-side config the operator owns — but they make
 * the next debugging round actionable.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Nav icon file
// ---------------------------------------------------------------
// NOTE: 0.13.22 — the operator swapped the previous monochrome
// theme-friendly SVG for a branded logo hosted at host-on.dev.
// We no longer enforce viewBox="0 0 24 24" / `currentColor` /
// zero hex colours — those constraints reflected the older
// nav-icon design goal. We DO still enforce that the file
// exists, is valid XML, and is wired via Application.php.
$iconPath = '/app/img/app.svg';
assertTrue(\file_exists($iconPath),
    "img/app.svg exists (the Nextcloud nav icon)", $passes, $failures);
$icon = \file_get_contents($iconPath);

// SVG must validate as XML so NC's IconService doesn't choke
$prev = \libxml_use_internal_errors(true);
$xml = @\simplexml_load_string($icon);
\libxml_clear_errors();
\libxml_use_internal_errors($prev);
assertTrue($xml !== false,
    "app.svg is well-formed XML — NC's image route can serve it as-is",
    $passes, $failures);

// Sanity: root element is <svg> with a viewBox (any dimensions)
assertTrue((bool) \preg_match('#<svg\b[^>]*\bviewBox\s*=\s*"[^"]+"#s', $icon),
    "app.svg root element is <svg> with a viewBox attribute (NC IconService can scale it)",
    $passes, $failures);

// Application.php wires the icon path
$app = \file_get_contents('/app/lib/AppInfo/Application.php');
assertTrue((bool) \preg_match("#imagePath\(self::APP_ID, 'app\.svg'\)#", $app),
    "Application.php points the nav icon at img/app.svg",
    $passes, $failures);
assertTrue(!\str_contains($app, "'logo-white-64x64.png'"),
    "Application.php no longer references the rasterised logo-white-64x64.png",
    $passes, $failures);

// The img/ folder holds two canonical files: app.svg (menu, white) +
// app-widget.svg (dashboard widget, black). v0.14.27 split them so the
// two rendering pipelines can never trip over each other again.
$imgFolder = '/app/img';
$imgFiles = \array_values(\array_diff(\scandir($imgFolder), ['.', '..']));
\sort($imgFiles);
assertTrue($imgFiles === ['app-widget.svg', 'app.svg'],
    "img/ folder contains exactly app.svg + app-widget.svg (got: " . \implode(',', $imgFiles) . ")",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. Setup --sieve-ssl default flipped to starttls
// ---------------------------------------------------------------
$setup = \file_get_contents('/app/lib/Command/Setup.php');

// VALUE_REQUIRED with the new 'starttls' default (Symfony Console's
// default is the last argument)
assertTrue((bool) \preg_match(
    "#addOption\('sieve-ssl',\s*null,\s*InputOption::VALUE_REQUIRED,[^,]+,\s*'starttls'\s*\)#",
    $setup
), "Setup.php --sieve-ssl defaults to 'starttls' (IANA port-4190 standard, matches Stalwart's default ManageSieve listener)",
    $passes, $failures);

assertTrue(!\preg_match(
    "#addOption\('sieve-ssl',\s*null,\s*InputOption::VALUE_REQUIRED,[^,]+,\s*'ssl'\s*\)#",
    $setup
), "Setup.php --sieve-ssl is NOT 'ssl' any more (the previous default that caused Error 352 on default Stalwart deploys)",
    $passes, $failures);

// Help text mentions Stalwart + the rationale
assertTrue(\str_contains($setup, 'IANA') && \str_contains($setup, 'Stalwart'),
    "Setup.php --sieve-ssl help text explains the choice (IANA + Stalwart)",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Status command surfaces the full Sieve config
// ---------------------------------------------------------------
$status = \file_get_contents('/app/lib/Command/Status.php');

// New `sieve` block with host/port/ssl/sasl alongside the legacy
// `sieve_enabled` field
foreach (['sieve', 'host', 'port', 'ssl', 'sasl'] as $key) {
    assertTrue((bool) \preg_match("#'$key'\s*=>#", $status),
        "Status::domainReport() emits the Sieve `$key` field",
        $passes, $failures);
}
assertTrue(\str_contains($status, "'sieve_enabled'"),
    "Status::domainReport() keeps the legacy `sieve_enabled` boolean for downstream automation that grepped it",
    $passes, $failures);
assertTrue(\str_contains($status, 'Error 352') || \str_contains($status, 'CantGetFilters'),
    "Status.php documents the Error 352 / CantGetFilters correlation in a comment",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. CHANGELOG + version bump
// ---------------------------------------------------------------
$changelog = \file_get_contents('/app/CHANGELOG.md');
assertTrue(\str_contains($changelog, '[0.13.12]'),
    "CHANGELOG.md contains a [0.13.12] entry", $passes, $failures);
assertTrue(\str_contains($changelog, 'Error 352') || \str_contains($changelog, 'CantGetFilters'),
    "CHANGELOG.md 0.13.12 mentions Error 352 / CantGetFilters",
    $passes, $failures);
assertTrue(\str_contains($changelog, 'icon') || \str_contains($changelog, 'Icon') || \str_contains($changelog, 'SVG'),
    "CHANGELOG.md 0.13.12 mentions the icon refresh",
    $passes, $failures);

$info = \file_get_contents('/app/appinfo/info.xml');
\preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(\version_compare($vm[1] ?? '0.0.0', '0.13.12', '>='),
    "info.xml <version> >= 0.13.12 (got: '" . ($vm[1] ?? '') . "')",
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
