<?php
/**
 * v0.18.1 — Regression pin for the /me identity endpoint and the
 * password-rotation server-hint.
 *
 * ==============================================================
 * Feature under test
 * ==============================================================
 * Operator request:
 *   "Optionaler /me Endpoint der zur loginName → email Auflösung
 *    dient (Client-Guide referenziert es bereits) <- das klingt gut
 *    Und Enhancement-Vorschlag ebenfalls. Wenn der Client Admin dann
 *    dafür was tun muss, erstell wieder ne TXT dafür."
 *
 * Deliverables:
 *   1. `GET /apps/souvera_mail/me` → { uid, loginName, displayName,
 *      email, server, rotation: {enabled, days, hint}, serverTime }.
 *   2. `docs/PASSWORD_ROTATION.txt` with the client rotation
 *      algorithm + admin section (config keys, monitoring, incident
 *      response).
 *   3. Update `docs/LOGIN_FLOW_CLIENT_INTEGRATION.txt` so it no
 *      longer says the /me endpoint is "upcoming".
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

// -------------------------------------------------------------------
// 1. MeController file: exists + namespace + class declaration.
// -------------------------------------------------------------------
$ctrlPath = '/app/lib/Controller/MeController.php';
$a(\is_file($ctrlPath), 'MeController.php file exists');

$ctrl = (string) \file_get_contents($ctrlPath);
$a(\str_contains($ctrl, 'namespace OCA\SouveraMail\Controller;'),
    'MeController declares OCA\\SouveraMail\\Controller namespace');
$a((bool) \preg_match('#class\s+MeController\s+extends\s+Controller\b#', $ctrl),
    'MeController extends Controller');

// DI signature.
foreach (
    [
        'private IUserSession $userSession'      => 'DI: IUserSession',
        'private IConfig $config'                => 'DI: IConfig (for rotation_days)',
        'private IURLGenerator $urlGenerator'    => 'DI: IURLGenerator (for server URL)',
        'private StalwartUserContext $userContext' => 'DI: StalwartUserContext (for email resolution)',
    ] as $needle => $desc
) {
    $a(\str_contains($ctrl, $needle), $desc);
}

// PHP attributes.
$a(\str_contains($ctrl, '#[NoAdminRequired]'),
    '#[NoAdminRequired] — any user reads their own /me');
$a(\str_contains($ctrl, '#[NoCSRFRequired]'),
    '#[NoCSRFRequired] — native clients cannot send CSRF token');
// Deliberately NO BruteForceProtection attribute — authenticated read
// of own data. The word may appear in a docblock (explaining why we
// don't have it) but the PHP attribute `#[BruteForceProtection` must
// NOT be present.
$a(!\str_contains($ctrl, '#[BruteForceProtection'),
    'no #[BruteForceProtection] attribute on /me (not an auth surface)');

// -------------------------------------------------------------------
// 2. Response shape — every key documented in the client guide MUST
//    be present in the response.
// -------------------------------------------------------------------
foreach (
    [
        "'uid' => \$userId"                           => 'response has `uid`',
        "'loginName' => \$userId"                     => 'response has `loginName` (alias for uid)',
        "'displayName' => \$user->getDisplayName()"   => 'response has `displayName` from IUser',
        "'email' => \$email"                          => 'response has `email` (Stalwart-resolved)',
        "'server' => \$server"                        => 'response has `server` (no trailing slash)',
        "'rotation' => \$rotation"                    => 'response has `rotation` sub-object',
        "'serverTime' => \\gmdate('Y-m-d\\TH:i:s\\Z')" => 'response has ISO-8601 serverTime',
    ] as $needle => $desc
) {
    $a(\str_contains($ctrl, $needle), $desc);
}

// Rotation sub-object shape.
foreach (
    [
        "'enabled' => false"        => 'rotation.enabled=false path (disabled config)',
        "'enabled' => true"         => 'rotation.enabled=true path',
        "'days' => \$days"          => 'rotation.days from clamped config',
        "'hint' => 'Password rotation is disabled on this instance.'"
                                    => 'rotation.hint has disabled-copy',
        "'hint' => 'The server recommends rotating this app password every '"
                                    => 'rotation.hint has enabled-copy',
    ] as $needle => $desc
) {
    $a(\str_contains($ctrl, $needle), $desc);
}

// -------------------------------------------------------------------
// 3. Rotation policy — config key + clamping + zero-is-disabled.
// -------------------------------------------------------------------
$a(\str_contains($ctrl, "public const CONFIG_KEY_ROTATION_DAYS = 'rotation_days';"),
    'CONFIG_KEY_ROTATION_DAYS = "rotation_days"');
$a(\str_contains($ctrl, "self::CONFIG_KEY_ROTATION_DAYS"),
    'uses the constant when reading the config (no stringly-typed key)');
$a((bool) \preg_match('#DEFAULT_ROTATION_DAYS\s*=\s*90#', $ctrl),
    'DEFAULT_ROTATION_DAYS = 90');
$a((bool) \preg_match('#ROTATION_DAYS_MIN\s*=\s*1#', $ctrl),
    'ROTATION_DAYS_MIN = 1');
$a((bool) \preg_match('#ROTATION_DAYS_MAX\s*=\s*3650#', $ctrl),
    'ROTATION_DAYS_MAX = 3650 (10 years)');
$a(\str_contains($ctrl, "FILTER_VALIDATE_INT"),
    'coerces config value with FILTER_VALIDATE_INT');
$a(\str_contains($ctrl, 'clamped'),
    'clamps out-of-range values (defensive)');
$a(\str_contains($ctrl, '`rotation_days` config is not an integer'),
    'logs WARN if config is non-integer');
$a(\str_contains($ctrl, 'is outside ['),
    'logs WARN if config outside min/max window');

// -------------------------------------------------------------------
// 4. Error handling — 401 for unauth, graceful email fallback.
// -------------------------------------------------------------------
$a(\str_contains($ctrl, 'Http::STATUS_UNAUTHORIZED'),
    'returns 401 when unauthenticated');
$a(\str_contains($ctrl, '$user->getEMailAddress()'),
    'falls back to IUser::getEMailAddress if StalwartUserContext throws');
$a(\str_contains($ctrl, 'no email available for user'),
    'logs (info) when no email is resolvable');

// -------------------------------------------------------------------
// 5. Route registration.
// -------------------------------------------------------------------
$routes = (string) \file_get_contents('/app/appinfo/routes.php');
$a(\str_contains($routes, "'name' => 'me#show'"),
    'route me#show registered');
$a(\str_contains($routes, "'url' => '/me'"),
    'route URL is /me');
// Find the block and check verb=GET (read-only endpoint).
$blockStart = \strpos($routes, "'me#show'");
$a($blockStart !== false, 'located me#show route block');
$routeBlock = '';
if ($blockStart !== false) {
    $tail = \substr($routes, (int) $blockStart);
    $endOffset = \strpos($tail, '],');
    $routeBlock = $endOffset !== false ? \substr($tail, 0, $endOffset) : \substr($tail, 0, 200);
}
$a(\str_contains($routeBlock, "'verb' => 'GET'"),
    'route verb is GET (read-only endpoint, safe to retry)');
$a(!\str_contains($routeBlock, "'verb' => 'POST'"),
    'route is NOT POST');

// -------------------------------------------------------------------
// 6. Composer classmap includes MeController.
// -------------------------------------------------------------------
$classmap = (string) \file_get_contents('/app/vendor/composer/autoload_classmap.php');
$a(\str_contains($classmap, "OCA\\\\SouveraMail\\\\Controller\\\\MeController"),
    'composer classmap includes MeController');

// -------------------------------------------------------------------
// 7. Admin/Client rotation TXT — exists + covers all documented
//    surfaces from the client guide + admin section.
// -------------------------------------------------------------------
$rotDoc = '/app/docs/PASSWORD_ROTATION.txt';
$a(\is_file($rotDoc), 'password-rotation TXT exists');
$rotSrc = (string) \file_get_contents($rotDoc);
foreach (
    [
        'ROTATION ALGORITHM (client)'                                    => 'has client algorithm section',
        'ADMIN SECTION'                                                 => 'has admin section',
        'CONFIGURE THE ROTATION CADENCE'                                => 'admin: cadence config',
        'config:app:set souvera_mail rotation_days'                     => 'admin: exact occ command',
        '--value=0'                                                     => 'admin: disable-rotation value shown',
        '--value=90'                                                    => 'admin: default 90 shown',
        'RATE-LIMIT IMPACT'                                             => 'admin: rate-limit section',
        'BRUTE-FORCE COUNTER TUNING'                                    => 'admin: brute-force section',
        'security:bruteforce:reset'                                     => 'admin: brute-force reset command',
        'MONITORING — WHAT TO GRAPH'                                    => 'admin: monitoring section',
        'STALE-TOKEN CLEANUP'                                           => 'admin: cleanup section',
        'DISABLING ROTATION IN AN EMERGENCY'                            => 'admin: emergency shutdown',
        'Kotlin'                                                        => 'client: Kotlin example',
        'Swift'                                                         => 'client: Swift example',
        'Rust'                                                          => 'client: Rust example',
        'appPasswordCreatedAt'                                          => 'client: createdAt field usage',
        'stalwartId'                                                    => 'client: stalwartId field usage',
        'DELETE <server>/apps/souvera_mail/app-passwords/<OLD_stalwartId>'
                                                                        => 'client: exact revoke URL shape',
        'FAILURE MODES'                                                 => 'client: failure-modes section',
        'v0.18.1'                                                       => 'doc versioned',
    ] as $needle => $desc
) {
    $a(\str_contains($rotSrc, $needle), $desc);
}

// -------------------------------------------------------------------
// 8. Login-flow client guide was updated (no longer says "upcoming").
// -------------------------------------------------------------------
$loginGuide = (string) \file_get_contents('/app/docs/LOGIN_FLOW_CLIENT_INTEGRATION.txt');
$a(\str_contains($loginGuide, 'GET /apps/souvera_mail/me'),
    'login-flow guide now references the /me endpoint with proper method');
$a(!\str_contains($loginGuide, 'upcoming `/me` endpoint'),
    'login-flow guide no longer says /me is upcoming');
$a(\str_contains($loginGuide, 'PASSWORD_ROTATION.txt'),
    'login-flow guide cross-links the rotation doc');

// -------------------------------------------------------------------
// 9. Version bump propagated.
// -------------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$a((bool) \preg_match('#<version>0\.(?:1[8-9]|[2-9]\d)\.[1-9]\d*</version>#', $info)
   || (bool) \preg_match('#<version>0\.(?:19|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.18.1 or higher');
$pkg = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#"version":\s*"0\.(?:1[8-9]|[2-9]\d)\.[1-9]\d*"#', $pkg)
   || (bool) \preg_match('#"version":\s*"0\.(?:19|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.18.1 or higher');

// -------------------------------------------------------------------
// 10. php -l syntax check on the new controller.
// -------------------------------------------------------------------
$lint = [];
$lintRc = 0;
\exec('php -l ' . \escapeshellarg($ctrlPath) . ' 2>&1', $lint, $lintRc);
$a($lintRc === 0, 'php -l passes on MeController: ' . \implode(' | ', $lint));

// -------------------------------------------------------------------
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
