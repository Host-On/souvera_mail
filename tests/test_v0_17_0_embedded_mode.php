<?php
/**
 * v0.17.0 — Regression pin for the standalone / embedded mode.
 *
 * Operator request:
 *   „Eine URL, die die SnappyMail-Oberfläche ohne jegliche Nextcloud-
 *   Shell ausliefert (kein #header, kein App-Menü, keine abgerundeten
 *   Container/Ränder) — aber die bestehende Nextcloud-Session /
 *   OIDC-Authentifizierung weiter nutzt, sodass kein zweiter Login
 *   nötig ist."
 *
 * The implementation offers TWO equally-valid entry points:
 *   1. `?embedded=1` (or `?standalone=1`) on the existing route
 *   2. Dedicated `/embed` route
 * Both go through the same NoAdminRequired auth middleware — a
 * missing session redirects to /login as usual (NOT hard 401).
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

// -----------------------------------------------------------------
// 1. PageController shape
// -----------------------------------------------------------------
$ctrlPath = '/app/lib/Controller/PageController.php';
$ctrl = (string) \file_get_contents($ctrlPath);

$a(\str_contains($ctrl, 'public function embed()'),
    'PageController exposes a dedicated embed() action');
$a(\str_contains($ctrl, 'private function renderMailApp(bool $forceStandalone)'),
    'PageController extracts the shared mail-app render into renderMailApp()');
$a(\str_contains($ctrl, "\$this->request->getParam('embedded') === '1'"),
    'index() detects ?embedded=1');
$a(\str_contains($ctrl, "\$this->request->getParam('standalone') === '1'"),
    'index() also honours ?standalone=1 (alias)');
$a(\str_contains($ctrl, "\$forceStandalone"),
    'embed()/index() share the renderMailApp() branch via a bool flag');
$a(\str_contains($ctrl, "\$response->renderAs('base')"),
    'standalone mode calls TemplateResponse::renderAs("base") — no NC shell');

// Both actions must be #[NoAdminRequired] so the SAME auth middleware
// runs — no hard 401 on missing session, standard /login redirect.
$a(\preg_match('/#\[NoAdminRequired\]\s*\n\s*#\[NoCSRFRequired\]\s*\n\s*public function index/', $ctrl) === 1,
    'index() carries #[NoAdminRequired] + #[NoCSRFRequired]');
$a(\preg_match('/#\[NoAdminRequired\]\s*\n\s*#\[NoCSRFRequired\]\s*\n\s*public function embed/', $ctrl) === 1,
    'embed() carries the SAME attributes so auth middleware behaves identically');

// The residual-query strip: `?embedded=1` alone must NOT be treated
// as a SnappyMail AJAX request (which would happen if we passed the
// raw query string through to the engine).
$a(\str_contains($ctrl, "'/(?:^|&)(?:embedded|standalone)=[^&]*/'"),
    'residual query-string filter strips embedded/standalone before AJAX gate');
$a(\str_contains($ctrl, "\$residualQuery !== ''"),
    'AJAX gate uses the FILTERED residual query — a lone ?embedded=1 does not trigger it');

// Standalone stylesheet swap
$a(\str_contains($ctrl, "\OCP\Util::addStyle('souvera_mail', 'standalone')"),
    'standalone mode registers css/standalone.css');
$a(\str_contains($ctrl, "\OCP\Util::addStyle('souvera_mail', 'embed')"),
    'default (in-NC-shell) mode still registers css/embed.css');

// Template gets the IsStandalone flag for future body-class or JS
// wiring.
$a(\str_contains($ctrl, "'IsStandalone' => \$isStandalone"),
    'index_embed template receives the IsStandalone flag');

// -----------------------------------------------------------------
// 2. Routes registered
// -----------------------------------------------------------------
$routes = (string) \file_get_contents('/app/appinfo/routes.php');
$a(\str_contains($routes, "'name' => 'page#embed'"),
    'routes.php registers page#embed');
$a(\str_contains($routes, "'name' => 'page#embedPost'"),
    'routes.php registers page#embedPost (v0.17.1 POST twin — required by SnappyMail\'s relative-URL AJAX)');
$a(\str_contains($routes, "'url' => '/embed'"),
    'page#embed is bound to the /embed URL');
// Route file must remain PHP-parseable.
$parsed = require '/app/appinfo/routes.php';
$a(\is_array($parsed) && isset($parsed['routes']) && \is_array($parsed['routes']),
    'routes.php still returns the expected structure after edit');
$embedRoute = null;
$embedPostRoute = null;
foreach ($parsed['routes'] as $r) {
    if (($r['name'] ?? null) === 'page#embed') { $embedRoute = $r; }
    if (($r['name'] ?? null) === 'page#embedPost') { $embedPostRoute = $r; }
}
$a($embedRoute !== null,
    'page#embed route entry parseable from routes.php');
$a($embedRoute !== null && ($embedRoute['verb'] ?? null) === 'GET',
    'page#embed responds to GET (WebView loads via GET)');
$a($embedRoute !== null && ($embedRoute['url'] ?? null) === '/embed',
    'page#embed URL is exactly /embed (no trailing slash)');
$a($embedPostRoute !== null,
    'page#embedPost route entry parseable from routes.php');
$a($embedPostRoute !== null && ($embedPostRoute['verb'] ?? null) === 'POST',
    'page#embedPost responds to POST (SnappyMail\'s relative-URL AJAX target)');
$a($embedPostRoute !== null && ($embedPostRoute['url'] ?? null) === '/embed',
    'page#embedPost bound to the SAME /embed URL as the GET route');

// PageController exposes both handlers.
$a(\str_contains($ctrl, 'public function embedPost()'),
    'PageController exposes embedPost() action');
$a(\str_contains($ctrl, "engineHelper->startApp(true)"),
    'embedPost() delegates to the SnappyMail engine directly (same pattern as indexPost/appPost)');

// -----------------------------------------------------------------
// 3. standalone.css exists and covers the essentials
// -----------------------------------------------------------------
$cssPath = '/app/css/standalone.css';
$a(\is_file($cssPath), 'css/standalone.css exists');
$css = (string) \file_get_contents($cssPath);
$a(\str_contains($css, '#x2m-app'),
    'standalone.css targets #x2m-app (the SnappyMail root)');
$a(\str_contains($css, 'position: fixed'),
    '#x2m-app is fixed-positioned to fill the viewport');
$a(\str_contains($css, 'inset: 0'),
    '#x2m-app spans the whole viewport (inset: 0)');
$a(\str_contains($css, 'border-radius: 0'),
    'rounded containers are explicitly killed');
$a(\str_contains($css, 'logoutClick'),
    'SSO-mode logout controls are hidden (users log out via NC)');

// -----------------------------------------------------------------
// 4. Non-regression: existing embed.css still exists AND the
//    default (?query-less) index() still uses it. (The user-facing
//    behaviour of /apps/souvera_mail/ is unchanged.)
// -----------------------------------------------------------------
$a(\is_file('/app/css/embed.css'),
    'legacy css/embed.css is still present (default in-NC-shell mode)');
$a(\str_contains($ctrl, "\OCP\Util::addStyle('souvera_mail', 'embed')"),
    'default index() render path still loads embed.css');

// -----------------------------------------------------------------
// 5. Version bump
// -----------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$pkg  = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#<version>0\.(?:1[7-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.17.0 or higher');
$a((bool) \preg_match('#"version"\s*:\s*"0\.(?:1[7-9]|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.17.0 or higher');

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
