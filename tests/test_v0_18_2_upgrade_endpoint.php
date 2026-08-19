<?php
/**
 * v0.18.2 — Regression pin for the /app-passwords/upgrade endpoint
 * (Post-Login upgrade path) and the CLIENT_UPGRADE_PATTERN.txt agent
 * playbook.
 *
 * ==============================================================
 * Feature under test
 * ==============================================================
 *
 * Client team already built the "X (NC-only from /login/v2) → Y
 * (paired mail+DAV) swap" via TWO calls: POST /login-flow with X,
 * then optional DELETE. Operator confirmed the pattern is right but
 * asked us to
 *   a) provide an ATOMIC endpoint so X is guaranteed cleaned up
 *      inside a single request/response cycle,
 *   b) supply the exact client-agent playbook so iOS + Desktop
 *      implement the same pattern.
 *
 * Deliverables in this test:
 *   1. LoginFlowController::upgrade() + POST /app-passwords/upgrade
 *      route, atomic Y-create + X-invalidate.
 *   2. AppPasswordService::revokeByRawSecret() helper (best-effort,
 *      log-and-swallow, single ITokenProvider::invalidateToken() call).
 *   3. docs/CLIENT_UPGRADE_PATTERN.txt (client-agent guide with
 *      Kotlin/Swift/Rust examples, atomicity contract, cleanup
 *      fallback for the invalidated=false edge case).
 *   4. docs/LOGIN_FLOW_CLIENT_INTEGRATION.txt cross-linked to
 *      CLIENT_UPGRADE_PATTERN.txt so implementers find the
 *      optimized path from the general doc.
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

$ctrl = (string) \file_get_contents('/app/lib/Controller/LoginFlowController.php');
$svc = (string) \file_get_contents('/app/lib/Service/AppPasswordService.php');
$routes = (string) \file_get_contents('/app/appinfo/routes.php');

// -------------------------------------------------------------------
// 1. Controller: `upgrade()` method exists with the right attributes.
// -------------------------------------------------------------------
$a((bool) \preg_match('#public\s+function\s+upgrade\s*\(#', $ctrl),
    'LoginFlowController::upgrade() method exists');

// PHP attributes must be on the upgrade method (same three as create).
// We locate the method start and slice the ~200 chars BEFORE it (the
// attribute stack lives immediately above `public function upgrade`).
$upgradeOffset = \strpos($ctrl, 'public function upgrade(');
$attrBlock = '';
if ($upgradeOffset !== false) {
    $lookBack = 700;
    $attrStart = \max(0, $upgradeOffset - $lookBack);
    $attrBlock = \substr($ctrl, $attrStart, $upgradeOffset - $attrStart);
}
$a($upgradeOffset !== false, 'located upgrade() method offset');
$a(\str_contains($attrBlock, '#[NoAdminRequired]'),
    'upgrade() has #[NoAdminRequired]');
$a(\str_contains($attrBlock, '#[NoCSRFRequired]'),
    'upgrade() has #[NoCSRFRequired]');
$a(\str_contains($attrBlock, "#[BruteForceProtection(action: 'souvera_mail_login_flow')]"),
    'upgrade() shares the login_flow brute-force counter (same IP budget)');

// -------------------------------------------------------------------
// 2. Upgrade auth model — MUST reject non-Basic-Auth (no PHP_AUTH_PW)
//    with 400 BAD_REQUEST, because it cannot resolve which token to
//    invalidate without the plaintext.
// -------------------------------------------------------------------
// Slice the whole upgrade() body — needs to be large enough to reach
// past the ~60-line docblock into the response return statement.
$bodyStart = $upgradeOffset === false ? 0 : $upgradeOffset;
$bodyLen = 8000;
$upgradeBody = \substr($ctrl, $bodyStart, $bodyLen);

$a(\str_contains($upgradeBody, "PHP_AUTH_PW"),
    'upgrade() reads Basic-Auth plaintext from PHP_AUTH_PW');
$a(\str_contains($upgradeBody, 'Http::STATUS_BAD_REQUEST'),
    'upgrade() returns 400 when PHP_AUTH_PW is empty');
$a(\str_contains($upgradeBody, 'requires HTTP Basic-Auth'),
    'upgrade() 400-message explains WHY (Basic-Auth required)');

// Standard error paths.
$a(\str_contains($upgradeBody, 'Http::STATUS_UNAUTHORIZED'),
    'upgrade() returns 401 when no user');
$a(\str_contains($upgradeBody, 'Http::STATUS_SERVICE_UNAVAILABLE'),
    'upgrade() returns 503 when Stalwart not configured');
$a(\str_contains($upgradeBody, 'Http::STATUS_BAD_GATEWAY'),
    'upgrade() returns 502 on createForUser failure');
$a(\str_contains($upgradeBody, "throttle(['action' => 'souvera_mail_login_flow'])"),
    'upgrade() throttles the same bruteforce action as login-flow');

// -------------------------------------------------------------------
// 3. Atomicity — creates Y BEFORE invalidating X. If Y-create fails,
//    NOTHING is touched (X still works).
// -------------------------------------------------------------------
$createIdx = \strpos($upgradeBody, '$this->appPasswords->createForUser(');
$revokeIdx = \strpos($upgradeBody, '$this->appPasswords->revokeByRawSecret(');
$a($createIdx !== false, 'upgrade() calls createForUser');
$a($revokeIdx !== false, 'upgrade() calls revokeByRawSecret');
$a($createIdx !== false && $revokeIdx !== false && $createIdx < $revokeIdx,
    'upgrade() creates Y BEFORE invalidating X (atomicity guarantee G2)');

// Response includes `upgradedFrom` block with `invalidated` boolean.
$a(\str_contains($upgradeBody, "'upgradedFrom' => ["),
    'upgrade() response includes `upgradedFrom` metadata block');
$a(\str_contains($upgradeBody, "'invalidated' => \$invalidated"),
    'upgrade() reports the invalidation result to the client');
$a(\str_contains($upgradeBody, 'has been invalidated'),
    'success message tells client to drop X from local storage');
$a(\str_contains($upgradeBody, 'revoke it manually'),
    'failure message directs user to manual cleanup');

// Same top-level response shape as /login-flow (bit-compat).
foreach (
    [
        "'server' => \$server",
        "'loginName' => \$userId",
        "self::RESPONSE_KEY_APP_PASSWORD => \$created['secret']",
        "'stalwartId' => \$created['id']",
        "'createdAt' => \$nowIso",
    ] as $needle
) {
    $a(\str_contains($upgradeBody, $needle),
        'upgrade() response has same key as /login-flow: `' . $needle . '`');
}

// -------------------------------------------------------------------
// 4. AppPasswordService::revokeByRawSecret helper.
// -------------------------------------------------------------------
$a((bool) \preg_match(
    '#public\s+function\s+revokeByRawSecret\s*\(\s*string\s+\$userId\s*,\s*string\s+\$rawSecret\s*\)\s*:\s*void#',
    $svc
), 'AppPasswordService::revokeByRawSecret(string $userId, string $rawSecret): void');

// Body: guards empty, calls invalidateToken, log-and-swallow.
$revokeStart = \strpos($svc, 'public function revokeByRawSecret');
$revokeBody = $revokeStart !== false ? \substr($svc, (int) $revokeStart, 1500) : '';
$a(\str_contains($revokeBody, "if (\$rawSecret === '')"),
    'revokeByRawSecret guards empty plaintext');
$a(\str_contains($revokeBody, '$this->ncTokenProvider->invalidateToken($rawSecret)'),
    'revokeByRawSecret calls ITokenProvider::invalidateToken with plaintext');
$a(\str_contains($revokeBody, 'catch (\Throwable $e)'),
    'revokeByRawSecret catches Throwable (log-and-swallow)');
$a(\str_contains($revokeBody, 'could not invalidate NC token'),
    'revokeByRawSecret logs a WARN on failure');

// -------------------------------------------------------------------
// 5. Route registration.
// -------------------------------------------------------------------
$a(\str_contains($routes, "'name' => 'loginFlow#upgrade'"),
    'route loginFlow#upgrade registered');
$a(\str_contains($routes, "'url' => '/app-passwords/upgrade'"),
    'route URL is /app-passwords/upgrade');

$blockStart = \strpos($routes, "'loginFlow#upgrade'");
$a($blockStart !== false, 'located loginFlow#upgrade route block');
$routeBlock = '';
if ($blockStart !== false) {
    $tail = \substr($routes, (int) $blockStart);
    $endOffset = \strpos($tail, '],');
    $routeBlock = $endOffset !== false ? \substr($tail, 0, $endOffset) : \substr($tail, 0, 200);
}
$a(\str_contains($routeBlock, "'verb' => 'POST'"),
    'route verb is POST');
$a(!\str_contains($routeBlock, "'verb' => 'GET'"),
    'route is NOT GET (creates resource)');

// -------------------------------------------------------------------
// 6. Composer classmap sanity — LoginFlowController still in there.
// -------------------------------------------------------------------
$classmap = (string) \file_get_contents('/app/vendor/composer/autoload_classmap.php');
$a(\str_contains($classmap, "OCA\\\\SouveraMail\\\\Controller\\\\LoginFlowController"),
    'composer classmap still includes LoginFlowController');

// -------------------------------------------------------------------
// 7. CLIENT_UPGRADE_PATTERN.txt — playbook exists + covers the
//    documented surfaces.
// -------------------------------------------------------------------
$guide = '/app/docs/CLIENT_UPGRADE_PATTERN.txt';
$a(\is_file($guide), 'CLIENT_UPGRADE_PATTERN.txt exists');
$gsrc = (string) \file_get_contents($guide);
foreach (
    [
        'POST /apps/souvera_mail/app-passwords/upgrade'   => 'documents endpoint URL',
        'WHEN TO CALL `/upgrade`  vs.  `/login-flow`'     => 'decision-matrix section',
        'Authorization: Basic base64(<loginName>:<X>)'    => 'exact auth header shape',
        'upgradedFrom.invalidated'                        => 'documents invalidated field',
        'ATOMICITY GUARANTEES'                            => 'atomicity contract',
        'CLIENT ALGORITHM (recommended)'                  => 'algorithm section',
        'RAM only'                                        => 'security: X must stay in RAM until we know Y works',
        'Cleanup fallback'                                => 'invalidated=false fallback path',
        'GET /apps/souvera_mail/connected-devices'        => 'fallback uses connected-devices API',
        'DELETE /apps/souvera_mail/connected-devices/{'   => 'fallback deletes stale X',
        'Kotlin'                                          => 'Kotlin/Android example',
        'Swift'                                           => 'Swift/iOS example',
        'Rust'                                            => 'Rust/Desktop example',
        'onLoginSuccess'                                  => 'Android glue-code hook',
        'TEST CHECKLIST FOR CLIENT AGENTS'                => 'test checklist section',
        'v0.18.2'                                         => 'versioned',
    ] as $needle => $desc
) {
    $a(\str_contains($gsrc, $needle), 'CLIENT_UPGRADE_PATTERN.txt: ' . $desc);
}

// -------------------------------------------------------------------
// 8. LOGIN_FLOW_CLIENT_INTEGRATION.txt cross-links the upgrade doc.
// -------------------------------------------------------------------
$lgSrc = (string) \file_get_contents('/app/docs/LOGIN_FLOW_CLIENT_INTEGRATION.txt');
$a(\str_contains($lgSrc, 'CLIENT_UPGRADE_PATTERN.txt'),
    'login-flow guide cross-links CLIENT_UPGRADE_PATTERN.txt');
$a(\str_contains($lgSrc, 'RELATED ENDPOINTS — WHICH ONE TO USE'),
    'login-flow guide has decision-matrix section');
$a(\str_contains($lgSrc, '/apps/souvera_mail/app-passwords/upgrade'),
    'login-flow guide mentions /upgrade endpoint URL');

// -------------------------------------------------------------------
// 9. Version bump.
// -------------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$a((bool) \preg_match('#<version>0\.(?:1[8-9]|[2-9]\d)\.[2-9]\d*</version>#', $info)
   || (bool) \preg_match('#<version>0\.(?:19|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.18.2 or higher');
$pkg = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#"version":\s*"0\.(?:1[8-9]|[2-9]\d)\.[2-9]\d*"#', $pkg)
   || (bool) \preg_match('#"version":\s*"0\.(?:19|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.18.2 or higher');

// -------------------------------------------------------------------
// 10. php -l passes on controller + service.
// -------------------------------------------------------------------
$lint = [];
\exec('php -l /app/lib/Controller/LoginFlowController.php 2>&1', $lint, $rc);
$a($rc === 0, 'php -l passes on LoginFlowController: ' . \implode(' | ', $lint));

$lint2 = [];
\exec('php -l /app/lib/Service/AppPasswordService.php 2>&1', $lint2, $rc2);
$a($rc2 === 0, 'php -l passes on AppPasswordService: ' . \implode(' | ', $lint2));

// -------------------------------------------------------------------
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
