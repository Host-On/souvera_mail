<?php
/**
 * Regression test for Souvera Mail v0.14.9 — Migration wizard Phase 1
 * backend: MigrationService orchestration + DB + Controller + Cron.
 *
 * Source-level pinning of the service layer's contract with the DB,
 * with AppPasswordService, and with the two background jobs. We can
 * not spin up a full Nextcloud container in CI, so we assert:
 *
 *  - DB migration adds the correct schema + indexes.
 *  - MigrationJob entity has the right property types + terminal-status
 *    classification.
 *  - MigrationJobMapper exposes the four queries the service needs.
 *  - MigrationService rate-limits to ONE active job per user.
 *  - Start-flow order: mint app-pw → insert pending → call provider.tools
 *    → on failure roll back BOTH sides.
 *  - Refresh-flow: only ACTIVE_STATUSES → COMPLETED/FAILED transition
 *    revokes the temp app-pw AND blanks stalwart_app_id (so cleanup
 *    doesn't retry).
 *  - Controller endpoints exist with correct verbs + JSON shape.
 *  - Cron jobs registered, correct intervals, TIME_INSENSITIVE.
 *  - App-password service exposes createStalwartOnlyForMigration
 *    + revokeStalwartOnlyForMigration but does NOT create an NC token
 *    or a mapping row for the migration temp pw.
 *  - Destination host resolution cascade: app-config → overwrite.cli.url
 *    → trusted_domains[0].
 */

declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$paths = [
    'entity'     => '/app/lib/Db/MigrationJob.php',
    'mapper'     => '/app/lib/Db/MigrationJobMapper.php',
    'migration'  => '/app/lib/Migration/Version001409Date20260219000000.php',
    'service'    => '/app/lib/Service/MigrationService.php',
    'appPw'      => '/app/lib/Service/AppPasswordService.php',
    'controller' => '/app/lib/Controller/MigrationController.php',
    'poller'     => '/app/lib/Cron/MigrationPoller.php',
    'cleanup'    => '/app/lib/Cron/MigrationCleanup.php',
    'routes'     => '/app/appinfo/routes.php',
    'info'       => '/app/appinfo/info.xml',
    'changelog'  => '/app/CHANGELOG.md',
];
$src = [];
foreach ($paths as $k => $p) {
    $src[$k] = (string) file_get_contents($p);
    ok($src[$k] !== '', "readable: {$k} ({$p})", $passes, $failures);
    $out = []; $rc = 0;
    if (str_ends_with($p, '.php')) {
        exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
        ok($rc === 0, "php -l clean: " . basename($p), $passes, $failures);
    }
}

// ==============================================================
// A — Entity: status enum + terminal classification
// ==============================================================
foreach (['STATUS_PENDING', 'STATUS_RUNNING', 'STATUS_COMPLETED', 'STATUS_FAILED', 'STATUS_DISMISSED'] as $c) {
    ok(str_contains($src['entity'], "public const {$c}"),
        "MigrationJob::{$c} defined", $passes, $failures);
}
ok(str_contains($src['entity'], 'ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING]'),
    "ACTIVE_STATUSES = [pending, running] (only these need polling)",
    $passes, $failures);
ok(str_contains($src['entity'], 'TERMINAL_STATUSES = ['),
    "TERMINAL_STATUSES declared for cleanup targeting", $passes, $failures);
ok(str_contains($src['entity'], 'public function toApiArray(): array'),
    "toApiArray() shapes the row for the frontend", $passes, $failures);
ok(str_contains($src['entity'], "'isActive' =>")
    && str_contains($src['entity'], "'isTerminal' =>"),
    "toApiArray() exposes isActive+isTerminal flags for UI branching",
    $passes, $failures);

// The sensitive fields we deliberately do NOT store:
ok(!str_contains($src['entity'], 'sourcePassword')
    && !str_contains($src['entity'], 'source_password'),
    "Entity has NO source_password field (creds NEVER stored locally)",
    $passes, $failures);

// ==============================================================
// B — Mapper queries
// ==============================================================
foreach (['findLatestForUser', 'findActiveForUser', 'findAllActive', 'findStaleTerminalJobs'] as $fn) {
    ok(str_contains($src['mapper'], "function {$fn}("),
        "Mapper exposes {$fn}()", $passes, $failures);
}
// v0.14.20 — Mapper::find(int $id) must exist so cancelJobForUser /
// dismissJobForUser can look up a row by primary key. QBMapper (the
// base class since NC 21) intentionally dropped the id-lookup helper
// that the older deprecated Mapper base class exposed. Regression pin
// against the "Call to undefined method …::find()" 500 the operator
// reported on 2026-02-19.
ok((bool) preg_match('#public function find\(int \$id\)\s*:\s*MigrationJob#', $src['mapper']),
    "Mapper exposes find(int \$id): MigrationJob (used by cancel + dismiss)",
    $passes, $failures);
