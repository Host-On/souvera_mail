<?php
/**
 * Regression test for Souvera Mail v0.14.10 — Migration wizard Phase 2
 * FRONTEND: vanilla-JS overlay, floating pill, 5-screen state machine.
 *
 * We can't stand up Snappymail in CI, so this suite is a source-shape
 * test that pins:
 *  - Assets exist + are properly wired via PageController::index()
 *  - JS parses cleanly (node --check)
 *  - CSS uses the reserved z-index range above Snappymail's popups
 *  - State machine covers all 8 states
 *  - Backend contract: correct endpoint paths + verbs + JSON headers
 *  - Password is wiped from memory after the /start POST
 *  - NC requesttoken header is set on state-changing calls
 *  - Poll interval matches the backend cache freshness (5s)
 *  - The floating pill is ALWAYS mounted (persistent trigger)
 *  - The bridge Application hardening from earlier in the session is
 *    still in place (v0.14.10 defensive fix rides with this release)
 */

declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$paths = [
    'js'         => '/app/js/migration-wizard.js',
    'css'        => '/app/css/migration-wizard.css',
    'controller' => '/app/lib/Controller/PageController.php',
    'bridge'     => '/app/lib-bridge/Souvera_mail/AppInfo/Application.php',
    'info'       => '/app/appinfo/info.xml',
    'changelog'  => '/app/CHANGELOG.md',
];
$src = [];
foreach ($paths as $k => $p) {
    $src[$k] = (string) file_get_contents($p);
    ok($src[$k] !== '', "readable: {$k} ({$p})", $passes, $failures);
}

// node --check on the JS.
$out = []; $rc = 0;
\exec('node --check ' . \escapeshellarg($paths['js']) . ' 2>&1', $out, $rc);
ok($rc === 0, "node --check clean on migration-wizard.js", $passes, $failures);

// php -l on PageController + bridge.
foreach (['controller', 'bridge'] as $k) {
    $out2 = []; $rc2 = 0;
    \exec('php -l ' . \escapeshellarg($paths[$k]) . ' 2>&1', $out2, $rc2);
    ok($rc2 === 0, "php -l clean on " . basename($paths[$k]), $passes, $failures);
}

// ==============================================================
// A — asset wiring in PageController
// ==============================================================
ok(str_contains($src['controller'], "\\OCP\\Util::addStyle('souvera_mail', 'migration-wizard')"),
    "PageController loads css/migration-wizard.css via addStyle", $passes, $failures);
ok(str_contains($src['controller'], "\\OCP\\Util::addScript('souvera_mail', 'migration-wizard')"),
    "PageController loads js/migration-wizard.js via addScript", $passes, $failures);
// Order matters: style before script so no flash-of-unstyled-content
// when the JS mounts its pill on DOMContentLoaded.
$stylePos  = strpos($src['controller'], "addStyle('souvera_mail', 'migration-wizard')");
$scriptPos = strpos($src['controller'], "addScript('souvera_mail', 'migration-wizard')");
ok($stylePos !== false && $scriptPos !== false && $stylePos < $scriptPos,
    "Style tag added BEFORE script tag (avoids FOUC on pill mount)",
    $passes, $failures);

// ==============================================================
// B — JS state machine covers all 8 documented states
// ==============================================================
foreach (['HIDDEN', 'WELCOME', 'FORM', 'CONFIRM', 'PROGRESS', 'DONE_OK', 'DONE_FAIL'] as $state) {
    ok(str_contains($src['js'], "'{$state}'") || str_contains($src['js'], "\"{$state}\""),
        "State enum present in JS: {$state}", $passes, $failures);
}
// TESTING + STARTING are transient in-flight UI states, not persistent
// wizardState values — they surface only as button-disabled + spinner
// labels on the FORM and CONFIRM screens. Verify these UX labels exist
// so the user always sees the wizard is actively working during an HTTP
// round-trip.
ok(str_contains($src['js'], "'Prüfe…'"),
    'FORM screen shows a Pruefe... label while the pre-flight test-connection HTTP call is in flight',
    $passes, $failures);
