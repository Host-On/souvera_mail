<?php
/**
 * Static verification that /app/docs/configs/*.md is aligned with the
 * current architecture (Souvera Mail 0.13.0 / H2CK-oidc-as-OP /
 * `occ souvera_mail:*` CLI).
 *
 * Catches doc drift: previous iterations of the docs referenced the
 * old `smail` app id, `occ smail:setup`, and Keycloak-as-primary-IdP.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$docs = [
    '/app/docs/configs/stalwart-oauthbearer.md',
    '/app/docs/configs/dovecot-postfix-oauthbearer.md',
    '/app/docs/configs/keycloak.md',
];
foreach ($docs as $d) {
    assertTrue(file_exists($d), "$d exists", $passes, $failures);
}

$stalwart = file_get_contents('/app/docs/configs/stalwart-oauthbearer.md');
$dovecot  = file_get_contents('/app/docs/configs/dovecot-postfix-oauthbearer.md');
$kc       = file_get_contents('/app/docs/configs/keycloak.md');

// --- stalwart-oauthbearer.md ---
assertTrue(str_contains($stalwart, 'occ souvera_mail:bootstrap'),
    "stalwart doc uses 'occ souvera_mail:bootstrap'", $passes, $failures);
assertTrue(!preg_match('#\boccc?\s+smail:#', $stalwart),
    "stalwart doc has NO 'occ smail:*' commands", $passes, $failures);
assertTrue(!preg_match('#\boccc?\s+x2mail:#', $stalwart),
    "stalwart doc has NO 'occ x2mail:*' commands", $passes, $failures);
assertTrue(str_contains($stalwart, 'H2CK/oidc'),
    "stalwart doc mentions H2CK/oidc", $passes, $failures);
assertTrue(str_contains($stalwart, 'index.php/apps/oidc/jwks'),
    "stalwart doc mentions JWKS endpoint", $passes, $failures);
assertTrue(str_contains($stalwart, 'souvera-users'),
    "stalwart doc mentions souvera-users group restriction", $passes, $failures);
assertTrue(str_contains($stalwart, 'Stalwart 0.16'),
    "stalwart doc targets Stalwart 0.16+", $passes, $failures);

// --- dovecot-postfix-oauthbearer.md ---
assertTrue(str_contains($dovecot, 'occ souvera_mail:bootstrap'),
    "dovecot doc uses 'occ souvera_mail:bootstrap'", $passes, $failures);
assertTrue(!preg_match('#\boccc?\s+smail:#', $dovecot),
    "dovecot doc has NO 'occ smail:*' commands", $passes, $failures);
assertTrue(str_contains($dovecot, 'H2CK/oidc'),
    "dovecot doc mentions H2CK/oidc", $passes, $failures);
assertTrue(str_contains($dovecot, 'oauth2_jwks') || str_contains($dovecot, 'JWKS'),
    "dovecot doc explains JWKS-based local validation", $passes, $failures);
assertTrue(str_contains($dovecot, 'souvera-users'),
    "dovecot doc mentions souvera-users group restriction", $passes, $failures);

// --- keycloak.md should be marked advanced/legacy ---
assertTrue(stripos($kc, 'advanced') !== false || stripos($kc, 'legacy') !== false,
    "keycloak.md is marked as advanced/legacy", $passes, $failures);
assertTrue(stripos($kc, 'rarely needed') !== false || stripos($kc, 'Only read this page if you must') !== false || stripos($kc, 'External IdP') !== false,
    "keycloak.md is framed as external-IdP appendix, not primary path", $passes, $failures);
assertTrue(!preg_match('#\boccc?\s+smail:#', $kc),
    "keycloak.md has NO 'occ smail:*' commands", $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
