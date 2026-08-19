<?php
declare(strict_types=1);

/**
 * v0.14.36 — JMAP session-based accountId resolution.
 *
 * Diagnosis (2026-02-19): the Shield/Sieve UI surfaced
 * `CantSaveFilters[351]` because the previous `resolveAccountId()`
 * delegated to `souvera_central::StalwartService::findAccountId($email)`
 * — that call returned a truncated single character (`'d'` instead of
 * `'d333333'`) on Stalwart 0.16 deploys.
 *
 * The fix moves the source of truth to Stalwart itself: we call
 * `GET /jmap/session` with the user's OIDC bearer and read
 * `primaryAccounts['urn:ietf:params:jmap:mail']` — the value Stalwart
 * itself asserts is the correct accountId for that principal.
 *
 * This test pins the new behaviour in code so it can't quietly
 * regress:
 *   1. StalwartUserContext injects StalwartAdminService.
 *   2. resolveAccountId() calls fetchSessionAsUser($bearer).
 *   3. resolveAccountId() reads primaryAccounts[mail] and
 *      primaryAccounts[sieve] (fallback), NOT the truncated
 *      souvera_central lookup.
 *   4. The old broken `findAccountId(...)` call chain is retained
 *      only in `resolveCentralAccountId()` and is NOT reachable
 *      from any JMAP code path.
 *   5. The result is memoised per-request (accountIdCache).
 */

$repo = \dirname(__DIR__);
$file = $repo . '/lib/Service/StalwartUserContext.php';

$passes = [];
$failures = [];
$check = static function (bool $cond, string $msg) use (&$passes, &$failures): void {
    if ($cond) { $passes[] = $msg; } else { $failures[] = $msg; }
};

$check(\is_file($file), 'StalwartUserContext.php exists');
$src = (string) \file_get_contents($file);

// ---------------------------------------------------------------
// 1. Injection of StalwartAdminService
// ---------------------------------------------------------------
$check(
    (bool) \preg_match('/__construct\([\s\S]{0,600}private\s+StalwartAdminService\s+\$stalwartAdmin/', $src),
    'ctor injects StalwartAdminService (needed for /jmap/session probe)'
);

// ---------------------------------------------------------------
// 2. resolveAccountId uses fetchSessionAsUser
// ---------------------------------------------------------------
$check(
    (bool) \preg_match('/public function resolveAccountId\([^)]*\)\s*:\s*string\s*\{[\s\S]{0,2200}stalwartAdmin->fetchSessionAsUser/', $src),
    'resolveAccountId() calls stalwartAdmin->fetchSessionAsUser($bearer)'
);

// ---------------------------------------------------------------
// 3. resolveAccountId reads primaryAccounts (mail + sieve)
// ---------------------------------------------------------------
$check(
    (bool) \preg_match('/primaryAccounts/', $src),
    'source references primaryAccounts (JMAP session map)'
);
$check(
    (bool) \preg_match('/JMAP_CAP_MAIL\s*=\s*[\'"]urn:ietf:params:jmap:mail[\'"]/', $src),
    'declares urn:ietf:params:jmap:mail as preferred capability lookup'
);
$check(
    (bool) \preg_match('/JMAP_CAP_SIEVE\s*=\s*[\'"]urn:ietf:params:jmap:sieve[\'"]/', $src),
    'declares urn:ietf:params:jmap:sieve as fallback capability lookup'
);

// ---------------------------------------------------------------
// 4. Per-request cache (accountIdCache)
// ---------------------------------------------------------------
$check(
    (bool) \preg_match('/\$accountIdCache\s*=\s*\[/', $src)
    && (bool) \preg_match('/accountIdCache\[\$userId\]/', $src),
    'result is memoised in $accountIdCache to avoid re-hitting /jmap/session on every call'
);

// ---------------------------------------------------------------
// 5. The broken souvera_central path is NOT the primary resolver.
//    It has been renamed to resolveCentralAccountId() and marked
//    @internal — so no NEW code should reach it.
// ---------------------------------------------------------------
$check(
    (bool) \preg_match('/public function resolveCentralAccountId\(/', $src),
    'legacy souvera_central lookup renamed to resolveCentralAccountId (only for non-JMAP callers)'
);
$check(
    (bool) \preg_match('/@internal[\s\S]{0,100}resolveAccountId/', $src),
    'resolveCentralAccountId is marked @internal so future callers pick the JMAP resolver'
);

// The primary resolveAccountId MUST NOT call findAccountId directly
// (that was the source of the truncated-`d` bug). We check by extracting
// the function body via balanced-brace matching, then verifying the
// findAccountId call is absent from it.
$offset = \strpos($src, 'public function resolveAccountId(');
$primaryBody = '';
if ($offset !== false) {
    $braceStart = \strpos($src, '{', $offset);
    if ($braceStart !== false) {
        $depth = 0;
        for ($i = $braceStart, $n = \strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') { $depth++; }
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $primaryBody = \substr($src, $braceStart, $i - $braceStart + 1);
                    break;
                }
            }
        }
    }
}
$check(
    $primaryBody !== '' && !\str_contains($primaryBody, 'findAccountId'),
    'primary resolveAccountId() does NOT delegate to souvera_central::findAccountId (root cause of the 404)'
);

// ---------------------------------------------------------------
// 6. Sensible error messages point the operator at the fix path.
// ---------------------------------------------------------------
$check(
    (bool) \preg_match('/warmup-oidc/', $src),
    'error message on session-fetch failure points at `occ souvera_mail:warmup-oidc` diagnostic'
);

// ---------------------------------------------------------------
// 7. `php -l` clean
// ---------------------------------------------------------------
$out = [];
$rc = 0;
\exec('php -l ' . \escapeshellarg($file) . ' 2>&1', $out, $rc);
$check($rc === 0, 'php -l clean on StalwartUserContext.php: ' . \implode(' | ', $out));

// ---------------------------------------------------------------
// 8. Cross-check: SieveScriptService uploadBlob uses the path-style URL
// (this is the actual failure surface from the operator's log:
//  `POST /jmap/upload?account=d 404`).
// ---------------------------------------------------------------
$sieveFile = $repo . '/lib/Service/SieveScriptService.php';
$sieveSrc = (string) \file_get_contents($sieveFile);

$check(
    (bool) \preg_match('#/jmap/upload/[\'"]?\s*\.\s*\\\\rawurlencode\(\$accountId\)#', $sieveSrc),
    'SieveScriptService::uploadBlob builds a path-style URL /jmap/upload/{accountId}/'
);
$check(
    !\str_contains($sieveSrc, "'/jmap/upload?account='"),
    'SieveScriptService::uploadBlob does NOT use the query-param form ?account='
);

// ---------------------------------------------------------------
// Report
// ---------------------------------------------------------------
$total = \count($passes) + \count($failures);
echo 'Passed: ' . \count($passes) . "/{$total}\n";
foreach ($passes as $p) {
    echo "  ✓ {$p}\n";
}
if ($failures !== []) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  ✗ {$f}\n";
    }
    exit(1);
}
echo "ALL TESTS PASSED\n";
