<?php
/**
 * Regression test for the OIDC token TTL bug fixed in Souvera Mail
 * 0.13.7.
 *
 * Symptom
 * -------
 * H2CK/oidc issues access tokens with a 15 min TTL. Souvera Mail
 * cached them in NC's distributed cache. On long-lived IMAP/SMTP
 * sessions, or on background reconnects, the cached entry was
 * returned even when the JWT's `exp` was already (or about to be)
 * in the past — different cache backends (Redis, APCu, Memcached,
 * NoLocal) honour TTLs with subtly different semantics and minor
 * clock drift between NC and Stalwart compounds the window. The
 * IMAP connect therefore presented a token Stalwart rejected with
 * `ExpiredSignature` → `AUTHENTICATIONFAILED`.
 *
 * Fix
 * ---
 * `OidcProviderService::generateAccessToken()` now treats the cache
 * TTL as a coarse hint and re-validates the cached JWT's `exp`
 * claim on every hit:
 *   - cached JWT with `exp - now >= 60 s`  → safe, return it
 *   - cached JWT with `exp - now <  60 s`  → evict, re-mint
 *   - opaque (non-JWT) cached token        → trust the cache TTL
 *
 * `extractCacheTtl()` no longer falls back to 60 s for a parsed JWT
 * with a near-expired `exp`. The fallback applies ONLY to opaque
 * tokens — for JWTs we either get a positive TTL (`exp - now - 60`)
 * or 0 (do not cache).
 *
 * This test pins the contract through static-source assertions
 * AND a behavioural simulation driven by a stub cache + stub
 * dispatcher.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Static source contract on OidcProviderService
// ---------------------------------------------------------------
$src = file_get_contents('/app/lib/Service/OidcProviderService.php');

assertTrue(preg_match('#private function isJwtStillSafe\(string \$jwt\)\s*:\s*bool#', $src) === 1,
    "OidcProviderService has isJwtStillSafe(string \$jwt): bool", $passes, $failures);
assertTrue(preg_match('#private function extractJwtExp\(string \$jwt\)\s*:\s*\?int#', $src) === 1,
    "OidcProviderService has extractJwtExp(string \$jwt): ?int", $passes, $failures);

// generateAccessToken must consult isJwtStillSafe on every cache hit
$gatStart = strpos($src, 'public function generateAccessToken(');
$gatEnd = strpos($src, "\n    }", $gatStart);
$gat = substr($src, $gatStart, $gatEnd - $gatStart);
assertTrue(str_contains($gat, '$this->isJwtStillSafe($cached)'),
    "generateAccessToken() re-validates the cached JWT's exp on every cache hit (the bug fix)",
    $passes, $failures);
assertTrue(str_contains($gat, '$this->cache->remove($cacheKey)'),
    "generateAccessToken() evicts the stale entry when the cached JWT is near-expired",
    $passes, $failures);
assertTrue(str_contains($gat, 'if ($ttl > 0)'),
    "generateAccessToken() refuses to cache a freshly minted JWT whose remaining lifetime is at/under the safety margin",
    $passes, $failures);

// extractCacheTtl must NOT use the 60 s fallback for a parsed JWT with bad exp
$tttStart = strpos($src, 'private function extractCacheTtl(');
$tttEnd = strpos($src, "\n    }", $tttStart);
$ttt = substr($src, $tttStart, $tttEnd - $tttStart);
assertTrue(str_contains($ttt, '$remaining > 0 ? $remaining : 0'),
    "extractCacheTtl() returns 0 (do-not-cache) for parsed JWTs with non-positive remaining lifetime",
    $passes, $failures);
// The opaque-token fallback is still reachable (via extractJwtExp returning null)
assertTrue(str_contains($ttt, 'self::FALLBACK_TTL_SECONDS'),
    "extractCacheTtl() still uses FALLBACK_TTL_SECONDS for opaque tokens (extractJwtExp returns null)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. Behavioural simulation
// ---------------------------------------------------------------
//
// We re-inline the new generateAccessToken() body in a tiny harness
// driven by stub cache + stub dispatcher. Drift between the real
// source and the inline body is caught by the regex assertions
// above (same pattern as test_before_login_token_swap.php).

class StubCache {
    public array $store = [];
    public array $calls = [];
    public function get(string $k): mixed {
        $this->calls[] = ['get', $k];
        if (!isset($this->store[$k])) return null;
        [$val, $expiresAt] = $this->store[$k];
        if ($expiresAt !== null && $expiresAt <= \time()) {
            // Most NC cache backends honour TTLs — simulate that here.
            unset($this->store[$k]);
            return null;
        }
        return $val;
    }
    public function set(string $k, mixed $v, int $ttl): void {
        $this->calls[] = ['set', $k, $ttl];
        $this->store[$k] = [$v, $ttl > 0 ? \time() + $ttl : null];
    }
    public function remove(string $k): void {
        $this->calls[] = ['remove', $k];
        unset($this->store[$k]);
    }
}

class StubDispatcher {
    public int $mintCount = 0;
    public ?string $nextToken = null;
    public function dispatch(): string {
        $this->mintCount++;
        return $this->nextToken ?? '';
    }
}

const SAFETY_MARGIN = 60;
const FALLBACK_TTL = 60;

function makeJwt(int $expDeltaSeconds): string {
    $header  = base64_url_enc(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64_url_enc(json_encode(['exp' => \time() + $expDeltaSeconds, 'sub' => 'alice']));
    return $header . '.' . $payload . '.sig-placeholder';
}
function base64_url_enc(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function extractJwtExp(string $jwt): ?int {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;
    $b64 = strtr($parts[1], '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    $decoded = base64_decode($b64, true);
    if ($decoded === false) return null;
    $p = json_decode($decoded, true);
    if (!is_array($p) || !isset($p['exp']) || !is_int($p['exp'])) return null;
    return $p['exp'];
}
function isJwtStillSafe(string $jwt): bool {
    $exp = extractJwtExp($jwt);
    if ($exp === null) return true;
    return ($exp - \time()) >= SAFETY_MARGIN;
}
function extractCacheTtl(string $jwt): int {
    $exp = extractJwtExp($jwt);
    if ($exp === null) return FALLBACK_TTL;
    $remaining = $exp - \time() - SAFETY_MARGIN;
    return $remaining > 0 ? $remaining : 0;
}

/** Mirrors generateAccessToken() — drift-protected by the regex assertions above. */
function simGenerate(string $userId, StubCache $cache, StubDispatcher $dispatcher): ?string {
    if ($userId === '') return null;
    $key = 'souvera_mail.oidc.token.souvera_mail.' . $userId;

    $cached = $cache->get($key);
    if (is_string($cached) && $cached !== '' && isJwtStillSafe($cached)) {
        return $cached;
    }
    if ($cached !== null && $cached !== false) {
        $cache->remove($key);
    }

    $token = $dispatcher->dispatch();
    if ($token === '') return null;

    $ttl = extractCacheTtl($token);
    if ($ttl > 0) {
        $cache->set($key, $token, $ttl);
    }
    return $token;
}

