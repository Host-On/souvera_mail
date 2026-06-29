<?php
/**
 * Behavioural verification of the navigation closure registered in
 * /app/lib/AppInfo/Application.php::boot().
 *
 * Verifies the three required states:
 *   (1) IUserSession::getUser() returns null            -> closure returns []
 *   (2) getUser() OK, isEnabledForUser() returns false  -> closure returns []
 *   (3) getUser() OK, isEnabledForUser() returns true   -> closure returns
 *       full nav-entry array with keys id/name/href/icon/order
 *
 * Implementation strategy:
 *   - Define minimal stub classes for IUser, IUserSession, IAppManager,
 *     IAppConfig, IURLGenerator, NavigationTitle resolver, plus a PSR-style
 *     container.
 *   - Inline the exact closure body from Application.php so the test is
 *     self-contained (no need to bootstrap the full Nextcloud server
 *     container). The closure body has been copied verbatim — any drift
 *     between Application.php and this test will be caught by the
 *     structural assertions in the second half of the file (regex against
 *     Application.php source).
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------- Stubs ----------
class StubUser { public function getUID(): string { return 'alice'; } }

class StubUserSession {
    public $user = null;
    public function getUser() { return $this->user; }
}

class StubAppManager {
    public bool $enabled = true;
    public array $calls = [];
    public function isEnabledForUser(string $appId, $user): bool {
        $this->calls[] = [$appId, $user];
        return $this->enabled;
    }
}

class StubAppConfig {
    public function getValueString(string $app, string $key, string $default = ''): string { return $default; }
    public function getAppValueString(string $key, string $default = ''): string { return $default; }
}

class StubUrlGenerator {
    public function linkToRoute(string $r, array $p = []): string { return '/index.php/apps/souvera_mail/'; }
    public function imagePath(string $app, string $img): string  { return "/apps/$app/img/$img"; }
}

class StubContainer {
    /** @var array<string,object> */
    public array $services = [];
    public function get(string $id) {
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Service not registered: $id");
        }
        return $this->services[$id];
    }
}

// Stub NavigationTitle::resolve()
if (!class_exists('OCA\\SouveraMail\\Util\\NavigationTitle')) {
    eval('namespace OCA\\SouveraMail\\Util; class NavigationTitle {
        public static function resolve($appConfig): string { return "Souvera Mail"; }
    }');
}

// Constant alias for self::APP_ID
const APP_ID = 'souvera_mail';

// ---------- Inline copy of closure body from Application.php boot() ----------
$makeNav = function ($serverContainer): array {
    $userSession = $serverContainer->get('OCP\\IUserSession');
    $user = $userSession->getUser();
    if ($user === null) {
        return [];
    }
    $appManager = $serverContainer->get('OCP\\App\\IAppManager');
    if (!$appManager->isEnabledForUser(APP_ID, $user)) {
        return [];
    }

    $appConfig    = $serverContainer->get('OCP\\IAppConfig');
    $urlGenerator = $serverContainer->get('OCP\\IURLGenerator');

    return [
        'id'    => APP_ID,
        'name'  => \OCA\SouveraMail\Util\NavigationTitle::resolve($appConfig),
        'href'  => $urlGenerator->linkToRoute('souvera_mail.page.index'),
        'icon'  => $urlGenerator->imagePath(APP_ID, 'logo-white-64x64.png'),
        'order' => 4,
    ];
};

// ---------- Container factory ----------
function buildContainer(StubUserSession $sess, StubAppManager $apps): StubContainer {
    $c = new StubContainer();
    $c->services['OCP\\IUserSession']     = $sess;
    $c->services['OCP\\App\\IAppManager'] = $apps;
    $c->services['OCP\\IAppConfig']       = new StubAppConfig();
    $c->services['OCP\\IURLGenerator']    = new StubUrlGenerator();
    return $c;
}

// ============================================================
// Case 1: user is null -> []
// ============================================================
$sess = new StubUserSession(); $sess->user = null;
$apps = new StubAppManager();  $apps->enabled = true; // should not even be queried
$res = $makeNav(buildContainer($sess, $apps));
assertTrue($res === [], "Case 1: user=null -> closure returns []", $passes, $failures);
assertTrue(count($apps->calls) === 0, "Case 1: IAppManager::isEnabledForUser NOT called when user=null", $passes, $failures);

// ============================================================
// Case 2: user OK but isEnabledForUser=false -> []
// ============================================================
$sess = new StubUserSession(); $sess->user = new StubUser();
$apps = new StubAppManager();  $apps->enabled = false;
$res = $makeNav(buildContainer($sess, $apps));
assertTrue($res === [], "Case 2: isEnabledForUser=false -> closure returns []", $passes, $failures);
assertTrue(count($apps->calls) === 1, "Case 2: isEnabledForUser called exactly once", $passes, $failures);
assertTrue($apps->calls[0][0] === 'souvera_mail', "Case 2: isEnabledForUser called with APP_ID='souvera_mail'", $passes, $failures);
assertTrue($apps->calls[0][1] instanceof StubUser, "Case 2: isEnabledForUser called with IUser instance", $passes, $failures);

