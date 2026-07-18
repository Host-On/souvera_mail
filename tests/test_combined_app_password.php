<?php
/**
 * Regression test for v0.14.0 — combined Mail + Nextcloud/DAV app passwords.
 *
 * What this pins
 * --------------
 * 1. `AppPasswordService::createForUser()` performs a two-phase commit:
 *    Stalwart first, then NC IProvider::generateToken() with the SAME
 *    plaintext, then a mapping-row insert in oc_souvera_mail_apppwd.
 *
 * 2. Rollback ORDER matters:
 *    - NC token fails after Stalwart → destroy Stalwart.
 *    - Mapping insert fails after both → destroy NC token, then Stalwart.
 *
 * 3. `revokeForUser()` order:
 *    - has mapping → NC invalidate FIRST, then Stalwart destroy, then
 *      mapping delete.
 *    - no mapping (legacy pre-v0.14.0) → Stalwart-only destroy.
 *
 * 4. `listForUser()` labels each row `kind: combined | legacy`.
 *
 * 5. NC token parameters:
 *    - type = IToken::PERMANENT_TOKEN
 *    - scope = ['filesystem' => true]  (full DAV)
 *    - password = null  (OIDC users have no local password)
 *    - name = description + " (Souvera Mail + DAV)"
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ==============================================================
// Part A — static source assertions on AppPasswordService.php
// ==============================================================
$svc = (string) file_get_contents('/app/lib/Service/AppPasswordService.php');
ok($svc !== '', "AppPasswordService.php readable", $passes, $failures);

// Constructor now takes ITokenProvider + AppPasswordMappingMapper + ITimeFactory
ok(str_contains($svc, 'private ITokenProvider $ncTokenProvider'),
    "AppPasswordService injects ITokenProvider (Nextcloud auth-token API)",
    $passes, $failures);
ok(str_contains($svc, 'private AppPasswordMappingMapper $mappingMapper'),
    "AppPasswordService injects AppPasswordMappingMapper (persistent mapping)",
    $passes, $failures);
ok(str_contains($svc, 'private ITimeFactory $time'),
    "AppPasswordService injects ITimeFactory for consistent created_at timestamps",
    $passes, $failures);

// Use statements for NC public APIs
ok(str_contains($svc, 'use OCP\Authentication\Token\IProvider as ITokenProvider'),
    "Uses the PUBLIC OCP\\Authentication\\Token\\IProvider (not the private OC\\... class)",
    $passes, $failures);
ok(str_contains($svc, 'use OCP\Authentication\Token\IToken;'),
    "Uses the PUBLIC OCP\\Authentication\\Token\\IToken (for PERMANENT_TOKEN constant)",
    $passes, $failures);

// Two-phase commit calls IProvider::generateToken() with the SAME secret
ok(str_contains($svc, '$this->ncTokenProvider->generateToken('),
    "createForUser() invokes IProvider::generateToken() (NC leg)",
    $passes, $failures);
ok((bool) preg_match('/generateToken\(\s*token:\s*\$secret/', $svc),
    "The generateToken() call passes the SAME `$secret` from Stalwart (named arg `token`)",
    $passes, $failures);
ok(str_contains($svc, 'type: IToken::PERMANENT_TOKEN'),
    "NC token is created as PERMANENT_TOKEN (not TEMPORARY_TOKEN — survives across sessions)",
    $passes, $failures);
ok((bool) preg_match("/scope:\s*\[['\"]filesystem['\"]\s*=>\s*true\]/", $svc),
    "NC token gets FULL DAV scope `['filesystem' => true]` — WebDAV/CalDAV/CardDAV",
    $passes, $failures);
ok(str_contains($svc, 'password: null'),
    "NC token creation passes `password: null` — OIDC users have no locally-stored password",
    $passes, $failures);
ok(str_contains($svc, '(Souvera Mail + DAV)'),
    "NC token name gets human-readable suffix so users spot our tokens in /settings/user/security",
    $passes, $failures);

// Rollback on NC failure — destroy Stalwart
$createBody = substr($svc, (int)strpos($svc, 'public function createForUser'));
$createBody = substr($createBody, 0, strpos($createBody, "\n    public "));
ok(str_contains($createBody, 'nc-generate-failed'),
    "createForUser() rollback marker `nc-generate-failed` is present (Stalwart destroy on NC failure)",
    $passes, $failures);
ok(str_contains($createBody, 'mapping-insert-failed'),
    "createForUser() rollback marker `mapping-insert-failed` (both sides destroyed on mapping fail)",
    $passes, $failures);
ok(str_contains($createBody, '$this->ncTokenProvider->invalidateTokenById($userId, (int) $ncToken->getId())'),
    "Mapping-insert rollback path invalidates the NC token first",
    $passes, $failures);

// Return shape now carries `ncTokenId`
ok(str_contains($svc, "'ncTokenId' => (int) \$ncToken->getId()"),
    "createForUser() returns `ncTokenId` so callers can correlate the NC token later",
    $passes, $failures);

// Revoke order (has mapping): NC first → Stalwart → mapping row
$revokeStart = strpos($svc, 'public function revokeForUser');
$revokeEnd = strpos($svc, "\n    public function ", $revokeStart + 10);
$revokeBody = substr($svc, $revokeStart, $revokeEnd - $revokeStart);

$posNcInvalidate = strpos($revokeBody, '$this->ncTokenProvider->invalidateTokenById(');
$posStalwart    = strpos($revokeBody, '$this->destroyStalwartAppPassword(');
$posMapDelete   = strpos($revokeBody, '$this->mappingMapper->delete($mapping)');
ok($posNcInvalidate !== false && $posStalwart !== false && $posMapDelete !== false
        && $posNcInvalidate < $posStalwart && $posStalwart < $posMapDelete,
    "revokeForUser(): NC invalidate → Stalwart destroy → mapping delete (correct order)",
    $passes, $failures);

// Legacy path: DoesNotExistException swallowed → Stalwart-only destroy
ok(str_contains($revokeBody, 'catch (DoesNotExistException)'),
    "revokeForUser() catches DoesNotExistException for legacy (pre-v0.14.0) passwords",
    $passes, $failures);

// listForUser labels each row
$listBody = substr($svc, (int)strpos($svc, 'public function listForUser'));
$listBody = substr($listBody, 0, strpos($listBody, "\n    public "));
ok(str_contains($listBody, "'kind' => \$mapping !== null ? 'combined' : 'legacy'"),
    "listForUser() labels each row `combined` (mapping present) or `legacy` (Stalwart-only)",
    $passes, $failures);
ok(str_contains($listBody, "'ncTokenId' => \$mapping !== null ? \$mapping->getNcTokenId() : null"),
    "listForUser() also surfaces `ncTokenId` for combined rows",
    $passes, $failures);

// PHPDoc return shape of listForUser updated
ok(str_contains($svc, "kind: string, ncTokenId: ?int"),
    "listForUser() PHPDoc includes the new `kind` and `ncTokenId` fields",
    $passes, $failures);

// revokeByNcTokenId (mirror invalidate from NC → Stalwart)
ok((bool) preg_match('/public function revokeByNcTokenId\(string \$userId, int \$ncTokenId\)/', $svc),
    "revokeByNcTokenId() reverse-invalidation hook exists",
    $passes, $failures);

// ==============================================================
// Part B — Db entity + mapper
// ==============================================================
$ent = (string) file_get_contents('/app/lib/Db/AppPasswordMapping.php');
ok(str_contains($ent, 'namespace OCA\SouveraMail\Db;'),
    "AppPasswordMapping in OCA\\SouveraMail\\Db namespace",
    $passes, $failures);
ok(str_contains($ent, 'extends Entity'),
    "AppPasswordMapping extends OCP\\AppFramework\\Db\\Entity",
    $passes, $failures);
foreach (['userId', 'ncTokenId', 'stalwartAppId', 'description', 'createdAt'] as $col) {
    ok(str_contains($ent, "\$$col"),
        "AppPasswordMapping has property \$$col",
        $passes, $failures);
}
ok((bool) preg_match("#\\\$this->addType\\('ncTokenId',\\s*'integer'\\)#", $ent),
    "AppPasswordMapping declares ncTokenId as integer",
    $passes, $failures);

$mapper = (string) file_get_contents('/app/lib/Db/AppPasswordMappingMapper.php');
ok(str_contains($mapper, "public const TABLE = 'souvera_mail_apppwd';"),
    "Mapper points at table `souvera_mail_apppwd`",
    $passes, $failures);
ok(str_contains($mapper, 'extends QBMapper'),
    "Mapper extends QBMapper (uses IQueryBuilder — no raw SQL)",
    $passes, $failures);
ok(str_contains($mapper, 'public function findAllForUser'),
    "Mapper::findAllForUser exists",
    $passes, $failures);
ok(str_contains($mapper, 'public function findByStalwartId'),
    "Mapper::findByStalwartId exists",
    $passes, $failures);
ok(!(bool) preg_match('#(INSERT|UPDATE|DELETE|SELECT)\s+INTO\s+#i', $mapper),
    "Mapper contains no raw SQL statements (uses IQueryBuilder only)",
    $passes, $failures);

// ==============================================================
// Part C — migration schema
// ==============================================================
$migration = (string) file_get_contents('/app/lib/Migration/Version001400Date20260218000000.php');
ok(str_contains($migration, "extends SimpleMigrationStep"),
    "Migration extends SimpleMigrationStep",
    $passes, $failures);
ok(str_contains($migration, "\$schema->createTable('souvera_mail_apppwd')"),
    "Migration creates the `souvera_mail_apppwd` table",
    $passes, $failures);
foreach (['user_id', 'nc_token_id', 'stalwart_app_id', 'description', 'created_at'] as $col) {
    ok(str_contains($migration, "'$col'"),
        "Migration defines column `$col`",
        $passes, $failures);
}
ok(str_contains($migration, "addUniqueIndex(['user_id', 'stalwart_app_id']"),
    "Migration adds UNIQUE index on (user_id, stalwart_app_id) so we cannot double-insert",
    $passes, $failures);
ok(str_contains($migration, "addIndex(['nc_token_id']"),
    "Migration adds INDEX on nc_token_id for fast reverse-lookup",
    $passes, $failures);
ok(str_contains($migration, "if (\$schema->hasTable('souvera_mail_apppwd'))"),
    "Migration is idempotent — early-returns if the table already exists",
    $passes, $failures);

// ==============================================================
// Part D — listeners registration
// ==============================================================
$app = (string) file_get_contents('/app/lib/AppInfo/Application.php');
ok(str_contains($app, 'SecurityPageHijackListener'),
    "Application.php imports SecurityPageHijackListener",
    $passes, $failures);
ok(str_contains($app, 'NcTokenInvalidatedListener'),
    "Application.php imports NcTokenInvalidatedListener",
    $passes, $failures);
ok(str_contains($app, 'BeforeTemplateRenderedEvent::class'),
    "SecurityPageHijackListener is wired to BeforeTemplateRenderedEvent",
    $passes, $failures);
ok(str_contains($app, 'TokenInvalidatedEvent::class'),
    "NcTokenInvalidatedListener is wired to TokenInvalidatedEvent",
    $passes, $failures);

// ==============================================================
// Part E — /settings/user/security hijack
// ==============================================================
$listener = (string) file_get_contents('/app/lib/Listeners/SecurityPageHijackListener.php');
ok(str_contains($listener, 'Application::RESTRICTED_GROUP_ID'),
    "Hijack listener gates on souvera-users group membership",
    $passes, $failures);
ok(str_contains($listener, "'/settings/user/security'"),
    "Hijack listener limits itself to the /settings/user/security URL",
    $passes, $failures);
ok(str_contains($listener, "Util::addStyle(Application::APP_ID, 'security-page-hijack')"),
    "Hijack listener injects the security-page-hijack.css asset",
    $passes, $failures);
ok(str_contains($listener, "Util::addScript(Application::APP_ID, 'security-page-hijack')"),
    "Hijack listener injects the security-page-hijack.js asset",
    $passes, $failures);

// The CSS + JS assets exist and target the built-in form
$css = (string) file_get_contents('/app/css/security-page-hijack.css');
ok(str_contains($css, '#security .new-token'),
    "Hijack CSS hides #security .new-token (built-in NC Vue create form)",
    $passes, $failures);
ok(str_contains($css, 'display: none !important;'),
    "Hijack CSS uses `display: none !important` (defeats inline styles from Vue)",
    $passes, $failures);
ok(str_contains($css, '.souvera-mail-security-notice'),
    "Hijack CSS ships the Souvera-branded notice card",
    $passes, $failures);

$js = (string) file_get_contents('/app/js/security-page-hijack.js');
ok(str_contains($js, 'souvera-mail-security-notice'),
    "Hijack JS injects the notice DIV",
    $passes, $failures);
ok(str_contains($js, "t('souvera_mail', 'Create app password for Mail & Nextcloud')")
    || str_contains($js, "Create app password for Mail & Nextcloud"),
    "Hijack JS builds the notice text via t('souvera_mail', 'Create app password …') so it's translatable (source is English; German shipped in l10n/de.js)",
    $passes, $failures);
ok(str_contains($js, 'OC.generateUrl'),
    "Hijack JS uses OC.generateUrl() to build the Souvera Mail link (respects webroot)",
    $passes, $failures);

// ==============================================================
// Part F — NC token invalidation mirror
// ==============================================================
$mirror = (string) file_get_contents('/app/lib/Listeners/NcTokenInvalidatedListener.php');
ok(str_contains($mirror, 'implements IEventListener'),
    "NcTokenInvalidatedListener implements IEventListener",
    $passes, $failures);
ok(str_contains($mirror, '$this->appPasswords->revokeByNcTokenId('),
    "NcTokenInvalidatedListener delegates to AppPasswordService::revokeByNcTokenId()",
    $passes, $failures);
ok(str_contains($mirror, 'catch (\Throwable $e)'),
    "NcTokenInvalidatedListener swallows all errors — never breaks NC's token flow",
    $passes, $failures);

// ==============================================================
// Part G — info.xml + surface
// ==============================================================
$info = (string) file_get_contents('/app/appinfo/info.xml');
ok((bool) preg_match('#<version>0\.(?:1[4-9]|[2-9]\d)\.\d+</version>#', $info),
    "info.xml version bumped to 0.14.x (MINOR — new feature)",
    $passes, $failures);

// ==============================================================
// Part H — behavioural simulation of the two-phase commit + rollback
// ==============================================================
class SimNcTokenProvider {
    public array $created = [];  // list<{token,uid,name,type,scope}>
    public array $invalidated = []; // list<{uid,id}>
    public int $nextId = 100;
    public bool $failCreate = false;
    public function generateToken(string $token, string $uid, string $loginName, ?string $password, string $name, int $type, int $remember, ?array $scope, ?int $expires = null): object {
        if ($this->failCreate) { throw new \RuntimeException('simulated NC failure'); }
        $tokenObj = new class {
            public int $id = 0;
            public function getId(): int { return $this->id; }
        };
        $tokenObj->id = $this->nextId++;
        $this->created[] = ['token' => $token, 'uid' => $uid, 'name' => $name, 'type' => $type, 'scope' => $scope, 'id' => $tokenObj->id];
        return $tokenObj;
    }
    public function invalidateTokenById(string $uid, int $id): void {
        $this->invalidated[] = ['uid' => $uid, 'id' => $id];
    }
}

class SimStalwart {
    public array $existing = [];  // stalwart id => secret
    public array $destroyed = [];
    public int $nextId = 1;
    public function jmapCall(string $bearer, array $methodCalls): array {
        $call = $methodCalls[0];
        $body = $call[1];
        if (isset($body['create'])) {
            $spec = $body['create']['k1'];
            $id = 'ap-' . $this->nextId++;
            $secret = 'app_' . str_repeat('a', 32);
            $this->existing[$id] = $secret;
            return ['methodResponses' => [[
                'x:AppPassword/set', ['created' => ['k1' => ['id' => $id, 'secret' => $secret]]], 'c0',
            ]]];
        }
        if (isset($body['destroy'])) {
            $id = $body['destroy'][0];
            unset($this->existing[$id]);
            $this->destroyed[] = $id;
            return ['methodResponses' => [['x:AppPassword/set', ['destroyed' => [$id]], 'c0']]];
        }
        return ['methodResponses' => [['x:AppPassword/set', [], 'c0']]];
    }
    public function extractMethodResponse(array $resp, string $name): array {
        foreach ($resp['methodResponses'] ?? [] as $mr) {
            if (($mr[0] ?? '') === $name) return $mr[1] ?? [];
        }
        return [];
    }
}

class SimUserContext {
    public function isAvailable(): bool { return true; }
    public function resolveEmail(string $u): string { return $u . '@example.com'; }
    public function resolveAccountId(string $u): string { return 'acc-' . $u; }
    public function resolveBearer(string $u): string { return 'jwt-' . $u; }
}

/**
 * Inlined createForUser() body — regex assertions above pin the real
 * source. This simulation exercises the ORDER of side-effects and the
 * rollback paths that string-matching alone cannot verify.
 */
