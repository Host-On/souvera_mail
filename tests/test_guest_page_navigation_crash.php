<?php
/**
 * Regression test for the NC34 guest-page TypeError reported on
 * 2026-06-30 (operator brief):
 *
 *   "In appinfo/info.xml fehlt im <navigation> Element der <id>-Tag.
 *    NavigationManager::init() liest $entry['id'], bekommt null,
 *    übergibt das an IAppManager::isAlwaysEnabled(string) → TypeError.
 *    Crash trifft layout.guest.php → blockiert /login, Public-Shares,
 *    alle Guest-Pages."
 *
 * Investigation: our `info.xml` carries NO `<navigations>` element —
 * the navigation entry is registered programmatically in
 * `Application::boot()` via `INavigationManager::add(closure)`. The
 * crash path is identical, though: NC34's `NavigationManager::add()`
 * does `$id = $entry['id']` on the closure's return value, then
 * `$appManager->isEnabledForUser($id)` (strict `string $appId`).
 *
 * Our pre-0.13.6 closure returned `[]` when the user was anonymous
 * or outside the souvera-users group — `$id = null` from that empty
 * array, TypeError on the strict-typed isEnabledForUser, guest-page
 * dead.
 *
 * Fix (0.13.6): we no longer return `[]` from the closure. The user-
 * presence + group-membership check is moved OUT of the closure into
 * the `boot()` body. When the gate fails we simply do not call
 * `$navigationManager->add()` at all — the closure cannot be triggered
 * later with a poisoned empty array.
 *
 * This test pins down:
 *   (1) `boot()` no longer registers a closure when pre-auth.
 *   (2) `boot()` no longer registers a closure when out-of-group.
 *   (3) When `boot()` DOES register, the closure ALWAYS returns the
 *       full required entry shape (`id`, `name`, `href`, `icon`,
 *       `order` set, NEVER `[]`).
 *   (4) info.xml has no empty container elements (the operator
 *       called out `<repair-steps/>`, `<settings/>` cluster crashes).
 *   (5) info.xml's `<navigations>` is either absent OR every
 *       `<navigation>` has an `<id>` child (defence-in-depth in
 *       case we ever add XML-based navigation).
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Application::boot() — gate moved out of the closure
// ---------------------------------------------------------------
$src = file_get_contents('/app/lib/AppInfo/Application.php');

// Closure no longer contains a `return [];` — the empty-array crash trap
$bootStart = strpos($src, 'function boot(');
$bootEnd = strpos($src, "\n    }\n", $bootStart);
$boot = substr($src, $bootStart, $bootEnd - $bootStart);

assertTrue(!preg_match('#return\s*\[\s*\]\s*;#', $boot),
    "boot() body contains NO `return [];` — the NC34 TypeError trap",
    $passes, $failures);

// The pre-auth gate is OUTSIDE the closure
assertTrue((bool)preg_match('#\$user\s*===\s*null[\s\S]{0,500}return;#', $boot),
    "boot() bails with bare `return;` when user is null (no closure registered)",
    $passes, $failures);

// The group gate is OUTSIDE the closure (also `return;`, not `return [];`)
assertTrue((bool)preg_match('#isEnabledForUser\(self::APP_ID,\s*\$user\)[\s\S]{0,200}return;#', $boot),
    "boot() bails with bare `return;` when user is not in the souvera-users group",
    $passes, $failures);

// The closure that DOES get registered always returns the full shape
// (id + name + href + icon + order)
$addStart = strpos($boot, '$navigationManager->add(function');
assertTrue($addStart !== false,
    "boot() still wires up INavigationManager::add(closure) for the happy path",
    $passes, $failures);
$tail = substr($boot, $addStart);
foreach ([
    "'id' => self::APP_ID",
    "'name' => NavigationTitle::resolve",
    "'href' => \$urlGenerator->linkToRoute('souvera_mail.page.index')",
    "'icon' => \$urlGenerator->imagePath(self::APP_ID,",
    "'order' => 4,",
] as $k) {
    assertTrue(str_contains($tail, $k),
        "Registered closure returns $k", $passes, $failures);
}

// Crucially: the closure body has NO `return [];`
$closureStart = strpos($tail, 'function ()');
$closureEnd = strpos($tail, '});', $closureStart);
$closureBody = substr($tail, $closureStart, $closureEnd - $closureStart);
assertTrue(!preg_match('#return\s*\[\s*\]\s*;#', $closureBody),
    "Registered closure body itself contains NO `return [];` (crash trap)",
    $passes, $failures);
// And contains exactly ONE return — the full-shape one
$returnCount = preg_match_all('#\breturn\b#', $closureBody);
assertTrue($returnCount === 1,
    "Registered closure contains exactly 1 return statement (got: $returnCount)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. info.xml hygiene — no empty container elements
// ---------------------------------------------------------------
$info = file_get_contents('/app/appinfo/info.xml');

// 2a. Operator-called-out elements must not be self-closing without children
foreach (['<settings/>', '<repair-steps/>', '<background-jobs/>', '<navigations/>',
          '<commands/>', '<sabre/>', '<collaboration/>', '<two-factor-providers/>',
          '<public/>', '<activity/>', '<trash/>', '<types/>'] as $bad) {
    assertTrue(!str_contains($info, $bad),
        "info.xml contains no $bad (NC InfoParser cluster-crashes on those)",
        $passes, $failures);
}

// 2b. simplexml parses cleanly
$xml = @simplexml_load_string($info);
assertTrue($xml !== false, "info.xml is well-formed XML", $passes, $failures);

// 2c. Every container element under <info> has at least one child or is text-bearing
$names = ['settings','repair-steps','commands','navigations','background-jobs',
          'sabre','collaboration','two-factor-providers','public','activity','trash','types'];
$emptyContainers = [];
foreach ($xml->children() as $name => $node) {
    if (!in_array($name, $names, true)) continue;
    if (count($node->children()) === 0 && trim((string)$node) === '') {
        $emptyContainers[] = (string)$name;
    }
}
assertTrue(empty($emptyContainers),
    "No empty container elements in info.xml (found: " . implode(',', $emptyContainers) . ")",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Navigation XML — defence-in-depth if anyone ever adds it
// ---------------------------------------------------------------
if (isset($xml->navigations)) {
    foreach ($xml->navigations->navigation as $n) {
        $id = (string)$n->id;
        assertTrue($id !== '',
            "If info.xml ships <navigation>, it MUST carry <id> (NC34 requirement) — got: '$id'",
            $passes, $failures);
        assertTrue((string)$n->name !== '',
            "If info.xml ships <navigation>, it MUST carry <name>",
            $passes, $failures);
        assertTrue((string)$n->route !== '',
            "If info.xml ships <navigation>, it MUST carry <route>",
            $passes, $failures);
    }
} else {
    // We intentionally register navigation programmatically. Lock that
    // choice in so a future maintainer doesn't accidentally add a
    // half-baked <navigations> block alongside the closure.
    $passes[] = "info.xml deliberately ships NO <navigations> section (programmatic registration in Application.php)";
    echo "PASS: info.xml deliberately ships NO <navigations> section (programmatic registration in Application.php)\n";
}

// ---------------------------------------------------------------
// 4. Version is at least 0.13.6 (the release that introduced the navigation fix)
// ---------------------------------------------------------------
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(version_compare($vm[1] ?? '0.0.0', '0.13.6', '>='),
    "info.xml <version> >= 0.13.6 (got: '" . ($vm[1] ?? '') . "')",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. Behavioural sim — drive boot()'s navigation branch with stubs
// ---------------------------------------------------------------
//
// Re-inline the post-0.13.6 boot() navigation gate and drive it with
// stub UserSession + AppManager. Drift between this inline copy and
// the real source is caught by the static regex assertions above.

class StubUser {}

class StubUserSession {
    public ?StubUser $user = null;
    public function getUser(): ?StubUser { return $this->user; }
}

class StubAppManager {
    public bool $enabled = true;
    public array $calls = [];
    public function isEnabledForUser(string $appId, ?StubUser $user = null): bool {
        $this->calls[] = ['isEnabledForUser', $appId, $user !== null];
        return $this->enabled;
    }
}

class StubNavigationManager {
    public array $registered = [];
    public function add(\Closure $c): void {
        // Resolve the closure NOW so we catch any `return [];` immediately
        $entry = $c();
        // Mirror NC34's validation (NavigationManager::add does $id = $entry['id'])
        if (!isset($entry['id']) || !is_string($entry['id'])) {
            throw new \TypeError(
                'NavigationManager::add(): entry has no string `id` — would crash NC34 ' .
                '(got: ' . var_export($entry['id'] ?? null, true) . ')'
            );
        }
        $this->registered[] = $entry;
    }
}

function simBoot(StubUserSession $us, StubAppManager $am, StubNavigationManager $nm): void {
    $user = $us->getUser();
    if ($user === null) {
        return;
    }
    if (!$am->isEnabledForUser('souvera_mail', $user)) {
        return;
    }
    $nm->add(function () {
        return [
            'id'    => 'souvera_mail',
            'name'  => 'Mail',
            'href'  => '/index.php/apps/souvera_mail/',
            'icon'  => '/img/logo.png',
            'order' => 4,
        ];
    });
}

// 5a. Pre-auth (no NC user) — no registration, no crash
$us = new StubUserSession();
$am = new StubAppManager();
$nm = new StubNavigationManager();
$crash = null;
try { simBoot($us, $am, $nm); } catch (\Throwable $e) { $crash = $e; }
assertTrue($crash === null,
    "5a: pre-auth (no user) does NOT crash boot()", $passes, $failures);
assertTrue(count($nm->registered) === 0,
    "5a: pre-auth registers ZERO navigation entries", $passes, $failures);
assertTrue($am->calls === [],
    "5a: pre-auth never calls isEnabledForUser() (no token leak on guest pages)",
    $passes, $failures);

// 5b. User but not in souvera-users group — no registration, no crash
$us2 = new StubUserSession(); $us2->user = new StubUser();
$am2 = new StubAppManager(); $am2->enabled = false;
$nm2 = new StubNavigationManager();
$crash = null;
try { simBoot($us2, $am2, $nm2); } catch (\Throwable $e) { $crash = $e; }
assertTrue($crash === null,
    "5b: user-not-in-group does NOT crash boot()", $passes, $failures);
assertTrue(count($nm2->registered) === 0,
    "5b: user-not-in-group registers ZERO navigation entries", $passes, $failures);
assertTrue($am2->calls === [['isEnabledForUser', 'souvera_mail', true]],
    "5b: user-not-in-group consulted isEnabledForUser() exactly once with the real user",
    $passes, $failures);

// 5c. Happy path — full entry registered with id
$us3 = new StubUserSession(); $us3->user = new StubUser();
$am3 = new StubAppManager(); $am3->enabled = true;
$nm3 = new StubNavigationManager();
$crash = null;
try { simBoot($us3, $am3, $nm3); } catch (\Throwable $e) { $crash = $e; }
assertTrue($crash === null,
    "5c: happy path does NOT crash boot()", $passes, $failures);
assertTrue(count($nm3->registered) === 1,
    "5c: happy path registers exactly 1 navigation entry", $passes, $failures);
assertTrue(($nm3->registered[0]['id'] ?? null) === 'souvera_mail',
    "5c: registered entry has id === 'souvera_mail'", $passes, $failures);
foreach (['id', 'name', 'href', 'icon', 'order'] as $k) {
    assertTrue(isset($nm3->registered[0][$k]),
        "5c: registered entry has key '$k'", $passes, $failures);
}

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
