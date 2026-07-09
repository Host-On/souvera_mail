<?php
/**
 * Regression test for Souvera Mail v0.14.11 — Vue 3 rewrite of the
 * Migration Wizard (Souvera Design System).
 *
 * Every UI element that was previously a hand-written vanilla-JS
 * overlay lives now in `src/**.vue` + `src/composables/*.js` and is
 * compiled into a single Webpack bundle at
 * `js/souvera_mail-migration-wizard.js`.
 *
 * This suite is a source-shape test that pins:
 *   - the Vue source tree exists and is complete
 *   - the composable exposes every API endpoint the backend defines
 *   - the CSRF header + password-wipe + 5s poll contracts still hold
 *   - `@nextcloud/vue` v9 components are imported via the new
 *     `components/NcXxx` path (not the legacy `dist/Components/*.js`)
 *   - the Souvera Design System tokens (`--sc-*`) are loaded once via
 *     src/styles/forms.css
 *   - Webpack has built the bundle and PageController loads it
 *   - Legacy vanilla-JS assets are gone from disk
 *   - Bridge hardening (from v0.14.10) is still in place
 *   - Version bump + CHANGELOG marker
 */

declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$paths = [
    'pkg'          => '/app/package.json',
    'webpack'      => '/app/webpack.config.js',
    'forms'        => '/app/src/styles/forms.css',
    'main'         => '/app/src/main.js',
    'app'          => '/app/src/App.vue',
    'composable'   => '/app/src/composables/useMigration.js',
    'pill'         => '/app/src/components/MigrationPill.vue',
    'wizard'       => '/app/src/components/MigrationWizard.vue',
    'welcome'      => '/app/src/components/screens/WelcomeScreen.vue',
    'form'         => '/app/src/components/screens/ImapFormScreen.vue',
    'confirm'      => '/app/src/components/screens/ConfirmScreen.vue',
    'progress'     => '/app/src/components/screens/ProgressScreen.vue',
    'terminal'     => '/app/src/components/screens/TerminalScreen.vue',
    'bundle'       => '/app/js/souvera_mail-migration-wizard.js',
    'controller'   => '/app/lib/Controller/PageController.php',
    'bridge'       => '/app/lib-bridge/Souvera_mail/AppInfo/Application.php',
    'info'         => '/app/appinfo/info.xml',
    'changelog'    => '/app/CHANGELOG.md',
];

$src = [];
foreach ($paths as $k => $p) {
    $src[$k] = (string) @file_get_contents($p);
    ok($src[$k] !== '', "readable: {$k} ({$p})", $passes, $failures);
}

// ==============================================================
// A — package.json declares Vue 3 + @nextcloud/vue v9 stack
// ==============================================================
$pkg = json_decode($src['pkg'], true) ?: [];
ok(($pkg['version'] ?? '') === ($xmlVer = (preg_match('#<version>([\d.]+)</version>#', $src['info'], $m) ? $m[1] : '0')),
    "package.json version matches info.xml (both {$xmlVer})", $passes, $failures);
ok(preg_match('/^\^3\.[0-9]+\./', $pkg['dependencies']['vue'] ?? '') === 1,
    'package.json pins vue ^3.x', $passes, $failures);
ok(preg_match('/^\^9\.[0-9]+\./', $pkg['dependencies']['@nextcloud/vue'] ?? '') === 1,
    'package.json pins @nextcloud/vue ^9.x', $passes, $failures);
ok(isset($pkg['dependencies']['vue-material-design-icons']),
    'package.json pulls in vue-material-design-icons (per Souvera Design System §8)',
    $passes, $failures);
ok(isset($pkg['scripts']['build']) && str_contains($pkg['scripts']['build'], 'webpack'),
    'yarn build runs webpack in production mode', $passes, $failures);