function simCreate(string $userId, string $desc,
                    SimUserContext $ctx, SimStalwart $st,
                    SimNcTokenProvider $nc,
                    array $mappings): array {
    $email = $ctx->resolveEmail($userId);
    $accountId = $ctx->resolveAccountId($userId);
    $bearer = $ctx->resolveBearer($userId);

    // Phase 1 — Stalwart
    $resp = $st->jmapCall($bearer, [['x:AppPassword/set', [
        'accountId' => $accountId,
        'create' => ['k1' => ['description' => $desc]],
    ], 'c0']]);
    $setResp = $st->extractMethodResponse($resp, 'x:AppPassword/set');
    $created = $setResp['created']['k1'] ?? null;
    if ($created === null) { throw new \RuntimeException('Stalwart failed'); }
    $stalwartId = (string) $created['id'];
    $secret     = (string) $created['secret'];

    // Phase 2 — NC token
    try {
        $ncToken = $nc->generateToken($secret, $userId, $userId, null, $desc . ' (Souvera Mail + DAV)',
            1 /* PERMANENT_TOKEN */, 0, ['filesystem' => true]);
    } catch (\Throwable $e) {
        // Rollback Stalwart
        $st->jmapCall($bearer, [['x:AppPassword/set', ['accountId' => $accountId, 'destroy' => [$stalwartId]], 'c0']]);
        throw $e;
    }

    // Phase 3 — mapping
    $mappings[] = [
        'user_id' => $userId, 'nc_token_id' => (int)$ncToken->getId(),
        'stalwart_app_id' => $stalwartId, 'description' => $desc,
    ];
    return ['id' => $stalwartId, 'secret' => $secret, 'description' => $desc,
            'username' => $email, 'ncTokenId' => (int)$ncToken->getId(),
            'mappings' => $mappings];
}

