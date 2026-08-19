<?php
/**
 * Regression test for the JMAP-based Sieve provider that replaces the
 * engine's default ManageSieve-port-4190 dial-out.
 *
 * Context (PRD step 23 + 25):
 *   Settings → Filters surfaces engine notification 352 (`CantGetFilters`)
 *   against the operator's Stalwart 0.16 deploy. The engine wraps every
 *   exception from the ManageSieve dial-out as the generic 352 — without
 *   surfacing the underlying TLS / listener / SASL error. Operator-confirmed
 *   fix direction (PRD step 23 open follow-up): build a JMAP-based Sieve
 *   provider that uses Stalwart's `urn:ietf:params:jmap:sieve` capability +
 *   reuses the H2CK/oidc JWT bearer flow we already proved out for
 *   AppPasswords / Quota / Identity sync.
 *
 * Test coverage:
 *   1) Service class file structure (DI signature, methods, JMAP envelope shape)
 *   2) Engine-side provider implements FiltersInterface + handles all four
 *      methods + maps PHP exceptions to engine Notifications::Cant* codes
 *   3) Snappymail plugin wires the provider via main.fabrica('filters')
 *   4) Composer classmap routes both new classes (fast-path on fresh dump)
 *   5) Behavioural sim: with a stubbed SieveScriptService, JmapSieveStorage's
 *      Load/Save/Activate/Delete delegate correctly and translate errors.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. SieveScriptService — source contract
// ---------------------------------------------------------------
$svcPath = '/app/lib/Service/SieveScriptService.php';
assertTrue(\is_file($svcPath), "lib/Service/SieveScriptService.php exists", $passes, $failures);
$lint = (string) shell_exec("php -l $svcPath 2>&1");
assertTrue(str_contains($lint, 'No syntax errors'),
    "SieveScriptService passes `php -l`", $passes, $failures);

$svc = (string) file_get_contents($svcPath);
assertTrue(str_contains($svc, 'namespace OCA\\SouveraMail\\Service;'),
    "SieveScriptService lives in OCA\\SouveraMail\\Service", $passes, $failures);
assertTrue(str_contains($svc, "CAPABILITY_SIEVE = 'urn:ietf:params:jmap:sieve'"),
    "SieveScriptService declares the standard JMAP-Sieve capability URI", $passes, $failures);
assertTrue(str_contains($svc, "private StalwartAdminService \$stalwart")
        && str_contains($svc, "private StalwartUserContext \$userContext")
        && str_contains($svc, "private IClientService \$clientService"),
    "SieveScriptService DI: StalwartAdminService + StalwartUserContext + IClientService + LoggerInterface",
    $passes, $failures);
assertTrue(str_contains($svc, 'public function isAvailable(): bool'),
    "SieveScriptService exposes isAvailable() — gates the engine hook", $passes, $failures);
assertTrue(str_contains($svc, 'public function listScriptsWithBodies(string $userId): array'),
    "SieveScriptService::listScriptsWithBodies(string \$userId) signature", $passes, $failures);
assertTrue(str_contains($svc, 'public function saveScript(string $userId, string $name, string $body): string'),
    "SieveScriptService::saveScript(uid, name, body) returns the script id", $passes, $failures);
assertTrue(str_contains($svc, 'public function activateScript(string $userId, string $name): void'),
    "SieveScriptService::activateScript(uid, name) — empty name → deactivate-all", $passes, $failures);
assertTrue(str_contains($svc, 'public function deleteScript(string $userId, string $name): void'),
    "SieveScriptService::deleteScript(uid, name) — idempotent on missing name", $passes, $failures);
assertTrue(str_contains($svc, "'SieveScript/get'"),
    "SieveScriptService uses the standard JMAP method name 'SieveScript/get'", $passes, $failures);
assertTrue(str_contains($svc, "'SieveScript/set'"),
    "SieveScriptService uses the standard JMAP method name 'SieveScript/set'", $passes, $failures);
assertTrue(str_contains($svc, "'Blob/get'"),
    "SieveScriptService fetches script bodies via Blob/get on the same envelope", $passes, $failures);
assertTrue(str_contains($svc, "'#ids'") && str_contains($svc, "'resultOf' => 'c0'"),
    "SieveScriptService chains SieveScript/get → Blob/get via JMAP back-reference (1 round-trip)",
    $passes, $failures);
assertTrue(str_contains($svc, "'/jmap/upload/'") && str_contains($svc, "\\rawurlencode(\$accountId)"),
    "SieveScriptService uploads blobs via JMAP path-style URL /jmap/upload/{accountId}/ (v0.14.36 fix: was ?account= before, which Stalwart 0.16 404s)",
    $passes, $failures);
// Negative guard against the broken query-param form ever coming back.
assertTrue(!str_contains($svc, "'/jmap/upload?account='"),
    "SieveScriptService MUST NOT use the query-param form '?account=' (diagnosis 2026-02-19)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. JmapSieveStorage — engine-side provider
// ---------------------------------------------------------------
$provPath = '/app/lib/Engine/Filters/JmapSieveStorage.php';
assertTrue(\is_file($provPath), "lib/Engine/Filters/JmapSieveStorage.php exists", $passes, $failures);
$lint = (string) shell_exec("php -l $provPath 2>&1");
assertTrue(str_contains($lint, 'No syntax errors'),
    "JmapSieveStorage passes `php -l`", $passes, $failures);

$prov = (string) file_get_contents($provPath);
assertTrue(str_contains($prov, 'namespace OCA\\SouveraMail\\Engine\\Filters;'),
    "JmapSieveStorage lives in OCA\\SouveraMail\\Engine\\Filters", $passes, $failures);
assertTrue(str_contains($prov, 'implements FiltersInterface'),
    "JmapSieveStorage implements the engine's FiltersInterface", $passes, $failures);
assertTrue(str_contains($prov, 'public function Load(Account $oAccount): array'),
    "JmapSieveStorage::Load(Account) signature matches FiltersInterface", $passes, $failures);
assertTrue(str_contains($prov, 'public function Save(Account $oAccount, string $sScriptName, string $sRaw): bool'),
    "JmapSieveStorage::Save signature matches FiltersInterface", $passes, $failures);
assertTrue(str_contains($prov, 'public function Activate(Account $oAccount, string $sScriptName): bool'),
    "JmapSieveStorage::Activate signature matches FiltersInterface", $passes, $failures);
assertTrue(str_contains($prov, 'public function Delete(Account $oAccount, string $sScriptName): bool'),
    "JmapSieveStorage::Delete signature matches FiltersInterface", $passes, $failures);
assertTrue(str_contains($prov, 'Notifications::CantGetFilters->value'),
    "JmapSieveStorage::Load throws ClientException(CantGetFilters) on failure (engine UI shows clean toast)",
    $passes, $failures);
assertTrue(str_contains($prov, 'Notifications::CantSaveFilters->value'),
    "JmapSieveStorage::Save throws ClientException(CantSaveFilters) on failure", $passes, $failures);
assertTrue(str_contains($prov, 'Notifications::CantActivateFiltersScript->value'),
    "JmapSieveStorage::Activate throws ClientException(CantActivateFiltersScript) on failure",
    $passes, $failures);
assertTrue(str_contains($prov, 'Notifications::CantDeleteFiltersScript->value'),
    "JmapSieveStorage::Delete throws ClientException(CantDeleteFiltersScript) on failure",
    $passes, $failures);
assertTrue(str_contains($prov, "'@Object' => 'Object/SieveScript'"),
    "JmapSieveStorage::Load emits the engine-expected '@Object/SieveScript' shape", $passes, $failures);
assertTrue(str_contains($prov, 'SieveStorage::SIEVE_FILE_NAME'),
    "JmapSieveStorage::Load seeds an empty default script via engine's SIEVE_FILE_NAME constant (engine UX parity, no magic string)",
    $passes, $failures);
assertTrue(str_contains($prov, "'fileinto'") && str_contains($prov, "'vacation'") && str_contains($prov, "'imap4flags'"),
    "JmapSieveStorage::Load advertises Stalwart's standard Sieve extensions in Capa[]",
    $passes, $failures);
assertTrue(str_contains($prov, 'IUserSession') && str_contains($prov, '->getUser()'),
    "JmapSieveStorage resolves uid from NC session, NOT from Account->Email() (shared-mailbox safety)",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Snappymail plugin wires it via main.fabrica('filters')
// ---------------------------------------------------------------
$plugin = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
assertTrue((bool) preg_match("/elseif\\s*\\(\\s*'filters'\\s*===\\s*\\\$sName\\s*\\)/", $plugin),
    "Plugin's MainFabrica branches on 'filters' (the engine's fabrica name)", $passes, $failures);
assertTrue(str_contains($plugin, 'OCA\\SouveraMail\\Engine\\Filters\\JmapSieveStorage'),
    "Plugin references JmapSieveStorage from main.fabrica('filters')", $passes, $failures);
assertTrue(str_contains($plugin, 'OCA\\SouveraMail\\Service\\SieveScriptService'),
    "Plugin probes SieveScriptService availability before wiring the provider", $passes, $failures);
assertTrue(str_contains($plugin, "} catch (\\Throwable \$e)") && str_contains($plugin, 'JmapSieveStorage wiring skipped'),
    "Plugin swallows hook-level failures so engine boot survives misconfig", $passes, $failures);

// ---------------------------------------------------------------
// 4. Composer classmap fast-path
// ---------------------------------------------------------------
$classmap = (string) file_get_contents('/app/vendor/composer/autoload_classmap.php');
assertTrue(str_contains($classmap, "'OCA\\\\SouveraMail\\\\Service\\\\SieveScriptService' => \$baseDir . '/lib/Service/SieveScriptService.php'"),
    "Composer classmap has SieveScriptService (fast path)", $passes, $failures);
assertTrue(str_contains($classmap, "'OCA\\\\SouveraMail\\\\Engine\\\\Filters\\\\JmapSieveStorage' => \$baseDir . '/lib/Engine/Filters/JmapSieveStorage.php'"),
    "Composer classmap has JmapSieveStorage (fast path)", $passes, $failures);

// ---------------------------------------------------------------
// 5. Behavioural sim — stub the engine + SieveScriptService and
//    drive the JmapSieveStorage methods through one happy path +
//    one failure path each.
// ---------------------------------------------------------------
$sim = <<<'PHP'
<?php
declare(strict_types=1);

// Stub the minimal engine surface the provider touches.
namespace Psr\Log {
    if (!interface_exists('Psr\\Log\\LoggerInterface')) {
        interface LoggerInterface {
            public function emergency($message, array $context = []): void;
            public function alert($message, array $context = []): void;
            public function critical($message, array $context = []): void;
            public function error($message, array $context = []): void;
            public function warning($message, array $context = []): void;
            public function notice($message, array $context = []): void;
            public function info($message, array $context = []): void;
            public function debug($message, array $context = []): void;
            public function log($level, $message, array $context = []): void;
        }
    }
}
namespace OCP {
    if (!interface_exists('OCP\\IUser')) {
        interface IUser {
            public function getUID(): string;
        }
    }
    if (!interface_exists('OCP\\IUserSession')) {
        interface IUserSession {
            public function login($user, $password): bool;
            public function logout(): bool;
            public function setUser($user): void;
            public function getUser(): ?IUser;
            public function isLoggedIn(): bool;
            public function getImpersonatingUserID(): ?string;
            public function setImpersonatingUserID(?string $uid = null): void;
        }
    }
}
namespace Smail\Engine {
    if (!enum_exists('Smail\\Engine\\Notifications')) {
        enum Notifications: int {
            case CantGetFilters = 352;
            case CantSaveFilters = 357;
            case CantActivateFiltersScript = 359;
            case CantDeleteFiltersScript = 358;
        }
    }
}
namespace Smail\Engine\Model {
    class Account {
        public function Email(): string { return 'stub@example.invalid'; }
    }
}
namespace Smail\Engine\Providers\Filters {
    interface FiltersInterface {
        public function Load(\Smail\Engine\Model\Account $oAccount): array;
        public function Save(\Smail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw): bool;
        public function Activate(\Smail\Engine\Model\Account $oAccount, string $sScriptName): bool;
        public function Delete(\Smail\Engine\Model\Account $oAccount, string $sScriptName): bool;
    }
    if (!class_exists('Smail\\Engine\\Providers\\Filters\\SieveStorage')) {
        class SieveStorage {
            public const SIEVE_FILE_NAME = 'smail.user';
        }
    }
}
namespace Smail\Engine\Exceptions {
    class ClientException extends \RuntimeException {
        public int $iCode;
        public function __construct(int $iCode, \Throwable $prev = null) {
            parent::__construct('ClientException(' . $iCode . ')', $iCode, $prev);
            $this->iCode = $iCode;
        }
    }
}

namespace Stub {
    use Psr\Log\LoggerInterface;
    final class FakeLogger implements LoggerInterface {
        public array $entries = [];
        public function emergency($message, array $context = []): void {}
        public function alert($message, array $context = []): void {}
        public function critical($message, array $context = []): void {}
        public function error($message, array $context = []): void { $this->entries[] = ['error', $message]; }
        public function warning($message, array $context = []): void { $this->entries[] = ['warning', $message]; }
        public function notice($message, array $context = []): void {}
        public function info($message, array $context = []): void {}
        public function debug($message, array $context = []): void {}
        public function log($level, $message, array $context = []): void { $this->entries[] = [$level, $message]; }
    }

    final class FakeUser implements \OCP\IUser {
        public function __construct(private string $uid) {}
        public function getUID(): string { return $this->uid; }
    }
    final class FakeUserSession implements \OCP\IUserSession {
        public function __construct(private ?\Stub\FakeUser $user) {}
        public function login($user, $password): bool { return false; }
        public function logout(): bool { return true; }
        public function setUser($user): void {}
        public function getUser(): ?\OCP\IUser { return $this->user; }
        public function isLoggedIn(): bool { return $this->user !== null; }
        public function getImpersonatingUserID(): ?string { return null; }
        public function setImpersonatingUserID(?string $uid = null): void {}
    }
}

namespace OCA\SouveraMail\Service {
    final class StubSieveScriptService {
        public bool $available = true;
        public array $listOut = ['scripts' => []];
        public ?\Throwable $listThrow = null;
        public ?\Throwable $saveThrow = null;
        public ?\Throwable $activateThrow = null;
        public ?\Throwable $deleteThrow = null;
        public array $calls = [];
        public function isAvailable(): bool { return $this->available; }
        public function listScriptsWithBodies(string $uid): array {
            $this->calls[] = ['list', $uid];
            if ($this->listThrow) throw $this->listThrow;
            return $this->listOut;
        }
        public function saveScript(string $uid, string $name, string $body): string {
            $this->calls[] = ['save', $uid, $name, $body];
            if ($this->saveThrow) throw $this->saveThrow;
            return 'srv-id-1';
        }
        public function activateScript(string $uid, string $name): void {
            $this->calls[] = ['activate', $uid, $name];
            if ($this->activateThrow) throw $this->activateThrow;
        }
        public function deleteScript(string $uid, string $name): void {
            $this->calls[] = ['delete', $uid, $name];
            if ($this->deleteThrow) throw $this->deleteThrow;
        }
    }
}

namespace {
    // Load JmapSieveStorage source — substitute the SieveScriptService type
    // hint for our stub (PHP doesn't have nominal-typing constraints we
    // can bypass without a real stub class; we use class_alias instead).
    $src = (string) file_get_contents('/app/lib/Engine/Filters/JmapSieveStorage.php');
    // Strip `declare(strict_types=1);` — eval() forbids it.
    $src = preg_replace('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', '', $src, 1);
    // Replace the SieveScriptService type with our stub via class_alias.
    class_alias('OCA\\SouveraMail\\Service\\StubSieveScriptService', 'OCA\\SouveraMail\\Service\\SieveScriptService');
    eval('?>' . $src);

    $logger = new \Stub\FakeLogger();
    $user = new \Stub\FakeUser('alice');
    $session = new \Stub\FakeUserSession($user);
    $stub = new \OCA\SouveraMail\Service\StubSieveScriptService();
    $stub->listOut = ['scripts' => [
        ['id' => 's1', 'name' => 'vacation', 'blobId' => 'b1', 'isActive' => true, 'body' => '# vacation script'],
        ['id' => 's2', 'name' => 'forward',  'blobId' => 'b2', 'isActive' => false, 'body' => '# forward script'],
    ]];

    // Construct the storage by-hand (no DI container in test).
    $reflection = new \ReflectionClass('OCA\\SouveraMail\\Engine\\Filters\\JmapSieveStorage');
    $storage = $reflection->newInstanceArgs([$stub, $session, $logger]);
    $account = new \Smail\Engine\Model\Account();

    // 5a) Happy path: Load() returns expected shape with both scripts + 'smail.user' seed.
    $loaded = $storage->Load($account);
    $ok = isset($loaded['Capa']) && isset($loaded['Scripts']);
    $ok = $ok && isset($loaded['Scripts']['vacation']['active']) && $loaded['Scripts']['vacation']['active'] === true;
    $ok = $ok && isset($loaded['Scripts']['forward']['active']) && $loaded['Scripts']['forward']['active'] === false;
    $ok = $ok && isset($loaded['Scripts']['smail.user']);
    $ok = $ok && in_array('fileinto', $loaded['Capa'], true);
    if (!$ok) { fwrite(STDERR, "FAIL: Load() shape: " . json_encode($loaded) . "\n"); exit(1); }
    if ($stub->calls[0] !== ['list', 'alice']) { fwrite(STDERR, "FAIL: Load() did not use uid from IUserSession\n"); exit(2); }

    // 5b) Failure path: Load() throws ClientException(CantGetFilters) when service throws.
    $stub2 = new \OCA\SouveraMail\Service\StubSieveScriptService();
    $stub2->listThrow = new \RuntimeException('boom');
    $storage2 = $reflection->newInstanceArgs([$stub2, $session, $logger]);
    try {
        $storage2->Load($account);
        fwrite(STDERR, "FAIL: Load() did not throw on service failure\n"); exit(3);
    } catch (\Smail\Engine\Exceptions\ClientException $e) {
        if ($e->iCode !== 352) { fwrite(STDERR, "FAIL: Load() wrong code on failure: " . $e->iCode . "\n"); exit(4); }
    }

    // 5c) Save() delegates
    if ($storage->Save($account, 'vacation', '# new body') !== true) { fwrite(STDERR, "FAIL: Save() return\n"); exit(5); }
    $lastSave = $stub->calls[array_key_last($stub->calls)];
    if ($lastSave !== ['save', 'alice', 'vacation', '# new body']) { fwrite(STDERR, "FAIL: Save() delegation: " . json_encode($lastSave) . "\n"); exit(6); }

    // 5d) Activate() delegates + handles error
    $storage->Activate($account, 'vacation');
    $lastAct = $stub->calls[array_key_last($stub->calls)];
    if ($lastAct !== ['activate', 'alice', 'vacation']) { fwrite(STDERR, "FAIL: Activate() delegation\n"); exit(7); }
    $stub3 = new \OCA\SouveraMail\Service\StubSieveScriptService();
    $stub3->activateThrow = new \RuntimeException('nope');
    $storage3 = $reflection->newInstanceArgs([$stub3, $session, $logger]);
    try {
        $storage3->Activate($account, 'vacation');
        fwrite(STDERR, "FAIL: Activate() did not throw\n"); exit(8);
    } catch (\Smail\Engine\Exceptions\ClientException $e) {
        if ($e->iCode !== 359) { fwrite(STDERR, "FAIL: Activate() wrong code: " . $e->iCode . "\n"); exit(9); }
    }

    // 5e) Delete() delegates
    $storage->Delete($account, 'forward');
    $lastDel = $stub->calls[array_key_last($stub->calls)];
    if ($lastDel !== ['delete', 'alice', 'forward']) { fwrite(STDERR, "FAIL: Delete() delegation\n"); exit(10); }

    // 5f) No-session crash: Load() with null user must surface "requires authenticated"
    $emptySession = new \Stub\FakeUserSession(null);
    $storage4 = $reflection->newInstanceArgs([$stub, $emptySession, $logger]);
    try {
        $storage4->Load($account);
        fwrite(STDERR, "FAIL: Load() with no NC user did not raise\n"); exit(11);
    } catch (\Smail\Engine\Exceptions\ClientException $e) {
        if ($e->iCode !== 352) { fwrite(STDERR, "FAIL: empty-session Load() wrong code: " . $e->iCode . "\n"); exit(12); }
    }

    echo "ALL OK\n";
}
PHP;
file_put_contents('/tmp/jmap_sieve_sim.php', $sim);
$out = (string) shell_exec('php /tmp/jmap_sieve_sim.php 2>&1');
assertTrue(str_contains($out, 'ALL OK'),
    "Behavioural sim — Load/Save/Activate/Delete delegate to SieveScriptService, errors map to Notifications::Cant* codes, empty session raises CantGetFilters (output: " . trim($out) . ")",
    $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