// ==============================================================
// B — Souvera Design System tokens exist ONCE in forms.css
// ==============================================================
foreach ([
    '--sc-control-height: 44px',
    '--sc-control-radius: var(--border-radius-large',
    '--sc-field-gap: 24px',
    '--sc-section-gap: 40px',
    '--sc-focus-ring:',
] as $needle) {
    ok(str_contains($src['forms'], $needle),
        "forms.css defines Souvera token `{$needle}`", $passes, $failures);
}
ok(str_contains($src['forms'], '.souvera-content'),
    'forms.css exposes the mandatory .souvera-content wrapper (Design System §2)',
    $passes, $failures);

// ==============================================================
// C — main.js mounts on #souvera-mail-migration-mount and imports
//     the design-system stylesheet exactly once
// ==============================================================
ok(str_contains($src['main'], "id = 'souvera-mail-migration-mount'"),
    'main.js creates the Vue mount point #souvera-mail-migration-mount',
    $passes, $failures);
ok(str_contains($src['main'], "import './styles/forms.css'"),
    'main.js imports the Souvera Design System stylesheet', $passes, $failures);
ok(str_contains($src['main'], "createApp(App)"),
    'main.js uses Vue 3 `createApp()` API (not new Vue())', $passes, $failures);

// ==============================================================
// D — every screen component is registered in MigrationWizard
// ==============================================================
foreach ([
    'WelcomeScreen',
    'ImapFormScreen',
    'FolderMappingScreen',
    'ConfirmScreen',
    'ProgressScreen',
    'TerminalScreen',
] as $screen) {
    ok(str_contains($src['wizard'], $screen),
        "MigrationWizard registers the {$screen} component", $passes, $failures);
}

// v0.14.14 — the wizard MUST insert a `mapping` step between `form`
// and `confirm`, because provider.tools 2026-02 now demands a
// non-empty `folders` array in POST /start.
ok(str_contains($src['wizard'], "'mapping'"),
    "MigrationWizard defines a 'mapping' step (folder picker)",
    $passes, $failures);
ok(str_contains($src['wizard'], "selectedFolders"),
    'Wizard tracks selectedFolders (Set) as import scope',
    $passes, $failures);
ok(str_contains($src['wizard'], "startMigration(conn, folders)"),
    'Wizard forwards `folders` array to composable.startMigration()',
    $passes, $failures);
ok(file_exists('/app/src/components/screens/FolderMappingScreen.vue'),
    'FolderMappingScreen.vue exists', $passes, $failures);

// v0.14.13 — onAdvance must catch testConnection() network errors
// and surface them via testResult, else the wizard freezes silently
// after a brief loading spinner (operator bug report 2026-02-19).
ok((bool) preg_match('#let\s+t1[\s\S]{0,200}try\s*\{[\s\S]{0,200}testConnection#s', $src['wizard'])
    || (bool) preg_match('#try\s*\{[\s\S]{0,200}await\s+.*testConnection#s', $src['wizard']),
    'onAdvance wraps testConnection() in a try/catch (network errors go to testResult)',
    $passes, $failures);
ok(substr_count($src['wizard'], 'testResult.value = { ok: false') >= 2,
    'onAdvance sets testResult={ok:false} for both HTTP-throw and logical-fail paths',
    $passes, $failures);

// ==============================================================
// E — Composable exposes every backend endpoint + safety contract
// ==============================================================
foreach ([
    "/migration/welcome-state",
    "/migration/dismiss-welcome",
    "/migration/test-connection",
    "/migration/list-folders",
    "/migration/start",
    "/migration/status",
    "/migration/dismiss/",
] as $needle) {
    ok(str_contains($src['composable'], $needle),
        "useMigration composable calls {$needle}", $passes, $failures);
}
ok(str_contains($src['composable'], "generateUrl("),
    'useMigration uses @nextcloud/router generateUrl (respects NC subpath routing)',
    $passes, $failures);
ok(str_contains($src['composable'], "requesttoken: token"),
    'useMigration sets the NC requesttoken header on every request (CSRF)',
    $passes, $failures);