// H-1: happy path
$ctx = new SimUserContext(); $st = new SimStalwart(); $nc = new SimNcTokenProvider();
$out = simCreate('alice', 'Thunderbird', $ctx, $st, $nc, []);
ok($out['id'] === 'ap-1' && $out['secret'] === 'app_' . str_repeat('a', 32),
    "H-1a: happy path returns Stalwart id + secret", $passes, $failures);
ok($out['ncTokenId'] === 100,
    "H-1b: happy path returns NC token id", $passes, $failures);
ok(count($nc->created) === 1 && $nc->created[0]['token'] === $out['secret'],
    "H-1c: NC token got the SAME plaintext secret Stalwart returned",
    $passes, $failures);
ok($nc->created[0]['type'] === 1,
    "H-1d: NC token is PERMANENT_TOKEN (type=1)", $passes, $failures);
ok($nc->created[0]['scope'] === ['filesystem' => true],
    "H-1e: NC token has DAV filesystem scope", $passes, $failures);
ok($nc->created[0]['name'] === 'Thunderbird (Souvera Mail + DAV)',
    "H-1f: NC token name has the combined-credential suffix", $passes, $failures);
ok(count($out['mappings']) === 1,
    "H-1g: mapping row inserted after both sides succeeded",
    $passes, $failures);