// 2a. Cold cache + fresh JWT (15 min) → mint once, cache with TTL = 840 s
$c = new StubCache(); $d = new StubDispatcher();
$freshJwt = makeJwt(900);
$d->nextToken = $freshJwt;
$tok1 = simGenerate('alice', $c, $d);
assertTrue($tok1 === $freshJwt, "2a: cold cache → fresh JWT returned", $passes, $failures);
assertTrue($d->mintCount === 1, "2a: dispatcher hit exactly once", $passes, $failures);
$setCalls = array_filter($c->calls, fn($x) => $x[0] === 'set');
$setCall = array_values($setCalls)[0] ?? null;
assertTrue($setCall !== null && $setCall[2] >= 838 && $setCall[2] <= 840,
    "2a: cache TTL is ~840 s (= 900 exp - 60 margin), got " . ($setCall[2] ?? 'null'),
    $passes, $failures);

// 2b. Warm cache hit while JWT is still safe → NO new mint, cached returned
$tok2 = simGenerate('alice', $c, $d);
assertTrue($tok2 === $freshJwt, "2b: warm cache → same JWT returned", $passes, $failures);
assertTrue($d->mintCount === 1, "2b: dispatcher NOT called again on warm hit", $passes, $failures);

// 2c. Cached JWT with `exp - now < 60 s` → evict + re-mint (the actual fix)
$c2 = new StubCache(); $d2 = new StubDispatcher();
$nearExpired = makeJwt(30);             // 30 s left — under the 60 s margin
$d2->nextToken = makeJwt(900);          // dispatcher will mint a fresh one
$key = 'souvera_mail.oidc.token.souvera_mail.bob';
$c2->store[$key] = [$nearExpired, \time() + 30];  // simulate a cache entry the backend hasn't evicted yet
$tok3 = simGenerate('bob', $c2, $d2);
assertTrue($tok3 === $d2->nextToken,
    "2c: near-expired cached JWT triggers re-mint (the bug fix)",
    $passes, $failures);