ok(str_contains($src['composable'], "credentials: 'same-origin'"),
    'useMigration sends session cookies with each request',
    $passes, $failures);
ok(str_contains($src['composable'], "'completed'")
    && str_contains($src['composable'], "'failed'"),
    'Terminal-state enum includes completed + failed', $passes, $failures);

// v0.14.13 — Composable ↔ Backend contract alignment.
// Backend (MigrationController.php) expects `user`/`secure` (not
// `username`/`tls`), and wraps all IMAP-check responses as
// `{status:'ok', result:{...}}`. Frontend keys stay ergonomic
// (`username`, `tls`) — the composable maps them.
ok(str_contains($src['composable'], "toBackendConn"),
    "Composable owns the UI→backend key mapping in ONE place (toBackendConn)",
    $passes, $failures);
ok(str_contains($src['composable'], "user: uiConn.username")
    || str_contains($src['composable'], "user: uiConn.username || uiConn.user"),
    'toBackendConn maps `username` → `user` (backend contract)',
    $passes, $failures);
ok(str_contains($src['composable'], "secure: !!("),
    'toBackendConn maps `tls` → `secure` (backend contract)',
    $passes, $failures);
ok(str_contains($src['composable'], "body?.state"),
    "loadState() reads body.state.* (backend wraps in .state)",
    $passes, $failures);
ok(str_contains($src['composable'], "body?.result")
    || str_contains($src['composable'], "body?.result || {}"),
    "testConnection/listFolders unwrap body.result (backend contract)",
    $passes, $failures);
ok(str_contains($src['composable'], "body?.active")
    && str_contains($src['composable'], "body?.latest"),
    "loadStatus reads body.active || body.latest (not body.job)",
    $passes, $failures);
ok(str_contains($src['composable'], "welcomeDismissed"),
    "loadState uses `welcomeDismissed` field name (backend contract)",
    $passes, $failures);

// v0.14.14 — startMigration MUST forward the folders selection.
ok((bool) preg_match('#startMigration\s*\(\s*uiConn\s*,\s*folders#', $src['composable']),
    'Composable.startMigration accepts a `folders` array (provider.tools 2026-02 contract)',
    $passes, $failures);
ok(str_contains($src['composable'], 'folders }'),
    'startMigration body includes the folders array',
    $passes, $failures);

// v0.14.15 — Frontend reads the ACTUAL MigrationJob::toApiArray()
// shape, not the invented one from v0.14.11:
//   status  (top-level, camelCase, NOT `.state`)
//   progress.progress.{messagesDone, messagesTotal, foldersDone, foldersTotal}
//   progress.queue.{position, totalInQueue}
ok(str_contains($src['composable'], "status.value?.status"),
    'jobState computed reads status.value?.status (top-level, matches backend)',
    $passes, $failures);
$progressSrc = (string) @file_get_contents('/app/src/components/screens/ProgressScreen.vue');
$terminalSrc = (string) @file_get_contents('/app/src/components/screens/TerminalScreen.vue');
ok(str_contains($progressSrc, 'job.value?.progress?.progress')
    && str_contains($progressSrc, 'job.value?.progress?.queue'),
    'ProgressScreen reads the nested progress.progress and progress.queue blocks',
    $passes, $failures);
foreach (['messagesDone', 'messagesTotal', 'foldersDone', 'foldersTotal'] as $k) {
    ok(str_contains($progressSrc, $k),
        "ProgressScreen reads camelCase `{$k}` (backend field name)",
        $passes, $failures);
}
ok(!preg_match('/[.\[\'"](messages_total|folders_total|queue_position)[\'"\]]/', $progressSrc),
    'ProgressScreen no longer uses invented snake_case field names',
    $passes, $failures);
ok(str_contains($terminalSrc, 'status ||')
    || str_contains($terminalSrc, 's.value.status'),
    'TerminalScreen reads job.status (top-level, camelCase)',
    $passes, $failures);