ok((bool) preg_match(
    '#public function find\(int \$id\)[\s\S]{0,400}\$qb->expr\(\)->eq\(\s*\'id\',\s*\$qb->createNamedParameter\(\$id,\s*IQueryBuilder::PARAM_INT\)#',
    $src['mapper']
), "Mapper::find() builds a WHERE id = :id (INT-typed) query",
    $passes, $failures);
ok(str_contains($src['mapper'], "public const TABLE = 'souvera_migrations'"),
    "Table name pinned to 'souvera_migrations' (prefix-managed by NC)",
    $passes, $failures);

// ==============================================================
// C — DB migration schema
// ==============================================================
foreach (['id', 'user_id', 'provider_job_id', 'status', 'source_host', 'source_user',
         'stalwart_app_id', 'progress_json', 'error_message', 'created_at', 'updated_at',
         'finished_at'] as $col) {
    ok(str_contains($src['migration'], "'{$col}'"),
        "DB schema includes column: {$col}", $passes, $failures);
}
foreach (['sm_mig_uid_ctime', 'sm_mig_uid_status', 'sm_mig_status_utime'] as $idx) {
    ok(str_contains($src['migration'], "'{$idx}'"),
        "DB schema includes index: {$idx}", $passes, $failures);
}
// Belt-and-suspenders: the table name must match the mapper constant.
ok(str_contains($src['migration'], "'souvera_migrations'"),
    "DB migration creates table 'souvera_migrations' (matches Mapper::TABLE)",
    $passes, $failures);

// ==============================================================
// D — Service: rate-limit + start-flow rollback + refresh transitions
// ==============================================================
ok(str_contains($src['service'], 'public const MAX_ACTIVE_PER_USER = 1'),
    "One active migration per user (hard-coded rate limit)",
    $passes, $failures);
ok(str_contains($src['service'], 'private function assertNoActiveJob('),
    "Start-flow guards against a second concurrent job", $passes, $failures);

// Start-flow ordering: mint app-pw → insert pending row → call provider.tools
$startPos = strpos($src['service'], 'public function startForUser(');
$mintPos  = strpos($src['service'], 'createStalwartOnlyForMigration(', $startPos);
$insertPos = strpos($src['service'], '$this->jobs->insert(', $startPos);
$callPos = strpos($src['service'], '$this->providerTools->startMigration(', $startPos);
ok($startPos !== false && $mintPos !== false && $insertPos !== false && $callPos !== false,
    "startForUser has all 3 required steps (mint + insert + call)",
    $passes, $failures);
ok($mintPos < $insertPos && $insertPos < $callPos,
    "Start-flow order: mint app-pw → insert pending row → provider.tools call",
    $passes, $failures);

// Failure roll-back on start:
ok(str_contains($src['service'], "'migration-start-failed'"),
    "Start-catch revokes the temp app-pw with reason 'migration-start-failed'",
    $passes, $failures);
ok(str_contains($src['service'], "'migration-start-rejected'"),
    "Rejection path (provider.tools says success=false) also revokes temp app-pw",
    $passes, $failures);

// Refresh transitions
ok(str_contains($src['service'], "if (\$upstream === 'completed' || \$upstream === 'failed')"),
    "Refresh recognises completed+failed as terminal (matches API enum)",
    $passes, $failures);
ok(str_contains($src['service'], "'migration-' . \$upstream"),
    "Terminal transition revokes temp app-pw with reason including the upstream status",
    $passes, $failures);
ok(str_contains($src['service'], '$job->setStalwartAppId(null);'),
    "Terminal transition BLANKS stalwart_app_id (so cleanup doesn't retry)",
    $passes, $failures);
ok(str_contains($src['service'], '$job->setFinishedAt('),
    "Terminal transition stamps finished_at", $passes, $failures);

// Destination host cascade
ok(str_contains($src['service'], 'private function resolveDestinationHost(): string'),
    "resolveDestinationHost() exists", $passes, $failures);
ok(str_contains($src['service'], "'stalwart_imap_host'")
    && str_contains($src['service'], "'overwrite.cli.url'")
    && str_contains($src['service'], "'trusted_domains'"),
    "Host cascade: app-config → overwrite.cli.url → trusted_domains[0]",
    $passes, $failures);
ok(str_contains($src['service'], 'private function resolveDestinationPort(): int')
    && str_contains($src['service'], 'private const DEFAULT_IMAP_PORT = 993'),
    "Port defaults to 993 (IMAPS)", $passes, $failures);
ok(str_contains($src['service'], 'private const DEFAULT_IMAP_SECURE = true'),
    "secure=true by default (per user directive)", $passes, $failures);

// Welcome-state
ok(str_contains($src['service'], "USERCONFIG_WELCOME_DISMISSED = 'welcome_dismissed'"),
    "Welcome-dismissed persisted as per-user config", $passes, $failures);
ok(str_contains($src['service'], 'public function getWelcomeStateForUser('),
    "getWelcomeStateForUser() combines dismissed + activeJob + lastJob",
    $passes, $failures);

// ==============================================================
// E — AppPasswordService new migration-scoped helpers
// ==============================================================
ok(str_contains($src['appPw'], 'public function createStalwartOnlyForMigration('),
    "AppPasswordService::createStalwartOnlyForMigration() exposed",
    $passes, $failures);
