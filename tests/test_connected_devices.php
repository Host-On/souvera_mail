<?php
/**
 * Static + behavioural test suite for the Connected Devices feature (v0.12.0).
 *
 *   - Static: file existence, namespace, attributes, route count, template IDs,
 *     settings-controller params, JS bootstrap, classmap, CHANGELOG, php -l.
 *   - Behavioural: instantiate ConnectedDevicesService with hand-written stubs
 *     for ITokenProvider / IUserManager / ISession / LoggerInterface / IToken
 *     and assert side-effects of listForUser / revoke / revokeAllOthers.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ============================================================
// 1. File existence
// ============================================================
$svcPath  = '/app/lib/Service/ConnectedDevicesService.php';
$ctrlPath = '/app/lib/Controller/ConnectedDevicesController.php';
assertTrue(file_exists($svcPath),  "Service file exists: $svcPath",  $passes, $failures);
assertTrue(file_exists($ctrlPath), "Controller file exists: $ctrlPath", $passes, $failures);

$svcSrc  = file_get_contents($svcPath);
$ctrlSrc = file_get_contents($ctrlPath);

// ============================================================
// 2. Namespaces + constructor injection
// ============================================================
assertTrue(str_contains($svcSrc,  'namespace OCA\\SouveraMail\\Service;'),
    "Service declares namespace OCA\\SouveraMail\\Service", $passes, $failures);
assertTrue(str_contains($ctrlSrc, 'namespace OCA\\SouveraMail\\Controller;'),
    "Controller declares namespace OCA\\SouveraMail\\Controller", $passes, $failures);

foreach (['ITokenProvider', 'IUserManager', 'ISession', 'LoggerInterface'] as $dep) {
    assertTrue(str_contains($svcSrc, $dep),
        "Service mentions dependency $dep", $passes, $failures);
}
foreach (['listForUser', 'revoke', 'revokeAllOthers'] as $m) {
    assertTrue((bool)preg_match('/public function\s+' . $m . '\s*\(/', $svcSrc),
        "Service has public method $m()", $passes, $failures);
}

// Controller attributes — every action carries #[NoAdminRequired], none #[NoCSRFRequired]
foreach (['index', 'destroy', 'signOutOthers'] as $action) {
    if (!preg_match(
        '/#\[NoAdminRequired\]\s*(?:#\[[^\]]+\]\s*)*public function\s+' . $action . '\s*\(/',
        $svcSrc . $ctrlSrc
    )) {
        assertTrue(false, "$action carries #[NoAdminRequired]", $passes, $failures);
    } else {
        assertTrue(true, "$action carries #[NoAdminRequired]", $passes, $failures);
    }
}
assertTrue(!str_contains($ctrlSrc, '#[NoCSRFRequired]'),
    "Controller has NO #[NoCSRFRequired] attribute (CSRF-enforced)", $passes, $failures);

// ============================================================
// 3. Source-level guard: revoke() refuses self-revocation
// ============================================================
assertTrue((bool)preg_match(
    '/\$tokenId\s*===\s*\$currentTokenId.*?throw\s+new\s+\\\\?InvalidArgumentException/s',
    $svcSrc
), "revoke() throws InvalidArgumentException when tokenId == currentTokenId", $passes, $failures);

// guard must appear BEFORE invalidateTokenById
$posGuard = strpos($svcSrc, 'InvalidArgumentException');
$posInvalidate = strpos($svcSrc, 'invalidateTokenById($uid, $tokenId)');
assertTrue($posGuard !== false && $posInvalidate !== false && $posGuard < $posInvalidate,
    "Self-revoke guard appears BEFORE invalidateTokenById in revoke()", $passes, $failures);

// NC34 strict-type contract: token-provider calls pass string $uid, NOT IUser
// (NC34's IProvider tightened both getTokenByUser and invalidateTokenById from
// `IUser $user` to `string $uid` — passing the IUser instance crashes with
// "must be of type string, OC\User\User given"). Pin all four call sites.
foreach ([
    'getTokenByUser($uid)',
    'invalidateTokenById($uid, $tokenId)',
    'invalidateTokenById($uid, $id)',
] as $needle) {
    assertTrue(str_contains($svcSrc, $needle),
        "Service calls token-provider with string \$uid: $needle", $passes, $failures);
}
assertTrue(!preg_match('#getTokenByUser\(\$user\)#', $svcSrc),
    "Service does NOT pass the IUser instance to getTokenByUser() (NC34 strict-type bug fix)",
    $passes, $failures);
assertTrue(!preg_match('#invalidateTokenById\(\$user,#', $svcSrc),
    "Service does NOT pass the IUser instance to invalidateTokenById() (NC34 strict-type bug fix)",
    $passes, $failures);

// revokeAllOthers skips current
assertTrue((bool)preg_match(
    '/revokeAllOthers\([^)]*\).*?\$id\s*===\s*\$currentTokenId\s*\)\s*{\s*continue;/s',
    $svcSrc
), "revokeAllOthers() skips current session via `continue`", $passes, $failures);

// listForUser usort: current first, then lastActivity desc
assertTrue((bool)preg_match(
    '/usort\(\$items.*?current.*?lastActivity.*?<=>/s',
    $svcSrc
), "listForUser() usort sorts current-first then lastActivity desc", $passes, $failures);

// ============================================================
// 4. routes.php — 13 routes total, including the 3 new ones
// ============================================================
$routes = require '/app/appinfo/routes.php';
assertTrue(isset($routes['routes']) && is_array($routes['routes']),
    "routes.php returns ['routes' => [...]] structure", $passes, $failures);
$count = count($routes['routes']);
assertTrue($count >= 28, "routes.php registers >= 28 routes (got: $count) — grows over time as features land (v0.15.0 external/*, v0.17.0 /embed, …)", $passes, $failures);

$byName = [];
foreach ($routes['routes'] as $r) { $byName[$r['name']] = $r; }

assertTrue(
    isset($byName['connectedDevices#index'])
    && $byName['connectedDevices#index']['url'] === '/connected-devices'
    && $byName['connectedDevices#index']['verb'] === 'GET',
    "Route connectedDevices#index = GET /connected-devices", $passes, $failures
);
assertTrue(
    isset($byName['connectedDevices#destroy'])
    && $byName['connectedDevices#destroy']['url'] === '/connected-devices/{id}'
    && $byName['connectedDevices#destroy']['verb'] === 'DELETE'
    && ($byName['connectedDevices#destroy']['requirements']['id'] ?? null) === '\d+',
    "Route connectedDevices#destroy = DELETE /connected-devices/{id} with id => \\d+ requirement",
    $passes, $failures
);
assertTrue(
    isset($byName['connectedDevices#signOutOthers'])
    && $byName['connectedDevices#signOutOthers']['url'] === '/connected-devices/sign-out-others'
    && $byName['connectedDevices#signOutOthers']['verb'] === 'POST',
    "Route connectedDevices#signOutOthers = POST /connected-devices/sign-out-others",
    $passes, $failures
);

// ============================================================
// 5. Connected-Devices UI moved to the in-engine Settings tab in 0.13.6.
//    The old NC-chrome templates/settings.php + js/personal-settings.js
//    were deleted; the new owner is the engine plugin's Knockout VM.
// ============================================================
assertTrue(!file_exists('/app/templates/settings.php'),
    "templates/settings.php DELETED (Connected Devices section moved to in-engine tab)",
    $passes, $failures);
assertTrue(!file_exists('/app/js/personal-settings.js'),
    "js/personal-settings.js DELETED (logic ported to settings-account.js)",
    $passes, $failures);

$tmplSrc = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html');
foreach ([
    'Verbundene Geräte',                // section heading (de)
    'Alle anderen abmelden',            // sign-out-others button label (shortened in 0.13.6)
    'foreach: devices',                 // Knockout binding
    'dieses Gerät',                     // current-session badge
] as $needle) {
    assertTrue(str_contains($tmplSrc, $needle),
        "Engine-tab template contains '$needle'", $passes, $failures);
}

// ============================================================
// 6. Plugin-side (index.php) emits the 3 ConnectedDevices URLs to JS
// ============================================================
$pluginSrc = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
foreach ([
    "'SmailConnectedDevicesListUrl'",
    "'SmailConnectedDevicesDestroyUrlTemplate'",
    "'SmailConnectedDevicesSignOutOthersUrl'",
] as $k) {
    assertTrue(str_contains($pluginSrc, $k),
        "Engine plugin FilterAppData emits $k", $passes, $failures);
}
assertTrue(str_contains($pluginSrc, "linkToRoute('souvera_mail.connectedDevices.index')"),
    "Engine plugin links to souvera_mail.connectedDevices.index route", $passes, $failures);
assertTrue(str_contains($pluginSrc, "linkToRoute('souvera_mail.connectedDevices.signOutOthers')"),
    "Engine plugin links to souvera_mail.connectedDevices.signOutOthers route", $passes, $failures);

// ============================================================
// 7. settings-account.js — Knockout VM + ConnectedDevices methods
// ============================================================
$jsSrc = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/settings-account.js');
foreach ([
    "rl.addSettingsViewModel"     => "JS registers a Snappymail Settings ViewModel",
    "'souvera-account'"           => "JS uses 'souvera-account' as the hash route",
    "loadDevices"                 => "JS defines loadDevices()",
    "revokeDevice"                => "JS defines revokeDevice()",
    "signOutOthers"               => "JS defines signOutOthers()",
    "SmailConnectedDevicesListUrl" => "JS reads SmailConnectedDevicesListUrl from cfg",
    "SmailConnectedDevicesDestroyUrlTemplate" => "JS reads SmailConnectedDevicesDestroyUrlTemplate from cfg",
    "SmailConnectedDevicesSignOutOthersUrl"   => "JS reads SmailConnectedDevicesSignOutOthersUrl from cfg",
] as $needle => $label) {
    assertTrue(str_contains($jsSrc, $needle), $label, $passes, $failures);
}

// confirm() guard on destructive sign-out
assertTrue((bool)preg_match("/signOutOthers[\s\S]{0,1000}confirm\(/", $jsSrc),
    "JS signOutOthers() has a confirm() guard before firing", $passes, $failures);

// ============================================================
// 8. info.xml version + CHANGELOG
// ============================================================
$info = file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(version_compare($vm[1] ?? '0.0.0', '0.13.6', '>='),
    "info.xml <version> >= 0.13.6 (got: " . ($vm[1] ?? '') . ")", $passes, $failures);

$changelog = file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($changelog, '[0.12.0]'),
    "CHANGELOG.md has [0.12.0] heading", $passes, $failures);
$idx = strpos($changelog, '[0.12.0]');
$idxNext = strpos($changelog, "\n## [0.11", $idx + 5);
$entry = substr($changelog, $idx, $idxNext === false ? 8000 : $idxNext - $idx);
assertTrue(str_contains($entry, 'ConnectedDevicesService'),
    "CHANGELOG [0.12.0] entry mentions ConnectedDevicesService", $passes, $failures);
foreach ([
    '/connected-devices',
    '/connected-devices/{id}',
    '/connected-devices/sign-out-others',
] as $r) {
    assertTrue(str_contains($entry, $r),
        "CHANGELOG [0.12.0] entry mentions route $r", $passes, $failures);
}
assertTrue(stripos($entry, 'Stalwart') !== false && stripos($entry, 'UserSession') !== false,
    "CHANGELOG [0.12.0] has 'reality check' note about Stalwart having no UserSession JMAP object",
    $passes, $failures);

// ============================================================
// 9. php -l on all PHP files under /app/lib
// ============================================================
$lintFails = [];
exec("find /app/lib -name '*.php'", $allPhp);
foreach ($allPhp as $f) {
    $out = shell_exec("php -l " . escapeshellarg($f) . " 2>&1");
    if (!str_contains((string)$out, 'No syntax errors')) {
        $lintFails[] = $f . ' -> ' . trim((string)$out);
    }
}
assertTrue(count($lintFails) === 0,
    "php -l passes on all /app/lib/*.php files (failures: " . implode(';', $lintFails) . ")",
    $passes, $failures);

// ============================================================
// 10. Composer classmap — 2 new classes + count == 273 (after 0.13.0 added EnforceGroupRestriction)
// ============================================================
$classmap = require '/app/vendor/composer/autoload_classmap.php';
assertTrue(isset($classmap['OCA\\SouveraMail\\Service\\ConnectedDevicesService']),
    "Classmap contains OCA\\SouveraMail\\Service\\ConnectedDevicesService", $passes, $failures);
assertTrue(isset($classmap['OCA\\SouveraMail\\Controller\\ConnectedDevicesController']),
    "Classmap contains OCA\\SouveraMail\\Controller\\ConnectedDevicesController", $passes, $failures);
$classmapCount = count($classmap);
// Lower-bound assertion (not strict equality) so the test stays robust as the
// app grows. The bound covers the connected-devices controller + service,
// EnforceGroupRestriction migration, and the namespace-bridge class. Earlier
// versions of this test hard-coded an exact count, which broke every time we
// added a service (e.g. SieveScriptService / JmapSieveStorage in 0.13.14).
assertTrue($classmapCount >= 274,
    "Classmap class count >= 274 (got: $classmapCount)", $passes, $failures);

// ============================================================
// 11. Existing regression suites still pass
// ============================================================
foreach (['/app/tests/test_navigation_gate.php' => 29,
          '/app/tests/test_souvera_mail_rename.php' => 55] as $tf => $expected) {
    $out = shell_exec("php " . escapeshellarg($tf) . " 2>&1");
    $ok = preg_match('/PASSED:\s*' . $expected . '\s*\/\s*' . $expected . '/', (string)$out)
        && str_contains((string)$out, 'ALL TESTS PASSED');
    assertTrue($ok, "Regression suite still green: " . basename($tf) . " ($expected/$expected)",
        $passes, $failures);
}

// ============================================================
// 12. BEHAVIOURAL SIMULATION
// ============================================================
// Define OCP\* stub interfaces via eval() (Nextcloud's real ones aren't
// autoloaded in this environment; switching namespaces mid-file is not legal,
// so eval() is the simplest portable trick).
if (!interface_exists('OCP\\IUser')) {
    eval('namespace OCP { interface IUser { public function getUID(): string; } }');
}
if (!interface_exists('OCP\\IUserManager')) {
    eval('namespace OCP { interface IUserManager { public function get(string $uid); } }');
}
if (!interface_exists('OCP\\ISession')) {
    eval('namespace OCP { interface ISession { public function getId(): string; } }');
}
if (!interface_exists('OCP\\Authentication\\Token\\IToken')) {
    eval('namespace OCP\\Authentication\\Token {
        interface IToken {
            public const PERMANENT_TOKEN = 0;
            public const TEMPORARY_TOKEN = 1;
            public function getId(): int;
            public function getName(): string;
            public function getType(): int;
            public function getLastActivity(): int;
            public function getScopeAsArray(): array;
        }
    }');
}
if (!interface_exists('OCP\\Authentication\\Token\\IProvider')) {
    eval('namespace OCP\\Authentication\\Token {
        interface IProvider {
            public function getTokenByUser(string $uid): array;
            public function getToken(string $sessionId): IToken;
            public function invalidateTokenById(string $uid, int $id): void;
        }
    }');
}
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log {
        interface LoggerInterface {
            public function emergency(string|\\Stringable $message, array $context = []): void;
            public function alert    (string|\\Stringable $message, array $context = []): void;
            public function critical (string|\\Stringable $message, array $context = []): void;
            public function error    (string|\\Stringable $message, array $context = []): void;
            public function warning  (string|\\Stringable $message, array $context = []): void;
            public function notice   (string|\\Stringable $message, array $context = []): void;
            public function info     (string|\\Stringable $message, array $context = []): void;
            public function debug    (string|\\Stringable $message, array $context = []): void;
            public function log($level, string|\\Stringable $message, array $context = []): void;
        }
    }');
}

// Now load the production class (will resolve the stub interfaces above).
require_once '/app/lib/Service/ConnectedDevicesService.php';

// --- Stub user / token / providers ----------------------------------------
class StubUser2 implements \OCP\IUser { public function getUID(): string { return 'alice'; } }

class StubTok implements \OCP\Authentication\Token\IToken {
    public function __construct(
        public int $id, public string $name = 'tok', public int $type = self::TEMPORARY_TOKEN,
        public int $last = 0, public array $scope = []
    ) {}
    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getType(): int { return $this->type; }
    public function getLastActivity(): int { return $this->last; }
    public function getScopeAsArray(): array { return $this->scope; }
}

class StubTokenProvider implements \OCP\Authentication\Token\IProvider {
    /** @var StubTok[] */ public array $tokens = [];
    /** @var int[] */ public array $invalidated = [];
    /** @var string[] */ public array $uidsSeen = [];
    public ?StubTok $sessionToken = null;
    public bool $sessionTokenThrows = false;
    public array $invalidateThrowsForIds = [];

    public function getTokenByUser(string $uid): array {
        $this->uidsSeen[] = $uid;
        return $this->tokens;
    }
    public function getToken(string $sessionId): \OCP\Authentication\Token\IToken {
        if ($this->sessionTokenThrows || $this->sessionToken === null) {
            throw new \RuntimeException('no session token');
        }
        return $this->sessionToken;
    }
    public function invalidateTokenById(string $uid, int $id): void {
        $this->uidsSeen[] = $uid;
        if (in_array($id, $this->invalidateThrowsForIds, true)) {
            throw new \RuntimeException("simulated failure on id=$id");
        }
        $this->invalidated[] = $id;
    }
}