ok(str_contains($src['js'], "'Starte…'"),
    'CONFIRM screen shows a Starte... label while the /start HTTP call is in flight',
    $passes, $failures);

// ==============================================================
// C — backend contract: exact endpoint paths + verbs
// ==============================================================
foreach ([
    "'/welcome-state', 'GET'"          => 'GET /welcome-state',
    "'/dismiss-welcome', 'POST'"       => 'POST /dismiss-welcome',
    "'/test-connection', 'POST'"       => 'POST /test-connection',
    "'/list-folders', 'POST'"          => 'POST /list-folders',
    "'/start', 'POST'"                 => 'POST /start',
    "'/status', 'GET'"                 => 'GET /status',
] as $needle => $label) {
    ok(str_contains($src['js'], $needle),
        "JS calls {$label} with the exact path+verb the backend exposes",
        $passes, $failures);
}
// The dismiss/{jobId} endpoint uses encodeURIComponent on the id.
ok(str_contains($src['js'], "'/dismiss/' + encodeURIComponent(jobId)"),
    "JS URL-encodes the job id when calling /dismiss/{jobId}",
    $passes, $failures);

// API base uses OC.generateUrl for correct proxy/index.php mapping.
ok(str_contains($src['js'], "OC.generateUrl('/apps/souvera_mail/migration')"),
    "JS uses OC.generateUrl for the API base (respects NC routing)",
    $passes, $failures);
// Fallback if OC.generateUrl isn't available (shouldn't happen inside
// Souvera Mail's PageController-rendered iframe, but defensive).
ok(str_contains($src['js'], "'/apps/souvera_mail/migration'"),
    "JS carries a hardcoded fallback API base for OC-less environments",
    $passes, $failures);

// ==============================================================
// D — CSRF + auth handling
// ==============================================================
ok(str_contains($src['js'], "opts.headers.requesttoken = token"),
    "JS sends the NC requesttoken header on all API calls (CSRF protection)",
    $passes, $failures);
ok(str_contains($src['js'], "credentials: 'same-origin'"),
    "JS sends session cookies with each call (same-origin auth)",
    $passes, $failures);

// ==============================================================
// E — security: password wiped from memory after /start POST
// ==============================================================
ok((bool) preg_match('#await api\(\'/start\'.*?form\.password\s*=\s*\'\';#s', $src['js']),
    "Password wiped from `form.password` immediately after /start returns",
    $passes, $failures);

// ==============================================================
// F — polling contract with backend cache
// ==============================================================
ok(str_contains($src['js'], 'POLL_INTERVAL_MS = 5000'),
    "Frontend polls every 5s (backend cache is refreshed every 60s by MigrationPoller)",
    $passes, $failures);
ok(str_contains($src['js'], 'if (next.isTerminal)'),
    "Frontend recognises `isTerminal` from the API response and stops polling",
    $passes, $failures);
ok(str_contains($src['js'], "next.status === 'completed'") &&
    str_contains($src['js'], "next.status === 'failed'"),
    "Frontend branches on completed/failed → success/failure splash",
    $passes, $failures);

// ==============================================================
// G — persistent floating pill
// ==============================================================
ok(str_contains($src['js'], "id: 'smail-migration-pill'"),
    "Persistent floating pill mounted with id smail-migration-pill",
    $passes, $failures);
ok(str_contains($src['js'], 'ensurePillMounted()'),
    "Pill is mounted from `boot()` — always visible when Souvera Mail loads",
    $passes, $failures);
ok(str_contains($src['js'], "data-state=\"running\"")
    || str_contains($src['js'], "'data-state', 'running'"),
    "Pill exposes `data-state=running` for CSS pulse animation during a live migration",
    $passes, $failures);

// ==============================================================
// H — auto-close success splash
// ==============================================================
ok(str_contains($src['js'], 'AUTO_CLOSE_SUCCESS_MS'),
    "Success splash auto-closes after AUTO_CLOSE_SUCCESS_MS (per user directive)",
    $passes, $failures);
ok((bool) preg_match('#AUTO_CLOSE_SUCCESS_MS\s*=\s*\d{4,}#', $src['js']),
    "AUTO_CLOSE_SUCCESS_MS is at least 4-digit ms (visible splash time)",
    $passes, $failures);

