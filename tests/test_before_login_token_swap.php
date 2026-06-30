<?php
/**
 * Regression test for the IMAP/SMTP/Sieve subrequest token bug
 * fixed in Souvera Mail 0.13.3.
 *
 * Symptom
 * -------
 * Background IMAP/SMTP/Sieve reconnects (dashboard widget refresh,
 * cron jobs, engine-token-cookie reconnects, Sieve-from-CLI) ran
 * without an active Nextcloud session. The engine plugin's
 * `beforeLogin` hook was gated on
 * `EngineHelper::isOIDCLogin()`, which itself required
 * `IUserSession::getUser() !== null`. With no session that returned
 * `false`, the sentinel `oidc_login|<uid>` was therefore sent
 * verbatim as the IMAP password — Stalwart rejected the connect
 * with `AUTHENTICATIONFAILED`.
 *
 * Fix
 * ---
 * 1. `EngineHelper::isOIDCEnabledServerSide()` — new session-free
 *    check (config flag + H2CK availability, no `IUserSession`).
 * 2. `EngineHelper::getOidcAccessTokenForUid(string $uid)` — new
 *    session-free token issuance: takes the uid as an explicit
 *    argument, dispatches `OidcProviderService::generateAccessToken($uid)`.
 * 3. Engine plugin `beforeLogin` parses the `<uid>` out of the
 *    sentinel and calls `getOidcAccessTokenForUid($uid)`. The
 *    `isOIDCLogin()` guard is removed — the sentinel is now the
 *    authoritative identity marker.
 *
 * This test pins down all three pieces so we never regress.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. EngineHelper static contract
// ---------------------------------------------------------------
$src = file_get_contents('/app/lib/Util/EngineHelper.php');

assertTrue(preg_match('#public function isOIDCEnabledServerSide\(\)\s*:\s*bool#', $src) === 1,
    "EngineHelper has session-free isOIDCEnabledServerSide(): bool", $passes, $failures);

assertTrue(preg_match('#public function getOidcAccessTokenForUid\(string \$uid\)\s*:\s*\?string#', $src) === 1,
    "EngineHelper has session-free getOidcAccessTokenForUid(string \$uid): ?string", $passes, $failures);

// isOIDCEnabledServerSide must NOT call userSession
$ssBlockStart = strpos($src, 'isOIDCEnabledServerSide(): bool');
$ssBlockEnd = strpos($src, "\n    }", $ssBlockStart);
$ssBlock = substr($src, $ssBlockStart, $ssBlockEnd - $ssBlockStart);
assertTrue(!str_contains($ssBlock, '$this->userSession'),
    "isOIDCEnabledServerSide() does NOT consult IUserSession (the bug fix)",
    $passes, $failures);
assertTrue(str_contains($ssBlock, "'souvera_mail', 'autologin-oidc'"),
    "isOIDCEnabledServerSide() still gates on the autologin-oidc app-config flag",
    $passes, $failures);
assertTrue(str_contains($ssBlock, '$this->oidcProvider->isProviderAvailable()'),
    "isOIDCEnabledServerSide() still gates on H2CK/oidc availability",
    $passes, $failures);

// getOidcAccessTokenForUid must NOT consult getSsoUid() — that's session-coupled
$tokenBlockStart = strpos($src, 'getOidcAccessTokenForUid(string $uid)');
$tokenBlockEnd = strpos($src, "\n    }", $tokenBlockStart);
$tokenBlock = substr($src, $tokenBlockStart, $tokenBlockEnd - $tokenBlockStart);
assertTrue(!str_contains($tokenBlock, 'getSsoUid'),
    "getOidcAccessTokenForUid() does NOT fall back to getSsoUid() (uses the explicit \$uid argument)",
    $passes, $failures);
assertTrue(!str_contains($tokenBlock, '$this->userSession'),
    "getOidcAccessTokenForUid() does NOT consult IUserSession (the bug fix)",
    $passes, $failures);
assertTrue(str_contains($tokenBlock, '$this->oidcProvider->generateAccessToken($uid)'),
    "getOidcAccessTokenForUid() dispatches OidcProviderService::generateAccessToken(\$uid) with the explicit arg",
    $passes, $failures);
assertTrue(str_contains($tokenBlock, 'isOIDCEnabledServerSide()'),
    "getOidcAccessTokenForUid() guards on isOIDCEnabledServerSide() (operator-controlled)",
    $passes, $failures);
assertTrue(str_contains($tokenBlock, "if (\$uid === '')"),
    "getOidcAccessTokenForUid() refuses an empty uid", $passes, $failures);

// isOIDCLogin() now layered on top of isOIDCEnabledServerSide() (no behaviour change for live-session callers)
$liBlockStart = strpos($src, 'isOIDCLogin(): bool');
$liBlockEnd = strpos($src, "\n    }", $liBlockStart);
$liBlock = substr($src, $liBlockStart, $liBlockEnd - $liBlockStart);
assertTrue(str_contains($liBlock, 'isOIDCEnabledServerSide()'),
    "isOIDCLogin() delegates to isOIDCEnabledServerSide() (no duplication)",
    $passes, $failures);
assertTrue(str_contains($liBlock, '$this->userSession->getUser() === null'),
    "isOIDCLogin() still rejects callers without an active NC user session (browser path)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. Engine plugin beforeLogin contract
// ---------------------------------------------------------------
$plugin = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
$blStart = strpos($plugin, 'public function beforeLogin(');
$blEnd = strpos($plugin, "\n\t}", $blStart);
$bl = substr($plugin, $blStart, $blEnd - $blStart);

assertTrue(!preg_match('#->\s*isOIDCLogin\(\s*\)#', $bl),
    "beforeLogin() does NOT actually CALL isOIDCLogin() any more — that was the session-bound trap",
    $passes, $failures);
assertTrue(str_contains($bl, "str_starts_with(\$oSettings->passphrase, 'oidc_login|')"),
    "beforeLogin() still matches the sentinel prefix 'oidc_login|'", $passes, $failures);
assertTrue(str_contains($bl, "\\substr(\$oSettings->passphrase, \\strlen('oidc_login|'))"),
    "beforeLogin() parses the <uid> out of the sentinel", $passes, $failures);
assertTrue(str_contains($bl, 'getOidcAccessTokenForUid'),
    "beforeLogin() calls the new session-free getOidcAccessTokenForUid()", $passes, $failures);
assertTrue(!str_contains($bl, 'getOidcAccessToken()'),
    "beforeLogin() does NOT call the legacy session-bound getOidcAccessToken() any more",
    $passes, $failures);

// Guards
assertTrue((bool)preg_match("#\\\$sUid\\s*===\\s*''#", $bl),
    "beforeLogin() bails out cleanly on empty <uid> (malformed sentinel)", $passes, $failures);
assertTrue(str_contains($bl, 'OAUTHBEARER'),
    "beforeLogin() still advertises OAUTHBEARER as the preferred SASL mechanism",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Behavioural sim — drive beforeLogin() with stubs
// ---------------------------------------------------------------
//
// We can NOT load the plugin file directly (it `extends
// \Smail\Engine\Plugins\AbstractPlugin` which pulls a huge engine
// graph). Instead, re-inline the body of beforeLogin() and drive
// it with stubs — same pattern as test_navigation_gate.php.
// Structural drift between the real source and this inline copy
// is caught by the regex assertions above.

class StubAccount {}
class StubMainAccount extends StubAccount {}
class StubConnectSettings {
    public string $passphrase = '';
    public array $SASLMechanisms = ['PLAIN', 'LOGIN'];
}
class StubEngineHelper {
    public array $calls = [];
    public ?string $tokenToReturn = null;
    public bool $serverSideEnabled = true;
    public function getOidcAccessTokenForUid(string $uid): ?string {
        $this->calls[] = ['getOidcAccessTokenForUid', $uid];
        if ($uid === '') return null;
        if (!$this->serverSideEnabled) return null;
        return $this->tokenToReturn;
    }
}

/**
 * Inlined beforeLogin body — drift-protected by the regex
 * assertions on the real source above.
 */