class StubUserMgr implements \OCP\IUserManager {
    public ?\OCP\IUser $user = null;
    public function get(string $uid) { return $this->user; }
}

class StubSession implements \OCP\ISession {
    public string $id = '';
    public function getId(): string { return $this->id; }
}

class StubLogger implements \Psr\Log\LoggerInterface {
    public array $warnings = [];
    public function emergency(\Stringable|string $m, array $c = []): void {}
    public function alert    (\Stringable|string $m, array $c = []): void {}
    public function critical (\Stringable|string $m, array $c = []): void {}
    public function error    (\Stringable|string $m, array $c = []): void {}
    public function warning  (\Stringable|string $m, array $c = []): void { $this->warnings[] = (string)$m; }
    public function notice   (\Stringable|string $m, array $c = []): void {}
    public function info     (\Stringable|string $m, array $c = []): void {}
    public function debug    (\Stringable|string $m, array $c = []): void {}
    public function log($l, \Stringable|string $m, array $c = []): void {}
}

global $passes, $failures;

// --- Case A: revoke() refuses self-revocation -----------------------------
$user    = new StubUser2();
$tokProv = new StubTokenProvider();
$tokProv->sessionToken = new StubTok(42, 'current'); // resolveCurrentTokenId() -> 42
$userMgr = new StubUserMgr(); $userMgr->user = $user;
$sess    = new StubSession(); $sess->id = 'sid-abc';
$log     = new StubLogger();
$svc = new \OCA\SouveraMail\Service\ConnectedDevicesService($tokProv, $userMgr, $sess, $log);