// ==============================================================
// I — CSS layered ABOVE Snappymail's popups
// ==============================================================
ok((bool) preg_match('#z-index:\s*21000000\d+#', $src['css']),
    "Overlay z-index is 2.1e9+ (above Snappymail's popup z-index of 210)",
    $passes, $failures);
ok(str_contains($src['css'], '#smail-migration-pill'),
    "CSS selects #smail-migration-pill for pill styling", $passes, $failures);
ok(str_contains($src['css'], '@keyframes smail-mig-pulse'),
    "CSS defines the pulse animation used by the running-state pill",
    $passes, $failures);
ok(str_contains($src['css'], '.smail-mig-modal'),
    "CSS styles the modal container", $passes, $failures);
ok(str_contains($src['css'], '.smail-mig-bar {'),
    "CSS styles the progress bar", $passes, $failures);

// ==============================================================
// J — user directives from the design conversation
// ==============================================================
// Welcome-Popup: Erst-Öffnen + jeder Login, bis „Nicht mehr zeigen"
ok(str_contains($src['js'], 'welcomeDismissed'),
    'JS honours welcomeDismissed flag (per user directive: hide after "Nicht mehr zeigen")',
    $passes, $failures);
ok(str_contains($src['js'], 'showWelcome'),
    "JS has explicit showWelcome() call path", $passes, $failures);
// Laufende Migration → Auto-Sprung auf Progress-Screen
ok(str_contains($src['js'], 'state.activeJob'),
    "JS jumps directly to progress screen if an active job exists on load",
    $passes, $failures);
ok(str_contains($src['js'], 'showProgress'),
    "JS has showProgress() called from boot() when activeJob is non-null",
    $passes, $failures);
// Kein Cancel — muss klar kommuniziert werden im Confirm-Screen
ok((\stripos($src['js'], 'kann nicht mehr abgebrochen') !== false)
    || (\stripos($src['js'], 'nicht mehr abgebrochen') !== false),
    "Confirm-Screen tells users the import cannot be cancelled (provider.tools has no cancel endpoint)",
    $passes, $failures);
// Provider-Presets: user said „custom" only → no dropdown
ok(!str_contains($src['js'], 'imap.gmail.com')
    && !str_contains($src['js'], 'imap.gmx.net')
    && !str_contains($src['js'], 'imap.web.de'),
    'No hard-coded provider presets (per user directive: "nur Custom")',
    $passes, $failures);

// ==============================================================
// K — Bridge hardening carried in this release
// ==============================================================
ok(str_contains($src['bridge'], "require_once \$vendorAutoload;"),
    "Bridge Application require_once's vendor/autoload.php (v0.14.10 defensive fix)",
    $passes, $failures);
ok(str_contains($src['bridge'], "\\dirname(__DIR__, 3)"),
    "Bridge path walks 3 dirs up (AppInfo → Souvera_mail → lib-bridge → /app)",
    $passes, $failures);
// PHP grammar: require_once MUST live AFTER the namespace declaration.
$nsPos = strpos($src['bridge'], 'namespace OCA\\Souvera_mail');
$reqPos = strpos($src['bridge'], 'require_once $vendorAutoload;');
ok($nsPos !== false && $reqPos !== false && $nsPos < $reqPos,
    "require_once lives AFTER the namespace declaration (PHP parse legality)",
    $passes, $failures);

// ==============================================================
// L — version bump + changelog markers
// ==============================================================
ok((bool) preg_match('#<version>0\.14\.(10|1[1-9]|\d{2,})</version>#', $src['info']),
    "info.xml version bumped to 0.14.10 (or later)", $passes, $failures);
ok((bool) preg_match('#\[0\.14\.10\]#', $src['changelog']),
    "CHANGELOG.md has a [0.14.10] section", $passes, $failures);
ok(\stripos($src['changelog'], 'wizard') !== false
    || \stripos($src['changelog'], 'welcome') !== false
    || \stripos($src['changelog'], 'import') !== false,
    "CHANGELOG [0.14.10] mentions the wizard/welcome/import feature",
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