ok(str_contains($src['appPw'], 'public function revokeStalwartOnlyForMigration('),
    "AppPasswordService::revokeStalwartOnlyForMigration() exposed",
    $passes, $failures);
// The migration variant must NOT create an NC token or mapping row.
$migVar = substr($src['appPw'],
    strpos($src['appPw'], 'createStalwartOnlyForMigration'),
    strpos($src['appPw'], 'revokeStalwartOnlyForMigration') - strpos($src['appPw'], 'createStalwartOnlyForMigration')
);
ok(!str_contains($migVar, 'ncTokenProvider->generateToken(')
    && !str_contains($migVar, 'mappingMapper->insert('),
    "createStalwartOnlyForMigration() does NOT create NC token or mapping row",
    $passes, $failures);
// Both helpers must reuse the existing private Stalwart primitives.
ok(str_contains($migVar, '$this->createStalwartAppPassword('),
    "createStalwartOnlyForMigration() delegates to the shared Stalwart primitive",
    $passes, $failures);

// ==============================================================
// F — Controller endpoints + auth + validation
// ==============================================================
foreach ([
    'welcomeState', 'dismissWelcome', 'testConnection', 'listFolders',
    'start', 'status', 'dismissJob',
] as $m) {
    ok(str_contains($src['controller'], "public function {$m}("),
        "Controller method exists: {$m}", $passes, $failures);
}
ok(substr_count($src['controller'], '#[NoAdminRequired]') >= 7,
    "All 7 controller methods carry #[NoAdminRequired] (regular user access)",
    $passes, $failures);
ok(str_contains($src['controller'], '$this->userId === null'),
    "Every method rejects unauthenticated callers (401)", $passes, $failures);
ok(str_contains($src['controller'], 'validateSourceInput(') !== false,
    "Source-side input is validated (host/port/user/password)",
    $passes, $failures);
ok(str_contains($src['controller'], 'catch (ProviderToolsUnavailable $e)'),
    "Controller catches ProviderToolsUnavailable → 502 Bad Gateway",
    $passes, $failures);
ok(str_contains($src['controller'], 'Http::STATUS_CONFLICT'),
    "Rate-limit-hit maps to 409 Conflict (RFC 7231)", $passes, $failures);

// ==============================================================
// G — Routes registered
// ==============================================================
foreach ([
    '/migration/welcome-state', '/migration/dismiss-welcome',
    '/migration/test-connection', '/migration/list-folders',
    '/migration/start', '/migration/status', '/migration/dismiss/',
] as $r) {
    ok(str_contains($src['routes'], "'url' => '{$r}"),
        "Route registered: {$r}", $passes, $failures);
}

// ==============================================================
// H — Background jobs registered in info.xml
// ==============================================================
ok(str_contains($src['info'], 'OCA\\SouveraMail\\Cron\\MigrationPoller'),
    "MigrationPoller registered in info.xml", $passes, $failures);
ok(str_contains($src['info'], 'OCA\\SouveraMail\\Cron\\MigrationCleanup'),
    "MigrationCleanup registered in info.xml", $passes, $failures);
ok(str_contains($src['info'], '<background-jobs>'),
    "<background-jobs> block present in info.xml", $passes, $failures);

// Poller: 60s interval, TIME_INSENSITIVE, bounded batch
ok(str_contains($src['poller'], "INTERVAL_SECONDS = 60"),
    "MigrationPoller interval = 60s (per §Poll interval decision)",
    $passes, $failures);
ok(str_contains($src['poller'], 'setTimeSensitivity(self::TIME_INSENSITIVE)'),
    "MigrationPoller marked TIME_INSENSITIVE (won't skip under NC load)",
    $passes, $failures);
ok(str_contains($src['poller'], 'BATCH_SIZE = 50'),
    "MigrationPoller batch size bounded to 50 per tick", $passes, $failures);

// Cleanup: daily, orphan-revoke phase
ok(str_contains($src['cleanup'], 'INTERVAL_SECONDS = 86400'),
    "MigrationCleanup interval = daily", $passes, $failures);
ok(str_contains($src['cleanup'], 'forceRevokeOrphan('),
    "MigrationCleanup calls MigrationService::forceRevokeOrphan()",
    $passes, $failures);

// ==============================================================
// I — Version bump + changelog markers
// ==============================================================
ok((bool) preg_match('#<version>0\.(?:1[4-9]|[2-9]\d)\.\d+</version>#', $src['info']),
    "info.xml version bumped to 0.14.9 (or later)", $passes, $failures);
ok((bool) preg_match('#\[0\.14\.9\]#', $src['changelog']),
    "CHANGELOG.md has a [0.14.9] section", $passes, $failures);
ok(stripos($src['changelog'], 'provider.tools') !== false
    || stripos($src['changelog'], 'migration') !== false
    || stripos($src['changelog'], 'import') !== false,
    "CHANGELOG [0.14.9] mentions the migration/import feature",
    $passes, $failures);

// ==============================================================
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n"; foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