ok(!str_contains($terminalSrc, 'messages_done')
    && !str_contains($terminalSrc, 'folders_done'),
    'TerminalScreen no longer uses invented snake_case field names',
    $passes, $failures);

// v0.14.15 — /status must trigger an on-demand refresh from
// provider.tools when the cached row is stale (>=10s), so the
// wizard doesn't sit on "Warteschlange…" for up to 60 seconds
// waiting on the MigrationPoller cron.
$controllerSrc = (string) file_get_contents('/app/lib/Controller/MigrationController.php');
$serviceSrc    = (string) file_get_contents('/app/lib/Service/MigrationService.php');
ok(str_contains($controllerSrc, 'refreshFromProvider')
    && str_contains($controllerSrc, 'findActiveJobForUser'),
    'MigrationController::status() calls refreshFromProvider on stale active rows',
    $passes, $failures);
ok((bool) preg_match('#\$ageSec\s*=\s*\\\\?time\(\)\s*-\s*\(int\)\s*\$jobRow->getUpdatedAt#', $controllerSrc),
    'MigrationController::status() checks row age before refreshing',
    $passes, $failures);
ok(str_contains($serviceSrc, 'public function findActiveJobForUser'),
    'MigrationService exposes findActiveJobForUser() (entity variant) for the controller',
    $passes, $failures);

// v0.14.16 — user-initiated cancel while job is in the provider.tools queue
$jobEntitySrc = (string) file_get_contents('/app/lib/Db/MigrationJob.php');
$routesSrc    = (string) file_get_contents('/app/appinfo/routes.php');
$progressSrc2 = (string) file_get_contents('/app/src/components/screens/ProgressScreen.vue');
$composableSrc2 = (string) file_get_contents('/app/src/composables/useMigration.js');

ok(str_contains($jobEntitySrc, "STATUS_CANCELLED  = 'cancelled'"),
    'MigrationJob defines STATUS_CANCELLED', $passes, $failures);
ok(str_contains($jobEntitySrc, 'self::STATUS_CANCELLED,'),
    'STATUS_CANCELLED is in TERMINAL_STATUSES', $passes, $failures);

ok(str_contains($serviceSrc, 'public function cancelJobForUser'),
    'MigrationService exposes cancelJobForUser($userId, $jobId)',
    $passes, $failures);
ok(str_contains($serviceSrc, "!== MigrationJob::STATUS_PENDING"),
    'cancelJobForUser refuses to cancel a non-pending row',
    $passes, $failures);
ok(str_contains($serviceSrc, "revokeStalwartOnlyForMigration")
    && (bool) preg_match('#function cancelJobForUser[\s\S]{0,3000}revokeStalwartOnlyForMigration#', $serviceSrc),
    'cancelJobForUser revokes the Stalwart temp app-password',
    $passes, $failures);

ok(str_contains($controllerSrc, 'public function cancelJob(int $jobId'),
    'MigrationController exposes cancelJob endpoint', $passes, $failures);
ok((bool) preg_match('#STATUS_CONFLICT#', $controllerSrc),
    'cancelJob returns 409 CONFLICT when the job has already left pending',
    $passes, $failures);
ok(str_contains($routesSrc, "'migration#cancelJob'")
    && str_contains($routesSrc, "'url' => '/migration/cancel/{jobId}'"),
    'routes.php registers POST /migration/cancel/{jobId}',
    $passes, $failures);

ok(str_contains($composableSrc2, 'cancelActiveJob'),
    'Composable exposes cancelActiveJob(jobId)', $passes, $failures);
ok((bool) preg_match('#/migration/cancel/\$\{jobId\}#', $composableSrc2),
    'Composable POSTs to /migration/cancel/{jobId}', $passes, $failures);

ok(str_contains($progressSrc2, "state.value === 'pending'"),
    'ProgressScreen only shows Cancel button while state === pending',
    $passes, $failures);
