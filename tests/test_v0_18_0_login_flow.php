<?php
/**
 * v0.18.0 — Regression pin for the Login-Flow endpoint.
 *
 * ==============================================================
 * Feature under test
 * ==============================================================
 * Operator request (translated):
 *   „Ich hätte gern dass das Passwort auch in Stalwart gesetzt wird,
 *    wenn die Nextcloud-App (Android) beim Login ein NC-Passwort setzt.
 *    Kann NC die Funktion nicht überschreiben, dass IMMER für beide
 *    eines gesetzt wird?"
 *
 * Constraint: Stalwart 0.16 REFUSES caller-supplied plaintext when
 * creating an App-Password, so the classical "hook AppPasswordCreatedEvent"
 * approach doesn't work — we can't force NC's plaintext into Stalwart.
 *
 * Chosen path: a NEW app-owned endpoint that native clients
 * (Souvera-Android, iOS, Desktop) call INSTEAD of NC's /login/v2/poll.
 * The endpoint reuses `AppPasswordService::createForUser()` — the SAME
 * code path that Souvera Mail's web UI uses — so the plaintext is
 * generated ONCE by Stalwart and the NC token is paired to it.
 *
 * Response is bit-compatible with NC's /login/v2/poll
 * (`server`, `loginName`, `appPassword`) plus two Souvera-specific
 * fields (`stalwartId`, `createdAt`).
 *
 * Also bundled in v0.18.0:
 *   Bug fix — declare `AppPasswordService::$inRevoke` explicitly.
 *   Previously used as an undeclared implicit property; PHP 8.2+
 *   emits a deprecation, spamming the log on every revoke request
 *   from the Android app.
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

// -------------------------------------------------------------------
// 1. Controller file exists and declares the class in the correct
//    namespace with expected attributes.
// -------------------------------------------------------------------
$controllerPath = '/app/lib/Controller/LoginFlowController.php';
$a(\is_file($controllerPath), 'LoginFlowController.php file exists');

$controllerSrc = (string) \file_get_contents($controllerPath);
$a(\str_contains($controllerSrc, 'namespace OCA\SouveraMail\Controller;'),
    'controller declares OCA\\SouveraMail\\Controller namespace');
$a((bool) \preg_match('#class\s+LoginFlowController\s+extends\s+Controller\b#', $controllerSrc),
    'LoginFlowController extends Controller');

// Constructor DI signature — we need userSession + urlGenerator to
// build the login-flow-v2-compat response.
$a(\str_contains($controllerSrc, 'private AppPasswordService $appPasswords'),
    'constructor injects AppPasswordService');
$a(\str_contains($controllerSrc, 'private IUserSession $userSession'),
    'constructor injects IUserSession (auth resolution)');
$a(\str_contains($controllerSrc, 'private IURLGenerator $urlGenerator'),
    'constructor injects IURLGenerator (for `server` field)');

// PHP attributes on the endpoint.
$a(\str_contains($controllerSrc, '#[NoAdminRequired]'),
    'endpoint has #[NoAdminRequired] (any user can provision for themself)');
$a(\str_contains($controllerSrc, '#[NoCSRFRequired]'),
    'endpoint has #[NoCSRFRequired] (native clients cannot supply CSRF token)');
$a(\str_contains($controllerSrc, "#[BruteForceProtection(action: 'souvera_mail_login_flow')]"),
    'endpoint has BruteForceProtection with dedicated action name');

// -------------------------------------------------------------------
// 2. Response shape — must be bit-compatible with NC's /login/v2/poll.
//    The Souvera-Android/iOS/Desktop client MUST see the exact same
//    top-level keys (`server`, `loginName`, `appPassword`) so it can
//    drop-in-replace NC's stock endpoint without touching parser code.
// -------------------------------------------------------------------
foreach (
    [
        "'server' => \$server"                              => "response has `server` key",
        "'loginName' => \$userId"                           => "response has `loginName` key",
        "self::RESPONSE_KEY_APP_PASSWORD => \$created['secret']"
                                                            => "response has `appPassword` key with plaintext secret",
        "'stalwartId' => \$created['id']"                   => "response includes `stalwartId` (for future revoke)",
        "'createdAt' => \$nowIso"                           => "response includes ISO-8601 `createdAt`",
    ] as $needle => $desc
) {
    $a(\str_contains($controllerSrc, $needle), $desc);
}

// The constant name that anchors the `appPassword` public key.
$a((bool) \preg_match('#RESPONSE_KEY_APP_PASSWORD\s*=\s*[\'\"]appPassword[\'\"]#', $controllerSrc),
    'RESPONSE_KEY_APP_PASSWORD constant = "appPassword" (bit-identical to NC /login/v2/poll)');

// -------------------------------------------------------------------
// 3. Status code semantics.
// -------------------------------------------------------------------
$a(\str_contains($controllerSrc, 'Http::STATUS_UNAUTHORIZED'),
    '401 for missing auth');
$a(\str_contains($controllerSrc, 'Http::STATUS_SERVICE_UNAVAILABLE'),
    '503 when not configured');
$a(\str_contains($controllerSrc, 'Http::STATUS_BAD_REQUEST'),
    '400 for bad description');
$a(\str_contains($controllerSrc, 'Http::STATUS_BAD_GATEWAY'),
    '502 for Stalwart/NC failure');
$a(\str_contains($controllerSrc, "throttle(['action' => 'souvera_mail_login_flow'])"),
    'unauthenticated attempts throttle the brute-force counter');

// -------------------------------------------------------------------
// 4. Fallback description logic. If the client omits `description`,
//    we auto-derive from the User-Agent so multiple devices per user
//    stay distinguishable in the connected-devices list.
// -------------------------------------------------------------------
$a(\str_contains($controllerSrc, "getHeader('User-Agent')"),
    'auto-description reads User-Agent header');
$a(\str_contains($controllerSrc, "preg_replace('/[[:cntrl:]]+/'"),
    'auto-description strips control chars from User-Agent');
$a(\str_contains($controllerSrc, "\\gmdate('Y-m-d')"),
    'auto-description appends UTC date');
$a(\str_contains($controllerSrc, "'Souvera Client'"),
    'auto-description has the DEFAULT_DESCRIPTION_PREFIX literal');

// -------------------------------------------------------------------
// 5. Reuses AppPasswordService::createForUser (single source of
//    truth for the two-phase Stalwart→NC pair). We must NOT be
//    calling internal Stalwart helpers directly — that would bypass
//    the roll-back safety net and the mapping row.
// -------------------------------------------------------------------
$a(\str_contains($controllerSrc, '$this->appPasswords->createForUser('),
    'delegates to AppPasswordService::createForUser (reuses the exact same paired flow)');
$a(!\str_contains($controllerSrc, 'createStalwartAppPassword'),
    'does NOT call the private Stalwart helper directly (would bypass rollback + mapping)');
$a(!\str_contains($controllerSrc, 'generateToken('),
    'does NOT call ITokenProvider::generateToken directly (must go through the service)');

// -------------------------------------------------------------------
// 6. Route registration in appinfo/routes.php.
// -------------------------------------------------------------------
$routes = (string) \file_get_contents('/app/appinfo/routes.php');
$a(\str_contains($routes, "'name' => 'loginFlow#create'"),
    'route loginFlow#create is registered');
$a(\str_contains($routes, "'url' => '/app-passwords/login-flow'"),
    'route URL is /app-passwords/login-flow');
// Grab the route block and verify verb is POST.
$blockStart = \strpos($routes, "'loginFlow#create'");
$a($blockStart !== false, 'located loginFlow#create route block');
// Slice only up to the closing `],` of THIS route entry (not the next
// route's block — otherwise a neighbouring GET verb false-triggers).
$routeBlock = '';
if ($blockStart !== false) {
    $tail = \substr($routes, (int) $blockStart);
    $endOffset = \strpos($tail, '],');
    $routeBlock = $endOffset !== false ? \substr($tail, 0, $endOffset) : \substr($tail, 0, 200);
}
$a(\str_contains($routeBlock, "'verb' => 'POST'"),
    'route verb is POST (creates a resource)');
$a(!\str_contains($routeBlock, "'verb' => 'GET'"),
    'route is NOT GET (would be cacheable / leak secrets in logs)');

// -------------------------------------------------------------------
// 7. AppPasswordService::$inRevoke property is now declared (bug fix).
// -------------------------------------------------------------------
$svc = (string) \file_get_contents('/app/lib/Service/AppPasswordService.php');
$a((bool) \preg_match('#private\s+bool\s+\$inRevoke\s*=\s*false\s*;#', $svc),
    'AppPasswordService::$inRevoke declared as `private bool $inRevoke = false`');
// Regression-guard: the flag must still be read+written in the same
// two places (revokeForUser sets it, revokeByNcTokenId reads it).
$a(\str_contains($svc, '$this->inRevoke = true;'),
    'revokeForUser still SETS $inRevoke=true');
$a(\str_contains($svc, '$this->inRevoke = false;'),
    'revokeForUser still RESETS $inRevoke=false in finally block');
$a(\str_contains($svc, 'if ($this->inRevoke)'),
    'revokeByNcTokenId still READS $inRevoke (re-entrancy guard)');

// -------------------------------------------------------------------
// 8. Composer classmap — verifies the new controller is autoloadable.
// -------------------------------------------------------------------
$classmap = (string) \file_get_contents('/app/vendor/composer/autoload_classmap.php');
$a(\str_contains($classmap, "OCA\\\\SouveraMail\\\\Controller\\\\LoginFlowController"),
    'composer classmap includes LoginFlowController (PSR-4)');

// -------------------------------------------------------------------
// 9. Client integration guide — the operator explicitly asked for a
//    TXT anleitung. Verify it exists and has the three platforms.
// -------------------------------------------------------------------
$guidePath = '/app/docs/LOGIN_FLOW_CLIENT_INTEGRATION.txt';
$a(\is_file($guidePath), 'client integration guide TXT exists at docs/');
$guide = (string) \file_get_contents($guidePath);
foreach (
    [
        'POST https://<your-nextcloud-host>/apps/souvera_mail/app-passwords/login-flow' => 'guide documents endpoint URL',
        'Basic base64('                             => 'guide covers Basic-Auth',
        'OIDC Bearer'                               => 'guide covers OIDC bearer auth',
        'session cookie'                            => 'guide covers session-cookie auth',
        'appPassword'                               => 'guide references response key `appPassword`',
        'Keystore'                                  => 'guide covers Android secure-storage guidance',
        'Keychain'                                  => 'guide covers iOS Keychain guidance',
        'DPAPI'                                     => 'guide covers Windows/Desktop DPAPI guidance',
        'libsecret'                                 => 'guide covers Linux libsecret guidance',
        'cURL'                                      => 'guide has cURL example',
        'Kotlin'                                    => 'guide has Kotlin/Android example',
        'Swift'                                     => 'guide has Swift/iOS example',
        'Rust'                                      => 'guide has Rust/Desktop example',
        'security:bruteforce:reset'                 => 'guide documents brute-force reset command',
        'v0.18.0'                                   => 'guide is versioned',
    ] as $needle => $desc
) {
    $a(\str_contains($guide, $needle), $desc);
}

// -------------------------------------------------------------------
// 10. Version bump propagated in both metadata files.
// -------------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$a((bool) \preg_match('#<version>0\.(?:1[8-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.18.0 or higher');
$pkg = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#"version":\s*"0\.(?:1[8-9]|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.18.0 or higher');

// -------------------------------------------------------------------
// 11. Behavioural smoke: PHP syntax + class-load check on the new
//     controller. Catches typos in namespaces / imports early.
// -------------------------------------------------------------------
$lint = [];
$lintRc = 0;
\exec('php -l ' . \escapeshellarg($controllerPath) . ' 2>&1', $lint, $lintRc);
$a($lintRc === 0, 'php -l passes on LoginFlowController (' . \implode(' | ', $lint) . ')');

// A syntax-clean file can still fail to load if imports point to
// non-existent classes; verify our IUserSession / IURLGenerator /
// BruteForceProtection imports resolve to their canonical NC paths.
foreach (
    [
        'use OCP\\IUserSession;',
        'use OCP\\IURLGenerator;',
        'use OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired;',
        'use OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired;',
        'use OCP\\AppFramework\\Http\\Attribute\\BruteForceProtection;',
        'use OCP\\AppFramework\\Http\\DataResponse;',
        'use OCP\\AppFramework\\Controller;',
    ] as $stmt
) {
    $a(\str_contains($controllerSrc, $stmt),
        'controller imports canonical NC symbol: ' . $stmt);
}

// -------------------------------------------------------------------
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
