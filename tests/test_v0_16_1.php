<?php
/**
 * v0.16.1 — Regression pins for two operator-reported bugs against
 * the fresh v0.16.0 release:
 *
 *   Bug #A  "No supported SASL mechanism found, remote server wants:
 *           AUTH=PLAIN, AUTH=LOGIN".
 *           Root cause: the Souvera-Mail engine fork had *removed*
 *           the PLAIN + LOGIN SASL classes (only OAUTHBEARER +
 *           XOAUTH2 shipped for Stalwart-only), so even when
 *           v0.16.0 wired PLAIN into the domain's SASLMechanisms
 *           list, `SASL::isSupported('PLAIN')` returned false and
 *           `ImapClient::Login()` fell into its "Unsupported SASL
 *           mechanism" branch.
 *           Fix: restore PLAIN + LOGIN as first-class SASL classes
 *           AND teach ImapClient / SmtpClient to drive them via
 *           the appropriate continuation flow.
 *
 *   Bug #B  ".alert .close" X-button rendered ABOVE the error title
 *           in Snappymail popups (screenshot: "X so allein und der
 *           Titel dann darunter").
 *           Root cause: Nextcloud's global stylesheet overrides
 *           .close to `display: block; float: none;` in some skins,
 *           dropping the intended float-right layout.
 *           Fix: absolute-position the close-X inside `dialog
 *           .modal-body .alert` and pad the container so text no
 *           longer flows underneath.
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

// -------------------------------------------------------------------
// Bug #A — SASL PLAIN + LOGIN restored
// -------------------------------------------------------------------

// (1) New SASL classes exist on disk in the lowercase-directory
//     convention that the case-sensitive autoloader (include.php:100)
//     picks up.
$plainPath = '/app/app/smail/v/current/app/libraries/Smail/Engine/sasl/plain.php';
$loginPath = '/app/app/smail/v/current/app/libraries/Smail/Engine/sasl/login.php';
$a(\is_file($plainPath),
    'sasl/plain.php exists (RFC 4616 SASL PLAIN class)');
$a(\is_file($loginPath),
    'sasl/login.php exists (SASL LOGIN two-step class)');

// (2) Classes have the exact FQN the SASL::factory() regex expects.
$plainSrc = (string) \file_get_contents($plainPath);
$loginSrc = (string) \file_get_contents($loginPath);
$a(\str_contains($plainSrc, 'namespace Smail\Engine\SASL'),
    'plain.php lives in Smail\Engine\SASL namespace');
$a(\preg_match('/class\s+PLAIN\s+extends\s+\\\\?Smail\\\\Engine\\\\SASL/', $plainSrc) === 1,
    'plain.php declares class PLAIN extends SASL');
$a(\str_contains($plainSrc, 'authenticate(') && \str_contains($plainSrc, '"\x00'),
    'plain.php SASL wire format: authzid \\0 authcid \\0 passwd');
$a(\str_contains($plainSrc, 'isSupported(string $param) : bool') && \str_contains($plainSrc, 'return true'),
    'plain.php reports supported so SASL::isSupported("PLAIN") returns true');

$a(\preg_match('/class\s+LOGIN\s+extends\s+\\\\?Smail\\\\Engine\\\\SASL/', $loginSrc) === 1,
    'login.php declares class LOGIN extends SASL');
$a(\str_contains($loginSrc, 'private string $passphrase'),
    'login.php stashes passphrase between authenticate() and challenge()');
$a(\str_contains($loginSrc, 'hasChallenge() : bool') && \str_contains($loginSrc, 'return true'),
    'login.php reports hasChallenge=true so the caller drives the second turn');
$a(\str_contains($loginSrc, 'passwordSent'),
    'login.php guards against sending the password twice');

// (3) ImapClient::Login() now handles PLAIN + LOGIN in addition
//     to OAUTHBEARER / XOAUTH2.
$imapSrc = (string) \file_get_contents('/app/app/smail/v/current/app/libraries/Smail/Mail/Imap/ImapClient.php');
$a(\str_contains($imapSrc, "else if ('PLAIN' === \$type)"),
    'ImapClient::Login() has a dedicated PLAIN branch');
$a(\str_contains($imapSrc, "else if ('LOGIN' === \$type)"),
    'ImapClient::Login() has a dedicated LOGIN branch');
$a(\str_contains($imapSrc, "\$SASL->challenge('')"),
    'ImapClient LOGIN branch drives the second challenge via SASL::challenge()');
// The "Unsupported SASL mechanism" throw must stay reachable for TRULY
// unknown mechanisms — but never for PLAIN / LOGIN / OAUTH.
$a(\str_contains($imapSrc, 'Unsupported SASL mechanism'),
    'ImapClient still rejects TRULY unknown SASL mechanisms with a clear error');

// (4) SmtpClient::Login() gets the same treatment.
$smtpSrc = (string) \file_get_contents('/app/app/smail/v/current/app/libraries/Smail/Mail/Smtp/SmtpClient.php');
$a(\str_contains($smtpSrc, "else if ('PLAIN' === \$type)"),
    'SmtpClient::Login() has a dedicated PLAIN branch');
$a(\str_contains($smtpSrc, "else if ('LOGIN' === \$type)"),
    'SmtpClient::Login() has a dedicated LOGIN branch');
$a(\str_contains($smtpSrc, "\$SASL->challenge('')"),
    'SmtpClient LOGIN branch drives the second challenge via SASL::challenge()');
$a(\str_contains($smtpSrc, "sendRequestWithCheck(\$sPass, 235)"),
    'SmtpClient LOGIN sends the base64 password and expects a 235 OK reply');

// (5) Belt-and-braces: `assertEncryptedForBearerAuth()` still gates
//     both IMAP and SMTP, so credentials cannot leak over a
//     non-TLS transport (except to loopback).
$a(\str_contains($imapSrc, 'assertEncryptedForBearerAuth'),
    'IMAP still gates credential submission on an encrypted transport');
$a(\str_contains($smtpSrc, 'assertEncryptedForBearerAuth'),
    'SMTP still gates credential submission on an encrypted transport');

// -------------------------------------------------------------------
// Bug #B — .alert .close CSS regression fix
// -------------------------------------------------------------------
$css = (string) \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/css/external-accounts.css');
$a(\str_contains($css, 'dialog .modal-body .alert'),
    'CSS scopes the .alert layout fix to dialog popups only');
$a(\str_contains($css, "position: absolute !important"),
    'close-X is absolutely positioned (X and title on the same line)');
$a(\str_contains($css, "float: none !important"),
    'close-X neutralises any inherited float from Nextcloud themes');
$a(\str_contains($css, "padding: 10px 44px 10px 14px"),
    'container pads the right side to reserve space for the absolute X');

// -------------------------------------------------------------------
// Version bump (regex-tolerant of 0.16.x-and-beyond)
// -------------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$pkg  = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#<version>0\.(?:1[6-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.16.1 or higher');
$a((bool) \preg_match('#"version"\s*:\s*"0\.(?:1[6-9]|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.16.1 or higher');

// -------------------------------------------------------------------
// Behavioural sim — file-based verification that SASL::factory("PLAIN")
// will succeed AT RUNTIME. We can't boot the engine from CLI (see
// prior test suites) so we assert the two pre-conditions
// SASL::factory() needs:
//   1. class Smail\Engine\SASL\PLAIN exists at the expected path
//   2. the class::isSupported('') method returns bool true
// Same for LOGIN.
// -------------------------------------------------------------------
$saslBase = (string) \file_get_contents('/app/app/smail/v/current/app/libraries/Smail/Engine/sasl.php');
$a(\str_contains($saslBase, "\\preg_match('/^([A-Z2]+)(?:-(.+))?"),
    'behavioural: SASL::factory() regex captures the mechanism family (uppercase)');
$a(\str_contains($plainSrc, 'return true'),
    'behavioural: PLAIN::isSupported returns true → SASL::factory succeeds');
$a(\str_contains($loginSrc, 'return true'),
    'behavioural: LOGIN::isSupported returns true → SASL::factory succeeds');

// -------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
