<?php
/**
 * Regression test for the `--imap-allow-self-signed` family added to
 * `souvera_mail:setup` after the live operator incident on 2026-06-30.
 *
 * Live trace (PRD Step 27):
 *   Dashboard widget + webmail login surfaced
 *   `Smail\Mail\Net\Exceptions\SocketReadException` on IMAP connect.
 *
 * Root cause: the operator's load balancer (`a.lb.oncloud.zone`,
 * 5.180.194.200) terminates TLS on port 993 but does NOT TCP-forward
 * IMAP traffic to the Stalwart container. The IMAP banner is therefore
 * never delivered, the socket hangs, and Snappymail surfaces the
 * generic socket-read error.
 *
 * The fix path proven live:
 *   1. Point the IMAP/SMTP/Sieve host directly at the cluster-internal
 *      Stalwart IP (`10.0.0.10`).
 *   2. Stalwart's cert binds CN=mail.example.com; connecting via IP
 *      fails strict cert-name verification. Relax verify_peer +
 *      verify_peer_name + allow_self_signed for the affected protocols.
 *
 * Without (1) the LB silently swallows IMAP — no workaround possible.
 * Without (2) Snappymail rejects the cert during TLS handshake.
 *
 * This test covers the SECOND half of that fix: the new
 * `--imap-allow-self-signed`, `--smtp-allow-self-signed`,
 * `--sieve-allow-self-signed`, and `--allow-self-signed` (shortcut)
 * options on `souvera_mail:setup`, and the per-protocol SSL config
 * branching they trigger in `DomainConfigService::buildDomainConfig()`.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Setup.php source — declares the four new options
// ---------------------------------------------------------------
$setupSrc = (string) file_get_contents('/app/lib/Command/Setup.php');

foreach ([
    'imap-allow-self-signed' => 'IMAP TLS relax flag',
    'smtp-allow-self-signed' => 'SMTP TLS relax flag',
    'sieve-allow-self-signed' => 'Sieve TLS relax flag',
    'allow-self-signed' => 'global shortcut',
] as $opt => $purpose) {
    assertTrue(str_contains($setupSrc, "'{$opt}'"),
        "Setup.php declares --{$opt} ({$purpose})", $passes, $failures);
}

assertTrue(str_contains($setupSrc, '$globalSelfSigned = (bool) $input->getOption(\'allow-self-signed\')')
        && str_contains($setupSrc, '$imapAllowSelfSigned = $globalSelfSigned || (bool) $input->getOption(\'imap-allow-self-signed\')'),
    "Setup.php OR-merges the global shortcut with per-protocol flags",
    $passes, $failures);

assertTrue(str_contains($setupSrc, '$imapAllowSelfSigned,')
        && str_contains($setupSrc, '$smtpAllowSelfSigned,')
        && str_contains($setupSrc, '$sieveAllowSelfSigned,'),
    "Setup.php passes the three flags into buildDomainConfig()",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. DomainConfigService::sslConfig accepts $allowSelfSigned param
// ---------------------------------------------------------------
$dcsSrc = (string) file_get_contents('/app/lib/Service/DomainConfigService.php');

assertTrue(str_contains($dcsSrc, 'private function sslConfig(bool $allowSelfSigned = false): array'),
    "sslConfig() signature accepts \$allowSelfSigned (default false = backwards compatible)",
    $passes, $failures);

assertTrue(str_contains($dcsSrc, "'verify_peer' => !\$allowSelfSigned")
        && str_contains($dcsSrc, "'verify_peer_name' => !\$allowSelfSigned")
        && str_contains($dcsSrc, "'allow_self_signed' => \$allowSelfSigned"),
    "sslConfig() defaults flip verify_peer/verify_peer_name/allow_self_signed when allowSelfSigned=true",
    $passes, $failures);

assertTrue(str_contains($dcsSrc, "'verify_peer' => \$allowSelfSigned ? false : \$context->verify_peer")
        && str_contains($dcsSrc, "'verify_peer_name' => \$allowSelfSigned ? false : \$context->verify_peer_name")
        && str_contains($dcsSrc, "'allow_self_signed' => \$allowSelfSigned || \$context->allow_self_signed"),
    "sslConfig() override branch (when engine SSLContext is reachable) still respects allowSelfSigned",
    $passes, $failures);

// buildDomainConfig now accepts 13 params (10 prev + 3 new flags), backwards-compatible defaults.
assertTrue(str_contains($dcsSrc, 'bool $imapAllowSelfSigned = false,')
        && str_contains($dcsSrc, 'bool $smtpAllowSelfSigned = false,')
        && str_contains($dcsSrc, 'bool $sieveAllowSelfSigned = false,'),
    "buildDomainConfig() accepts three new flags with default false (backwards-compatible)",
    $passes, $failures);

assertTrue(str_contains($dcsSrc, '$this->sslConfig($imapAllowSelfSigned)')
        && str_contains($dcsSrc, '$this->sslConfig($smtpAllowSelfSigned)')
        && str_contains($dcsSrc, '$this->sslConfig($sieveAllowSelfSigned)'),
    "buildDomainConfig() routes per-protocol flags into sslConfig()",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Behavioural: build a config WITHOUT engine loaded (no SSLContext)
//    and verify the JSON shape per protocol.
// ---------------------------------------------------------------
$sim = <<<'PHP'
<?php
declare(strict_types=1);

namespace OCP {
    if (!interface_exists('OCP\\IConfig')) {
        interface IConfig {}
    }
}

// Suppress engine boot — sslConfig() must fall through to the defaults branch.
namespace OCA\SouveraMail\Service {
    require_once '/app/lib/Service/DomainConfigService.php';

    final class StubConfig implements \OCP\IConfig {}

    $svc = new DomainConfigService(new StubConfig(), null);

    // Build A: backwards-compatible (no flags) — must produce verify_peer=true.
    $a = $svc->buildDomainConfig(
        'mail.example.com', 993, 'ssl',
        'mail.example.com', 465, 'ssl',
        'mail.example.com', 4190, 'starttls',
        true,
    );
    $ok = $a['IMAP']['ssl']['verify_peer'] === true
       && $a['IMAP']['ssl']['verify_peer_name'] === true
       && $a['IMAP']['ssl']['allow_self_signed'] === false
       && $a['SMTP']['ssl']['verify_peer'] === true
       && $a['Sieve']['ssl']['verify_peer'] === true;
    if (!$ok) { fwrite(STDERR, "FAIL backwards-compat: " . json_encode($a, JSON_PRETTY_PRINT) . "\n"); exit(1); }

    // Build B: ALL self-signed — every protocol flips.
    $b = $svc->buildDomainConfig(
        '10.0.0.10', 993, 'ssl',
        '10.0.0.10', 465, 'ssl',
        '10.0.0.10', 4190, 'starttls',
        true,
        true, true, true,
    );
    foreach (['IMAP', 'SMTP', 'Sieve'] as $p) {
        if ($b[$p]['ssl']['verify_peer'] !== false
         || $b[$p]['ssl']['verify_peer_name'] !== false
         || $b[$p]['ssl']['allow_self_signed'] !== true) {
            fwrite(STDERR, "FAIL all-relaxed for $p: " . json_encode($b[$p]['ssl']) . "\n");
            exit(2);
        }
        if ($b[$p]['host'] !== '10.0.0.10') {
            fwrite(STDERR, "FAIL host not 10.0.0.10 for $p\n");
            exit(3);
        }
    }

    // Build C: ONLY IMAP relaxed — SMTP+Sieve must stay strict.
    $c = $svc->buildDomainConfig(
        '10.0.0.10', 993, 'ssl',
        'mail.example.com', 465, 'ssl',
        'mail.example.com', 4190, 'starttls',
        true,
        true, false, false,
    );
    if ($c['IMAP']['ssl']['verify_peer'] !== false) { fwrite(STDERR, "FAIL IMAP not relaxed\n"); exit(4); }
    if ($c['SMTP']['ssl']['verify_peer'] !== true)  { fwrite(STDERR, "FAIL SMTP unexpectedly relaxed\n"); exit(5); }
    if ($c['Sieve']['ssl']['verify_peer'] !== true) { fwrite(STDERR, "FAIL Sieve unexpectedly relaxed\n"); exit(6); }

    // Build D: ONLY Sieve relaxed (e.g. self-hosted ManageSieve, public IMAP).
    $d = $svc->buildDomainConfig(
        'mail.example.com', 993, 'ssl',
        'mail.example.com', 465, 'ssl',
        '10.10.10.10', 4190, 'starttls',
        true,
        false, false, true,
    );
    if ($d['IMAP']['ssl']['verify_peer'] !== true)   { fwrite(STDERR, "FAIL D IMAP\n"); exit(7); }
    if ($d['Sieve']['ssl']['verify_peer'] !== false) { fwrite(STDERR, "FAIL D Sieve\n"); exit(8); }
    if ($d['Sieve']['ssl']['allow_self_signed'] !== true) { fwrite(STDERR, "FAIL D Sieve allow_self_signed\n"); exit(9); }

    // SASL stays OAUTHBEARER/XOAUTH2 even with relaxed TLS — auth is NOT weakened.
    foreach (['IMAP', 'SMTP', 'Sieve'] as $p) {
        $sasl = $b[$p]['sasl'];
        if (!in_array('OAUTHBEARER', $sasl, true) || !in_array('XOAUTH2', $sasl, true)) {
            fwrite(STDERR, "FAIL $p sasl regressed: " . json_encode($sasl) . "\n");
            exit(10);
        }
    }

    echo "ALL OK\n";
}
PHP;
file_put_contents('/tmp/self_signed_sim.php', $sim);
$out = (string) shell_exec('php /tmp/self_signed_sim.php 2>&1');
assertTrue(str_contains($out, 'ALL OK'),
    "Behavioural sim — backwards-compat, all-relaxed, IMAP-only, Sieve-only, SASL preserved (output: " . trim($out) . ")",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. CHANGELOG documents the new options (so operators discover them)
// ---------------------------------------------------------------
$changelog = (string) file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($changelog, '--imap-allow-self-signed')
        || str_contains($changelog, '--allow-self-signed'),
    "CHANGELOG.md documents the new --*-allow-self-signed setup flags",
    $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