function simBeforeLogin(StubAccount $account, StubConnectSettings $settings, StubEngineHelper $helper): void {
    if (!($account instanceof StubMainAccount)) return;
    if (!\str_starts_with($settings->passphrase, 'oidc_login|')) return;
    $uid = \substr($settings->passphrase, \strlen('oidc_login|'));
    if ($uid === '') return;
    $token = $helper->getOidcAccessTokenForUid($uid);
    if (!$token) return;
    $settings->passphrase = $token;
    $settings->SASLMechanisms = \array_values(\array_unique(
        \array_merge(array('OAUTHBEARER'), $settings->SASLMechanisms)
    ));
}

// 3a. Happy path — sentinel + healthy H2CK -> token swap
$acc = new StubMainAccount(); $s = new StubConnectSettings();
$s->passphrase = 'oidc_login|alice';
$h = new StubEngineHelper(); $h->tokenToReturn = 'eyJ.fake.token';
simBeforeLogin($acc, $s, $h);
assertTrue($s->passphrase === 'eyJ.fake.token',
    "3a: sentinel swapped for live token", $passes, $failures);
assertTrue($s->SASLMechanisms[0] === 'OAUTHBEARER',
    "3a: OAUTHBEARER moved to front of SASL list", $passes, $failures);
assertTrue($h->calls === [['getOidcAccessTokenForUid', 'alice']],
    "3a: helper called once with extracted uid 'alice'", $passes, $failures);