ok(str_contains($progressSrc2, 'cancelActiveJob'),
    'ProgressScreen calls migration.cancelActiveJob on confirm',
    $passes, $failures);
ok(str_contains($progressSrc2, 'showConfirm'),
    'ProgressScreen shows a Confirm dialog before cancelling',
    $passes, $failures);

// v0.14.14 — Backend (MigrationController.start) MUST accept and
// forward `folders`; MigrationService.startForUser signature MUST
// carry it into ProviderToolsClient::startMigration.
$controllerSrc = (string) file_get_contents('/app/lib/Controller/MigrationController.php');
$serviceSrc    = (string) file_get_contents('/app/lib/Service/MigrationService.php');
ok((bool) preg_match('#public function start\([\s\S]{0,400}array \$folders\s*=\s*\[\]#', $controllerSrc),
    'MigrationController::start() accepts `array $folders = []` from the request',
    $passes, $failures);
ok(str_contains($controllerSrc, 'mindestens einen Ordner')
    || str_contains($controllerSrc, 'non-empty array'),
    'MigrationController::start() rejects empty folder list with a friendly message',
    $passes, $failures);
ok((bool) preg_match('#startForUser\([\s\S]{0,600}array \$folders#', $serviceSrc),
    'MigrationService::startForUser() carries the folders parameter',
    $passes, $failures);
ok(str_contains($serviceSrc, 'startMigration($source, $destination, $folders)'),
    'MigrationService forwards folders to ProviderToolsClient::startMigration',
    $passes, $failures);

// v0.14.14 — FolderMappingScreen has the intelligent auto-select
// logic (ROLE_ALIASES with INBOX/Sent/Drafts/Trash/Junk/Archive).
$mappingSrc = (string) @file_get_contents('/app/src/components/screens/FolderMappingScreen.vue');
foreach (['inbox', 'sent', 'drafts', 'trash', 'junk', 'archive'] as $role) {
    ok(str_contains($mappingSrc, "'{$role}':")
        || str_contains($mappingSrc, "{$role}:"),
        "FolderMappingScreen ROLE_ALIASES defines the '{$role}' role",
        $passes, $failures);
}
ok(str_contains($mappingSrc, "isSystemFolder"),
    'FolderMappingScreen has isSystemFolder() to de-select [Gmail]/.dotfolders',
    $passes, $failures);
ok(str_contains($mappingSrc, 'selectRecommended')
    && str_contains($mappingSrc, 'selectAll')
    && str_contains($mappingSrc, 'selectNone'),
    'FolderMappingScreen exposes Empfohlene / Alle / Keine toolbar actions',
    $passes, $failures);

// ==============================================================
// F — Poll cadence matches backend cache (5s frontend / 60s poller)
// ==============================================================
ok(str_contains($src['composable'], 'startPolling(5000)')
    || str_contains($src['app'], 'startPolling(5000)')
    || str_contains($src['wizard'], 'startPolling(5000)'),
    'Frontend polls every 5s (backend MigrationPoller cron: 60s)',
    $passes, $failures);

// ==============================================================
// G — Password wiped from memory immediately after /start returns
// ==============================================================
ok((bool) preg_match('#await\s+.*startMigration.*form\.password\s*=\s*\'\';?#s', $src['wizard']),
    "Wizard wipes form.password to '' immediately after startMigration()",
    $passes, $failures);

// ==============================================================
// H — @nextcloud/vue v9 uses the new import path per Design System
// ==============================================================
$vueFiles = [$src['wizard'], $src['welcome'], $src['form'], $src['confirm'], $src['progress'], $src['terminal']];
foreach ($vueFiles as $idx => $body) {
    // Must NOT reference the legacy dist/Components/*.js path.
    ok(!str_contains($body, '@nextcloud/vue/dist/Components/'),
        "Vue file #{$idx} does not use the legacy dist/Components import path",
        $passes, $failures);
}
ok(str_contains($src['welcome'], "from '@nextcloud/vue/components/NcButton'"),
    "WelcomeScreen imports NcButton via the new @nextcloud/vue/components/NcXxx path",
    $passes, $failures);