$threw = false;
try { $svc->revoke('alice', 42); }
catch (\InvalidArgumentException $e) { $threw = true; }
assertTrue($threw, "revoke() throws InvalidArgumentException when tokenId == currentTokenId",
    $passes, $failures);
assertTrue(count($tokProv->invalidated) === 0,
    "revoke() does NOT call invalidateTokenById on self-revoke", $passes, $failures);

// --- Case B: revoke() invalidates non-current token ----------------------
$tokProv->invalidated = [];
$tokProv->uidsSeen = [];
$svc->revoke('alice', 99);
assertTrue($tokProv->invalidated === [99],
    "revoke() invalidates non-current token exactly once (got: ["
    . implode(',', $tokProv->invalidated) . "])", $passes, $failures);
// NC34 strict-type contract: the token-provider must be called with the
// canonical UID string, never the IUser instance. uidsSeen records each call.
assertTrue(in_array('alice', $tokProv->uidsSeen, true) && !in_array($user, $tokProv->uidsSeen, true),
    "revoke() forwards string uid 'alice' to invalidateTokenById (NC34 strict-type bug fix)",
    $passes, $failures);

// --- Case C: revokeAllOthers() — 3 tokens, one current → 2 invalidations -
$tokProv->invalidated = [];
$tokProv->uidsSeen = [];
$tokProv->tokens = [
    new StubTok(42, 'current'),  // current
    new StubTok(100, 'phone'),
    new StubTok(101, 'desktop'),
];
$n = $svc->revokeAllOthers('alice');
assertTrue($n === 2, "revokeAllOthers() returns 2 (got: $n)", $passes, $failures);
sort($tokProv->invalidated);
assertTrue($tokProv->invalidated === [100, 101],
    "revokeAllOthers() invalidated [100,101] (got: [" . implode(',', $tokProv->invalidated) . "])",
    $passes, $failures);