// 3b. NO session, H2CK happy -> swap STILL succeeds (the actual bug fix)
//     (We don't simulate IUserSession at all; the new code path never
//     touches it. So this is identical to 3a — and that is the point.)
$h2 = new StubEngineHelper(); $h2->tokenToReturn = 'session-free-token';
$s2 = new StubConnectSettings(); $s2->passphrase = 'oidc_login|bob';
simBeforeLogin(new StubMainAccount(), $s2, $h2);
assertTrue($s2->passphrase === 'session-free-token',
    "3b: token swap works WITHOUT a Nextcloud session (the bug fix)",
    $passes, $failures);

// 3c. H2CK refuses (operator disabled autologin-oidc, JWT minting failed, etc.)
//     -> sentinel left in place, engine bubbles up its normal error
$h3 = new StubEngineHelper(); $h3->tokenToReturn = null; $h3->serverSideEnabled = false;
$s3 = new StubConnectSettings(); $s3->passphrase = 'oidc_login|carol';
simBeforeLogin(new StubMainAccount(), $s3, $h3);
assertTrue($s3->passphrase === 'oidc_login|carol',
    "3c: sentinel preserved when H2CK refuses (no silent password leak)",
    $passes, $failures);
assertTrue(!\in_array('OAUTHBEARER', $s3->SASLMechanisms, true),
    "3c: OAUTHBEARER NOT advertised when no token available", $passes, $failures);

// 3d. Non-OIDC account (regular password) -> nothing changes
$s4 = new StubConnectSettings(); $s4->passphrase = 'hunter2';
$h4 = new StubEngineHelper(); $h4->tokenToReturn = 'should-not-be-used';
simBeforeLogin(new StubMainAccount(), $s4, $h4);
assertTrue($s4->passphrase === 'hunter2',
    "3d: regular password accounts are NOT touched", $passes, $failures);
assertTrue($h4->calls === [],
    "3d: helper NOT called for non-sentinel passphrases", $passes, $failures);

// 3e. Non-MainAccount (additional account) -> nothing changes even with sentinel
$s5 = new StubConnectSettings(); $s5->passphrase = 'oidc_login|alice';
$h5 = new StubEngineHelper(); $h5->tokenToReturn = 'should-not-be-used';
simBeforeLogin(new StubAccount(), $s5, $h5);
assertTrue($s5->passphrase === 'oidc_login|alice',
    "3e: additional (non-Main) accounts are NOT touched", $passes, $failures);
assertTrue($h5->calls === [],
    "3e: helper NOT called for non-MainAccount instances", $passes, $failures);

// 3f. Malformed sentinel (`oidc_login|` with no uid)
$s6 = new StubConnectSettings(); $s6->passphrase = 'oidc_login|';
$h6 = new StubEngineHelper(); $h6->tokenToReturn = 'irrelevant';
simBeforeLogin(new StubMainAccount(), $s6, $h6);
assertTrue($s6->passphrase === 'oidc_login|',
    "3f: malformed sentinel (empty uid) is left untouched", $passes, $failures);
assertTrue($h6->calls === [],
    "3f: helper NOT called for malformed sentinel", $passes, $failures);

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