// ==============================================================
// I — Persistent floating pill (from user directive v0.14.10 kept)
// ==============================================================
ok(str_contains($src['app'], 'MigrationPill'),
    'App.vue mounts the persistent MigrationPill component', $passes, $failures);
ok(str_contains($src['pill'], "data-state=\"idle\"")
    || str_contains($src['pill'], ':data-state="state"'),
    'MigrationPill exposes data-state for pulse/color styling', $passes, $failures);
ok(str_contains($src['pill'], "souvera-migration-pill--running"),
    'MigrationPill applies the "running" state class during a live migration',
    $passes, $failures);
ok(str_contains($src['pill'], "var(--color-primary-element)")
    && str_contains($src['pill'], "var(--color-success)"),
    'MigrationPill colours come from NC theme variables (never hardcoded hex)',
    $passes, $failures);

// ==============================================================
// J — No hardcoded provider presets (per v0.14.10 user directive)
// ==============================================================
foreach (['imap.gmail.com', 'imap.gmx.net', 'imap.web.de'] as $badPreset) {
    foreach ($vueFiles as $idx => $body) {
        ok(!str_contains($body, $badPreset),
            "Vue file #{$idx} contains no hardcoded preset for {$badPreset}",
            $passes, $failures);
    }
}

// ==============================================================
// K — Confirm-screen tells the user the import cannot be cancelled
// ==============================================================
ok(stripos($src['confirm'], 'nicht abgebrochen') !== false,
    'ConfirmScreen tells users the import cannot be cancelled',
    $passes, $failures);

// ==============================================================
// L — Webpack bundle exists and PageController loads it
// ==============================================================
ok(strlen($src['bundle']) > 100000,
    'Webpack bundle exists and is >100KB (Vue + @nextcloud/vue + components)',
    $passes, $failures);
ok(str_contains($src['controller'],
    "\\OCP\\Util::addScript('souvera_mail', 'souvera_mail-migration-wizard')"),
    "PageController loads the new Vue bundle via addScript('souvera_mail-migration-wizard')",
    $passes, $failures);
// Legacy vanilla-JS asset registration must be gone
ok(!str_contains($src['controller'], "addStyle('souvera_mail', 'migration-wizard')"),
    'PageController no longer references css/migration-wizard.css',
    $passes, $failures);
ok(!str_contains($src['controller'], "addScript('souvera_mail', 'migration-wizard')"),
    'PageController no longer references js/migration-wizard.js',
    $passes, $failures);

// ==============================================================
// M — Legacy vanilla-JS/CSS files removed from disk
// ==============================================================
ok(!file_exists('/app/js/migration-wizard.js'),
    'Legacy /app/js/migration-wizard.js has been deleted', $passes, $failures);
ok(!file_exists('/app/css/migration-wizard.css'),
    'Legacy /app/css/migration-wizard.css has been deleted', $passes, $failures);

// ==============================================================
// N — php -l on PageController + bridge
// ==============================================================
foreach (['controller', 'bridge'] as $k) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($paths[$k]) . ' 2>&1', $out, $rc);
    ok($rc === 0, "php -l clean on " . basename($paths[$k]), $passes, $failures);
}

// ==============================================================
// O — Bridge hardening (from v0.14.10) still intact
// ==============================================================
ok(str_contains($src['bridge'], "require_once \$vendorAutoload;"),
    'Bridge Application still require_once s vendor/autoload.php',
    $passes, $failures);
$nsPos = strpos($src['bridge'], 'namespace OCA\\Souvera_mail');
$reqPos = strpos($src['bridge'], 'require_once $vendorAutoload;');
ok($nsPos !== false && $reqPos !== false && $nsPos < $reqPos,
    'require_once still sits AFTER the namespace declaration',
    $passes, $failures);