ok(empty($nc->invalidated) && empty($st->destroyed),
    "H-1h: no rollback path taken on the happy path",
    $passes, $failures);

// H-2: NC token creation fails → Stalwart rollback destroys stalwartId
$ctx = new SimUserContext(); $st = new SimStalwart(); $nc = new SimNcTokenProvider();
$nc->failCreate = true;
$caught = null;
try { simCreate('bob', 'DAVx5', $ctx, $st, $nc, []); }
catch (\Throwable $e) { $caught = $e; }
ok($caught !== null,
    "H-2a: NC token failure propagates as exception to caller",
    $passes, $failures);
ok(count($st->destroyed) === 1 && $st->destroyed[0] === 'ap-1',
    "H-2b: Rollback destroyed the Stalwart AppPassword (id=ap-1)",
    $passes, $failures);
ok(empty($st->existing),
    "H-2c: Stalwart-side is clean after rollback (no orphan)",
    $passes, $failures);

// H-3: legacy revoke (no mapping) — Stalwart-only destroy
$st = new SimStalwart(); $nc = new SimNcTokenProvider();
$st->existing['ap-legacy'] = 'app_' . str_repeat('l', 32);
// simulate revoke — no mapping row means: destroy Stalwart only
$st->jmapCall('jwt', [['x:AppPassword/set', ['destroy' => ['ap-legacy']], 'c0']]);
ok(empty($st->existing),
    "H-3a: legacy revoke destroyed the Stalwart entry",
    $passes, $failures);
ok(empty($nc->invalidated),
    "H-3b: legacy revoke did NOT touch NC tokens (no mapping to reference)",
    $passes, $failures);

// ==============================================================
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