// NC34 strict-type contract on every call inside the iterator
assertTrue($tokProv->uidsSeen !== [] && array_unique($tokProv->uidsSeen) === ['alice'],
    "revokeAllOthers() forwards string uid 'alice' on every token-provider call (NC34 strict-type bug fix)",
    $passes, $failures);

// --- Case D: revokeAllOthers() — graceful failure on one token -----------
$tokProv->invalidated = [];
$tokProv->invalidateThrowsForIds = [100];
$tokProv->tokens = [
    new StubTok(42),  // current
    new StubTok(100), // will throw
    new StubTok(101), // will succeed
];
$log->warnings = [];
$n = $svc->revokeAllOthers('alice');
assertTrue($n === 1,
    "revokeAllOthers() returns 1 on partial failure (got: $n)", $passes, $failures);
assertTrue($tokProv->invalidated === [101],
    "revokeAllOthers() only the successful id is recorded (got: ["
    . implode(',', $tokProv->invalidated) . "])", $passes, $failures);
assertTrue(count($log->warnings) === 1 && str_contains($log->warnings[0], '100'),
    "revokeAllOthers() logs warning for failed token id=100", $passes, $failures);

// --- Case E: listForUser() — current first then lastActivity desc --------
$tokProv->invalidateThrowsForIds = [];
$tokProv->tokens = [
    new StubTok(100, 'phone',   \OCP\Authentication\Token\IToken::TEMPORARY_TOKEN, 1000),
    new StubTok(42,  'current', \OCP\Authentication\Token\IToken::TEMPORARY_TOKEN, 500),
    new StubTok(101, 'desk',    \OCP\Authentication\Token\IToken::PERMANENT_TOKEN, 2000),
];
$tokProv->sessionToken = new StubTok(42); // current = 42
$items = $svc->listForUser('alice');
assertTrue(count($items) === 3,
    "listForUser() returns 3 items (got: " . count($items) . ")", $passes, $failures);
assertTrue($items[0]['id'] === 42 && $items[0]['current'] === true,
    "listForUser() pins current session (id=42) to top", $passes, $failures);
assertTrue($items[1]['id'] === 101 && $items[2]['id'] === 100,
    "listForUser() sorts remaining by lastActivity desc (101 then 100)", $passes, $failures);
assertTrue($items[2]['type'] === 'browser' && $items[1]['type'] === 'app',
    "listForUser() classifies PERMANENT_TOKEN as 'app', TEMPORARY_TOKEN as 'browser'",
    $passes, $failures);

// --- Case F: listForUser() with no session token -- nobody marked current
$tokProv->sessionTokenThrows = true;
$items = $svc->listForUser('alice');
$noneCurrent = array_filter($items, fn($it) => $it['current'] === true);
assertTrue(count($noneCurrent) === 0,
    "listForUser() marks nobody as current when session lookup fails", $passes, $failures);

// --- Case G: requireUser() — unknown user → RuntimeException -------------
$userMgr->user = null;
$threw = false;
try { $svc->listForUser('ghost'); }
catch (\RuntimeException $e) { $threw = true; }
assertTrue($threw, "listForUser() throws RuntimeException when user not found",
    $passes, $failures);

// ============================================================
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