// ==============================================================
// P — version bump + changelog markers
// ==============================================================
ok((bool) preg_match('#<version>0\.14\.(19|[2-9]\d|\d{3,})</version>#', $src['info']),
    'info.xml version bumped to 0.14.19 (or later)', $passes, $failures);
ok(str_contains($src['changelog'], '[0.14.19]'),
    'CHANGELOG.md has a [0.14.19] section', $passes, $failures);

// ==============================================================
// R — v0.14.19: "Postfach neu synchronisieren" in Snappymail-Dropdown
// ==============================================================
$dropdownJs2  = (string) @file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/dropdown-menu.js');
$resyncDialog = (string) @file_get_contents('/app/src/components/ResyncDialog.vue');
$stalwartCtl  = (string) @file_get_contents('/app/lib/Controller/StalwartController.php');
$routesSrc2   = (string) @file_get_contents('/app/appinfo/routes.php');

ok($resyncDialog !== '',
    'ResyncDialog.vue exists', $passes, $failures);
ok($stalwartCtl !== '',
    'StalwartController.php exists', $passes, $failures);
ok(str_contains($dropdownJs2, "'souvera-mail:open-resync'"),
    'dropdown-menu.js dispatches souvera-mail:open-resync', $passes, $failures);
ok(str_contains($dropdownJs2, 'RESYNC_MARKER'),
    'dropdown-menu.js uses a separate idempotent marker for the resync entry',
    $passes, $failures);
ok(str_contains($src['app'], "'souvera-mail:open-resync'"),
    'App.vue listens for souvera-mail:open-resync', $passes, $failures);
ok(str_contains($src['app'], 'ResyncDialog'),
    'App.vue mounts ResyncDialog conditionally', $passes, $failures);
ok(str_contains($routesSrc2, "'stalwart#resync'")
    && str_contains($routesSrc2, "'/stalwart/resync'"),
    'routes.php registers POST /stalwart/resync', $passes, $failures);
ok((bool) preg_match('/class\s+StalwartController\s+extends\s+Controller/', $stalwartCtl),
    'StalwartController extends AppFramework Controller', $passes, $failures);
ok(str_contains($stalwartCtl, '#[NoAdminRequired]'),
    'StalwartController::resync is #[NoAdminRequired]', $passes, $failures);

// Honesty check: the ResyncDialog MUST NOT claim to trigger a
// server-side FTS reindex — Stalwart 0.16 has no such endpoint.
ok(str_contains($resyncDialog, 'FTS')
    && (str_contains($resyncDialog, 'automatisch') || str_contains($resyncDialog, 'automatic')),
    'ResyncDialog is honest about FTS (no fake server-side reindex claim)',
    $passes, $failures);
ok(str_contains($resyncDialog, 'clearSnappymailLocalStorage')
    && (str_contains($resyncDialog, "'rl.'")
        || str_contains($resyncDialog, "'snappymail.'")),
    'ResyncDialog clears Snappymail localStorage keys before reload',
    $passes, $failures);
ok(str_contains($resyncDialog, 'window.location.reload'),
    'ResyncDialog does a full page reload for the actual sync effect',
    $passes, $failures);
ok(stripos($src['changelog'], 'Vue 3') !== false
    || stripos($src['changelog'], 'Vue-3') !== false
    || stripos($src['changelog'], 'design system') !== false,
    'CHANGELOG mentions Vue 3 / Souvera Design System',
    $passes, $failures);

// ==============================================================
// Q — v0.14.12 bug fixes: v-model bindings + user-menu entry
// ==============================================================
$formSrc = (string) file_get_contents('/app/src/components/screens/ImapFormScreen.vue');
$appVueSrc = (string) file_get_contents('/app/src/App.vue');
$appPhpSrc = (string) file_get_contents('/app/lib/AppInfo/Application.php');