assertTrue($d2->mintCount === 1,
    "2c: dispatcher called exactly once when cache hit was unsafe",
    $passes, $failures);
$hasRemove = false;
foreach ($c2->calls as $call) {
    if ($call[0] === 'remove' && $call[1] === $key) { $hasRemove = true; break; }
}
assertTrue($hasRemove, "2c: stale entry was actively evicted from the cache",
    $passes, $failures);

// 2d. Cached JWT already past `exp` → evict + re-mint
$c3 = new StubCache(); $d3 = new StubDispatcher();
// Backend hasn't evicted yet (clock drift / lazy expiry / NoLocal cache).
// Force-insert past the natural Stub-cache expiry guard.
$expiredJwt = makeJwt(-5);
$d3->nextToken = makeJwt(900);
$c3->store['souvera_mail.oidc.token.souvera_mail.carol'] = [$expiredJwt, \time() + 60];
$tok4 = simGenerate('carol', $c3, $d3);
assertTrue($tok4 === $d3->nextToken,
    "2d: already-expired cached JWT triggers re-mint", $passes, $failures);
assertTrue($d3->mintCount === 1, "2d: dispatcher called exactly once", $passes, $failures);

// 2e. Fresh mint with already-near-expired exp → returned but NOT cached
$c4 = new StubCache(); $d4 = new StubDispatcher();
$d4->nextToken = makeJwt(30);   // upstream issued a near-expired token
$tok5 = simGenerate('dave', $c4, $d4);
assertTrue($tok5 === $d4->nextToken,
    "2e: caller still gets the near-expired token (better than nothing)",
    $passes, $failures);
$dotted = array_filter($c4->calls, fn($x) => $x[0] === 'set');
assertTrue(count($dotted) === 0,
    "2e: a near-expired freshly minted token is NOT persisted into the cache (don't outlive the JWT)",
    $passes, $failures);

// 2f. Opaque (non-JWT) token → cache with the fallback TTL, not 0
$c5 = new StubCache(); $d5 = new StubDispatcher();
$d5->nextToken = 'opaque-bearer-tok-abc123';   // pre-JWT H2CK default
$tok6 = simGenerate('eve', $c5, $d5);
assertTrue($tok6 === 'opaque-bearer-tok-abc123',
    "2f: opaque tokens are still issued (legacy H2CK)",
    $passes, $failures);
$setCalls = array_filter($c5->calls, fn($x) => $x[0] === 'set');
$setCall = array_values($setCalls)[0] ?? null;
assertTrue($setCall !== null && $setCall[2] === FALLBACK_TTL,
    "2f: opaque tokens use the 60 s fallback TTL", $passes, $failures);

// 2g. Empty uid → bails out without minting or touching the cache
$c6 = new StubCache(); $d6 = new StubDispatcher();
$tok7 = simGenerate('', $c6, $d6);
assertTrue($tok7 === null, "2g: empty uid returns null", $passes, $failures);
assertTrue($d6->mintCount === 0, "2g: dispatcher NOT called for empty uid",
    $passes, $failures);
assertTrue($c6->calls === [], "2g: cache NOT touched for empty uid",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. invalidate() still wired correctly (sanity check; the existing
//    LogoutListener integration was not touched by the fix)
// ---------------------------------------------------------------
assertTrue(preg_match('#public function invalidate\(\?string \$userId = null\)\s*:\s*void#', $src) === 1,
    "OidcProviderService::invalidate() still exposes the per-user / global signature",
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
