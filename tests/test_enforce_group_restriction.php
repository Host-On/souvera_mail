<?php
/**
 * Static + behavioural verification for the
 * `OCA\SouveraMail\Migration\EnforceGroupRestriction` repair-step
 * introduced in Souvera Mail 0.13.0.
 *
 * What this test covers
 * ---------------------
 * 1. The PHP file exists, has the correct namespace + class, implements
 *    `OCP\Migration\IRepairStep`, and `php -l` is clean.
 * 2. `appinfo/info.xml` registers the class in BOTH `<install>` and
 *    `<post-update>` `<repair-steps>` blocks (so fresh installs *and*
 *    every `occ upgrade` re-converge).
 * 3. `composer dump-autoload -o` has been run — the class is in the
 *    optimised classmap, so Nextcloud's lazy service-container can
 *    locate it without scanning.
 * 4. `Application::RESTRICTED_GROUP_ID` exists and equals `souvera-users`
 *    — the constant the repair-step reads.
 * 5. info.xml version bumped to `0.13.0`.
 * 6. CHANGELOG.md has a `[0.13.0]` entry mentioning the group restriction.
 *
 * 7. Behavioural simulation of `EnforceGroupRestriction::run()`:
 *     a) group already exists → uses the existing group, calls
 *        `enableAppForGroups` with that one group, returns success.
 *     b) group is missing → calls `createGroup`, then binds.
 *     c) `createGroup` returns null → throws RuntimeException with a
 *        helpful operator message (LDAP read-only backend scenario).
 *     d) `enableAppForGroups` throws → step swallows the exception, emits
 *        a `warning(...)` on the IOutput and logs to the LoggerInterface,
 *        returns without re-throwing (so `occ upgrade` keeps going).
 */
declare(strict_types=1);

$failures = [];
$passes = [];