// v-model must use Vue-3 API (modelValue), not the Vue-2 legacy
// (:value.sync / @update:value / @update:checked).
// Regex-based: only real attribute usage `:value.sync="..."` is a bug —
// mentions of the string in the file header comment are fine.
ok(!preg_match('/:value\.sync\s*=/', $formSrc),
    'ImapFormScreen no longer uses the Vue-2 :value.sync modifier as an attribute',
    $passes, $failures);
ok(!preg_match('/@update:value\s*=/', $formSrc),
    'ImapFormScreen no longer listens on @update:value (deprecated in @nextcloud/vue v9)',
    $passes, $failures);
ok(!preg_match('/@update:checked\s*=/', $formSrc),
    'ImapFormScreen no longer listens on @update:checked (deprecated in @nextcloud/vue v9)',
    $passes, $failures);
ok(str_contains($formSrc, ':model-value=')
    && str_contains($formSrc, '@update:model-value='),
    'ImapFormScreen uses :model-value / @update:model-value bindings',
    $passes, $failures);
// Port default (993) must be preserved as reactive number.
ok(str_contains($formSrc, "Number(v)")
    || str_contains($formSrc, "parseInt("),
    'Port input is cast back to Number on update (default 993 stays numeric)',
    $passes, $failures);
// canSubmit computed still verifies all four fields.
ok(str_contains($formSrc, 'this.form.host')
    && str_contains($formSrc, 'this.form.username')
    && str_contains($formSrc, 'this.form.password'),
    'canSubmit still guards host/username/password',
    $passes, $failures);

// v0.14.17 — the "Alte Mails importieren" entry lives inside the
// Snappymail SystemDropDown (top-right user menu, next to the F1 help
// item), NOT the Nextcloud user-menu. The NC entry from v0.14.12 has
// been removed and replaced by a client-side DOM injector.
ok(!str_contains($appPhpSrc, "'id' => 'souvera_mail_migration'"),
    'Application.php no longer registers the Nextcloud user-menu entry (moved into Snappymail)',
    $passes, $failures);
ok(!str_contains($appPhpSrc, "'type' => 'settings'"),
    'No settings-type navigation entry remains from the v0.14.12 attempt',
    $passes, $failures);

$dropdownJs = (string) @file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/dropdown-menu.js');
$pluginIdx  = (string) @file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
ok($dropdownJs !== '',
    'Snappymail plugin ships js/dropdown-menu.js (menu-entry enricher)',
    $passes, $failures);
ok(str_contains($dropdownJs, 'top-system-dropdown-id')
    && str_contains($dropdownJs, 'GLOBAL/HELP'),
    'dropdown-menu.js hooks the Snappymail SystemDropDown near the Help item',
    $passes, $failures);
ok(str_contains($dropdownJs, "'souvera-mail:open-migration'"),
    'dropdown-menu.js dispatches souvera-mail:open-migration on click',
    $passes, $failures);
ok(str_contains($dropdownJs, 'MutationObserver'),
    'dropdown-menu.js uses a MutationObserver (Snappymail lazily renders the menu)',
    $passes, $failures);
ok(str_contains($pluginIdx, "\$this->addJs('js/dropdown-menu.js')"),
    'Snappymail plugin (index.php) registers js/dropdown-menu.js',
    $passes, $failures);
ok(str_contains($appVueSrc, "'souvera-mail:open-migration'"),
    'App.vue listens for souvera-mail:open-migration and force-opens the wizard',
    $passes, $failures);

// Backward-compat: ?openMigration=1 URL param stays as a fallback path.
ok(str_contains($appVueSrc, "openMigration"),
    'App.vue keeps ?openMigration=1 URL param as a compatibility path',
    $passes, $failures);
ok(str_contains($appVueSrc, "forceOpen"),
    'App.vue uses a forceOpen guard to bypass the dismissed flag',
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