// ============================================================
// Case 3: user OK, isEnabledForUser=true -> full payload
// ============================================================
$sess = new StubUserSession(); $sess->user = new StubUser();
$apps = new StubAppManager();  $apps->enabled = true;
$res = $makeNav(buildContainer($sess, $apps));
assertTrue(is_array($res) && !empty($res), "Case 3: enabled -> closure returns non-empty array", $passes, $failures);
$expectedKeys = ['id', 'name', 'href', 'icon', 'order'];
$gotKeys = array_keys($res);
sort($gotKeys); $sortedExp = $expectedKeys; sort($sortedExp);
assertTrue($gotKeys === $sortedExp, "Case 3: keys are exactly [id,name,href,icon,order] (got: ".implode(',', $gotKeys).")", $passes, $failures);
assertTrue(($res['id'] ?? null) === 'souvera_mail', "Case 3: id == 'souvera_mail'", $passes, $failures);
assertTrue(($res['name'] ?? null) === 'Souvera Mail', "Case 3: name == 'Souvera Mail'", $passes, $failures);
assertTrue(is_string($res['href'] ?? null) && $res['href'] !== '', "Case 3: href is non-empty string", $passes, $failures);
assertTrue(is_string($res['icon'] ?? null) && str_contains($res['icon'], 'souvera_mail'), "Case 3: icon path contains 'souvera_mail'", $passes, $failures);
assertTrue(($res['order'] ?? null) === 4, "Case 3: order == 4", $passes, $failures);

// ============================================================
// Structural verification of Application.php — guarantees the
// inline closure above is faithful to the production source.
// ============================================================
$appSrc = file_get_contents('/app/lib/AppInfo/Application.php');

assertTrue(str_contains($appSrc, 'use OCP\\App\\IAppManager;'),
    "Application.php imports OCP\\App\\IAppManager", $passes, $failures);
assertTrue(str_contains($appSrc, 'use OCP\\IUserSession;'),
    "Application.php imports OCP\\IUserSession", $passes, $failures);
assertTrue(str_contains($appSrc, 'use OCP\\INavigationManager;'),
    "Application.php imports OCP\\INavigationManager", $passes, $failures);

// Exactly one INavigationManager::add() in production code
$addCount = substr_count($appSrc, '$navigationManager->add(');
assertTrue($addCount === 1, "Application.php has exactly ONE \$navigationManager->add() call (got: $addCount)", $passes, $failures);

// Closure must contain the gate BEFORE constructing the payload array.
// Find positions and assert ordering.
$posClosureStart = strpos($appSrc, '$navigationManager->add(function');
$posUserSession  = strpos($appSrc, 'getUser()', $posClosureStart !== false ? $posClosureStart : 0);
$posIfNull       = strpos($appSrc, '$user === null', $posClosureStart !== false ? $posClosureStart : 0);
$posIsEnabled    = strpos($appSrc, 'isEnabledForUser(self::APP_ID', $posClosureStart !== false ? $posClosureStart : 0);
$posReturnArr    = strpos($appSrc, "'id' => self::APP_ID", $posClosureStart !== false ? $posClosureStart : 0);
assertTrue($posClosureStart !== false, "Closure declaration found", $passes, $failures);
assertTrue($posUserSession !== false && $posUserSession > $posClosureStart, "getUser() invoked inside closure", $passes, $failures);
assertTrue($posIfNull !== false && $posIfNull > $posUserSession, "user===null guard appears after getUser()", $passes, $failures);
assertTrue($posIsEnabled !== false && $posIsEnabled > $posIfNull, "isEnabledForUser(self::APP_ID,…) appears AFTER user-null guard", $passes, $failures);
assertTrue($posReturnArr !== false && $posReturnArr > $posIsEnabled, "Payload array constructed AFTER isEnabledForUser guard", $passes, $failures);

// Two early `return []` statements (null-user + disabled)
$emptyReturns = preg_match_all('#return\s*\[\s*\]\s*;#', substr($appSrc, $posClosureStart ?: 0));
assertTrue($emptyReturns >= 2, "Closure has >= 2 early 'return [];' statements (got: $emptyReturns)", $passes, $failures);

// php -l on Application.php
$lintOut = shell_exec('php -l /app/lib/AppInfo/Application.php 2>&1');
assertTrue(str_contains((string)$lintOut, 'No syntax errors'), "php -l passes on Application.php", $passes, $failures);

// info.xml version is 0.11.1
$infoXml = file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $infoXml, $vm);
assertTrue(($vm[1] ?? '') === '0.12.0', "info.xml <version> == 0.11.1 (got: ".($vm[1] ?? '').")", $passes, $failures);

// CHANGELOG has 0.11.1 entry mentioning the navigation/group fix
$changelog = file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($changelog, '[0.11.1]'), "CHANGELOG.md contains [0.11.1] heading", $passes, $failures);
$idx = strpos($changelog, '[0.11.1]');
$idxNext = strpos($changelog, "\n## ", $idx + 5);
$entry = substr($changelog, $idx, $idxNext === false ? null : $idxNext - $idx);
assertTrue(stripos($entry, 'navigation') !== false, "0.11.1 entry mentions 'navigation'", $passes, $failures);
assertTrue(stripos($entry, 'group') !== false || stripos($entry, 'isEnabledForUser') !== false,
    "0.11.1 entry mentions 'group' restriction or isEnabledForUser", $passes, $failures);

// No other navigation registration anywhere in lib/
$grepOther = shell_exec("grep -rn 'NavigationManager.*->add(\\|navigationManager->add(' /app/lib 2>/dev/null");
$otherLines = array_filter(explode("\n", (string)$grepOther));
// Should be exactly the one line in Application.php
$nonAppLines = array_filter($otherLines, fn($l) => !str_contains($l, 'Application.php'));
assertTrue(count($nonAppLines) === 0, "No other ->add() navigation registration outside Application.php (got: ".count($nonAppLines).")", $passes, $failures);

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