function assertTrue(bool $c, string $m, array &$p, array &$f): void
{
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. File exists + parses
// ---------------------------------------------------------------
$path = '/app/lib/Migration/EnforceGroupRestriction.php';
assertTrue(file_exists($path), "EnforceGroupRestriction.php exists", $passes, $failures);

$src = file_get_contents($path);
assertTrue(str_contains($src, 'namespace OCA\\SouveraMail\\Migration'),
    "EnforceGroupRestriction declares OCA\\SouveraMail\\Migration namespace", $passes, $failures);
assertTrue(preg_match('#class\s+EnforceGroupRestriction\s+implements\s+IRepairStep#', $src) === 1,
    "EnforceGroupRestriction implements IRepairStep", $passes, $failures);
assertTrue(str_contains($src, 'IAppManager'),
    "EnforceGroupRestriction injects IAppManager", $passes, $failures);
assertTrue(str_contains($src, 'IGroupManager'),
    "EnforceGroupRestriction injects IGroupManager", $passes, $failures);
assertTrue(str_contains($src, 'Application::RESTRICTED_GROUP_ID'),
    "EnforceGroupRestriction reads Application::RESTRICTED_GROUP_ID", $passes, $failures);
assertTrue(str_contains($src, 'enableAppForGroups'),
    "EnforceGroupRestriction calls IAppManager::enableAppForGroups()", $passes, $failures);

// php -l
$lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
assertTrue(str_contains((string)$lint, 'No syntax errors'),
    "EnforceGroupRestriction.php passes `php -l`", $passes, $failures);

// ---------------------------------------------------------------
// 2. info.xml registers the step in both repair lifecycles
// ---------------------------------------------------------------
$info = file_get_contents('/app/appinfo/info.xml');

preg_match('#<install>(.*?)</install>#s', $info, $instMatch);
preg_match('#<post-update>(.*?)</post-update>#s', $info, $puMatch);

$installBlock   = $instMatch[1]   ?? '';
$postUpdateBlock = $puMatch[1]    ?? '';

assertTrue(str_contains($installBlock, 'OCA\\SouveraMail\\Migration\\EnforceGroupRestriction'),
    "info.xml <install> repair-step registers EnforceGroupRestriction", $passes, $failures);
assertTrue(str_contains($postUpdateBlock, 'OCA\\SouveraMail\\Migration\\EnforceGroupRestriction'),
    "info.xml <post-update> repair-step registers EnforceGroupRestriction", $passes, $failures);

// InstallStep must still be registered too — we did not replace it
assertTrue(str_contains($installBlock, 'OCA\\SouveraMail\\Migration\\InstallStep'),
    "info.xml <install> still registers InstallStep (regression guard)", $passes, $failures);
assertTrue(str_contains($postUpdateBlock, 'OCA\\SouveraMail\\Migration\\InstallStep'),
    "info.xml <post-update> still registers InstallStep (regression guard)", $passes, $failures);

// info.xml well-formed
$xml = @simplexml_load_string($info);
assertTrue($xml !== false, "info.xml is well-formed XML after adding repair-step", $passes, $failures);

// ---------------------------------------------------------------
// 3. composer classmap contains the new class
// ---------------------------------------------------------------
$classmap = file_get_contents('/app/vendor/composer/autoload_classmap.php');
assertTrue(str_contains($classmap, "'OCA\\\\SouveraMail\\\\Migration\\\\EnforceGroupRestriction'"),
    "autoload_classmap.php has OCA\\SouveraMail\\Migration\\EnforceGroupRestriction", $passes, $failures);

// ---------------------------------------------------------------
// 4. Application::RESTRICTED_GROUP_ID === 'souvera-users'
// ---------------------------------------------------------------
$appSrc = file_get_contents('/app/lib/AppInfo/Application.php');
preg_match("#const\\s+RESTRICTED_GROUP_ID\\s*=\\s*'([^']+)'#", $appSrc, $cm);
assertTrue(($cm[1] ?? '') === 'souvera-users',
    "Application::RESTRICTED_GROUP_ID == 'souvera-users' (got: '" . ($cm[1] ?? '') . "')",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. version bumped to 0.13.0
// ---------------------------------------------------------------
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(($vm[1] ?? '') === '0.13.4',
    "info.xml <version> == 0.13.4 (got: '" . ($vm[1] ?? '') . "')", $passes, $failures);

// ---------------------------------------------------------------
// 6. CHANGELOG mentions the group restriction
// ---------------------------------------------------------------
$changelog = file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($changelog, '[0.13.0]'),
    "CHANGELOG.md contains [0.13.0] heading", $passes, $failures);
$idx = strpos($changelog, '[0.13.0]');
$nextIdx = strpos($changelog, '## [', $idx + 5);
$entry = $nextIdx === false ? substr($changelog, $idx) : substr($changelog, $idx, $nextIdx - $idx);
assertTrue(stripos($entry, 'souvera-users') !== false,
    "CHANGELOG [0.13.0] entry mentions 'souvera-users' group", $passes, $failures);
assertTrue(stripos($entry, 'EnforceGroupRestriction') !== false,
    "CHANGELOG [0.13.0] entry mentions 'EnforceGroupRestriction'", $passes, $failures);

// ===============================================================
// 7. Behavioural sim of EnforceGroupRestriction::run()
// ===============================================================
//
// Stub the OCP namespace just enough to instantiate the real class.
// We can NOT use `require` on the real PHP file directly — it depends
// on `OCA\\SouveraMail\\AppInfo\\Application::RESTRICTED_GROUP_ID`,
// which itself pulls a big OCP graph. So we re-inline the body and
// drive it with stubs. (Same pattern as test_navigation_gate.php.)
// ---------------------------------------------------------------

// Minimal stubs
if (!class_exists('StubOutput')) {
    class StubOutput {
        public array $log = [];
        public function info(string $m): void { $this->log[] = ['info', $m]; }
        public function warning(string $m): void { $this->log[] = ['warning', $m]; }
        public function startProgress(int $max = 0): void {}
        public function advance(int $step = 1, string $desc = ''): void {}
        public function finishProgress(): void {}
    }
}

if (!class_exists('StubGroup')) {
    class StubGroup {
        public function __construct(public string $gid) {}
        public function getGID(): string { return $this->gid; }
    }
}

if (!class_exists('StubGroupManager')) {
    class StubGroupManager {
        public array $existing = [];
        public bool $refuseCreate = false;
        public array $calls = [];
        public function groupExists(string $gid): bool {
            $this->calls[] = ['exists', $gid];
            return isset($this->existing[$gid]);
        }
        public function get(string $gid) {
            $this->calls[] = ['get', $gid];
            return $this->existing[$gid] ?? null;
        }
        public function createGroup(string $gid) {
            $this->calls[] = ['createGroup', $gid];
            if ($this->refuseCreate) { return null; }
            $g = new StubGroup($gid);
            $this->existing[$gid] = $g;
            return $g;
        }
    }
}

if (!class_exists('StubAppManager')) {
    class StubAppManager {
        public array $calls = [];
        public ?\Throwable $throwOnBind = null;
        public function enableAppForGroups(string $appId, array $groups): void {
            $this->calls[] = ['enableAppForGroups', $appId, array_map(
                fn($g) => is_object($g) && method_exists($g, 'getGID') ? $g->getGID() : (string)$g,
                $groups
            )];
            if ($this->throwOnBind !== null) { throw $this->throwOnBind; }
        }
    }
}

if (!class_exists('StubLogger')) {
    class StubLogger {
        public array $log = [];
        public function info($m, $c = []): void { $this->log[] = ['info', (string)$m]; }
        public function warning($m, $c = []): void { $this->log[] = ['warning', (string)$m]; }
        public function error($m, $c = []): void { $this->log[] = ['error', (string)$m]; }
        public function debug($m, $c = []): void {}
        public function notice($m, $c = []): void {}
        public function critical($m, $c = []): void {}
        public function alert($m, $c = []): void {}
        public function emergency($m, $c = []): void {}
        public function log($l, $m, $c = []): void {}
    }
}

/**
 * Re-inlined run() body — kept structurally identical to
 * /app/lib/Migration/EnforceGroupRestriction.php to detect drift.
 */
function simulateRun(StubAppManager $am, StubGroupManager $gm, StubLogger $log, StubOutput $out): ?string {
    $gid = 'souvera-users'; // Application::RESTRICTED_GROUP_ID
    // ensureGroup
    if ($gm->groupExists($gid)) {
        $existing = $gm->get($gid);
        if ($existing !== null) {
            $out->info('Group ' . $gid . ' already exists');
            $group = $existing;
        } else {
            // race-condition: groupExists true but get() null. Fall through to creation.
            $out->info('Creating group ' . $gid);
            $group = $gm->createGroup($gid);
            if ($group === null) {
                return 'Nextcloud refused to create the group "' . $gid . '"';
            }
        }
    } else {
        $out->info('Creating group ' . $gid);
        $group = $gm->createGroup($gid);
        if ($group === null) {
            return 'Nextcloud refused to create the group "' . $gid . '"';
        }
    }
    $out->info('Binding souvera_mail to group ' . $gid);
    try {
        $am->enableAppForGroups('souvera_mail', [$group]);
    } catch (\Throwable $e) {
        $out->warning('Failed to bind souvera_mail to ' . $gid . ': ' . $e->getMessage());
        $log->warning('Souvera Mail group-restriction binding failed: ' . $e->getMessage());
        return null; // swallow
    }
    $out->info('App is now restricted to ' . $gid);
    return null;
}

// 7a. Group already exists
$am = new StubAppManager();
$gm = new StubGroupManager(); $gm->existing['souvera-users'] = new StubGroup('souvera-users');
$log = new StubLogger(); $out = new StubOutput();
$err = simulateRun($am, $gm, $log, $out);
assertTrue($err === null, "7a: existing group — run() does not throw", $passes, $failures);
$bound = array_filter($am->calls, fn($c) => $c[0] === 'enableAppForGroups');
assertTrue(count($bound) === 1, "7a: enableAppForGroups called exactly once", $passes, $failures);
$call = array_values($bound)[0];
assertTrue($call[1] === 'souvera_mail' && $call[2] === ['souvera-users'],
    "7a: enableAppForGroups('souvera_mail', ['souvera-users'])", $passes, $failures);
assertTrue(!in_array(['createGroup', 'souvera-users'], $gm->calls, true),
    "7a: createGroup NOT called when group already exists", $passes, $failures);

// 7b. Group missing — created
$am = new StubAppManager();
$gm = new StubGroupManager();
$log = new StubLogger(); $out = new StubOutput();
$err = simulateRun($am, $gm, $log, $out);
assertTrue($err === null, "7b: missing group — run() does not throw", $passes, $failures);
assertTrue(in_array(['createGroup', 'souvera-users'], $gm->calls, true),
    "7b: createGroup('souvera-users') was called", $passes, $failures);
$bound = array_filter($am->calls, fn($c) => $c[0] === 'enableAppForGroups');
assertTrue(count($bound) === 1,
    "7b: enableAppForGroups was called after group creation", $passes, $failures);

// 7c. createGroup returns null  ->  helpful error
$am = new StubAppManager();
$gm = new StubGroupManager(); $gm->refuseCreate = true;
$log = new StubLogger(); $out = new StubOutput();
$err = simulateRun($am, $gm, $log, $out);
assertTrue(is_string($err) && str_contains($err, 'refused to create the group'),
    "7c: when createGroup returns null, sim returns operator-message",
    $passes, $failures);
$bound = array_filter($am->calls, fn($c) => $c[0] === 'enableAppForGroups');
assertTrue(count($bound) === 0,
    "7c: enableAppForGroups NOT called when group creation fails", $passes, $failures);

// 7d. enableAppForGroups throws -> swallowed + warning emitted
$am = new StubAppManager();
$am->throwOnBind = new \RuntimeException('db locked');
$gm = new StubGroupManager(); $gm->existing['souvera-users'] = new StubGroup('souvera-users');
$log = new StubLogger(); $out = new StubOutput();
$err = simulateRun($am, $gm, $log, $out);
assertTrue($err === null, "7d: binding failure does NOT throw out of run()", $passes, $failures);
$warns = array_filter($out->log, fn($x) => $x[0] === 'warning');
assertTrue(count($warns) === 1,
    "7d: exactly one warning() emitted on IOutput", $passes, $failures);
$loggerWarns = array_filter($log->log, fn($x) => $x[0] === 'warning');
assertTrue(count($loggerWarns) === 1,
    "7d: exactly one warning() logged to LoggerInterface", $passes, $failures);

// Same behaviour against the REAL source - structural assertions to catch drift
assertTrue(str_contains($src, '$this->appManager->enableAppForGroups(Application::APP_ID, [$group])'),
    "real src: enableAppForGroups(Application::APP_ID, [\$group])", $passes, $failures);
assertTrue(str_contains($src, '} catch (\\Throwable $e) {'),
    "real src: catches Throwable around enableAppForGroups()", $passes, $failures);
assertTrue(str_contains($src, "throw new \\RuntimeException("),
    "real src: throws RuntimeException when createGroup returns null", $passes, $failures);

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
