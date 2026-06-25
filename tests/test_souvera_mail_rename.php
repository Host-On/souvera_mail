<?php
/**
 * Static verification for Souvera Mail v0.11.0 rename.
 * Covers:
 *  - info.xml id/namespace/version/settings block
 *  - composer.json PSR-4 mapping
 *  - vendor/composer/autoload_classmap.php contents
 *  - lib/ namespace declarations + php -l
 *  - templates/settings.php renders with stubbed deps (both appPasswordsAvailable branches)
 *  - templates/not_configured.php renders + contains 'occ souvera_mail:bootstrap'
 *  - appinfo/routes.php exactly 10 routes incl. settings#index
 *  - SettingsController.php
 *  - PersonalSettings/PersonalSection/personal_settings.php deleted
 *  - l10n register key is 'souvera_mail'
 *  - Engine plugin & quota.js verification
 *  - personal-settings.js id selectors match settings.php
 *  - No stray smail refs (excluding documented exceptions)
 */
declare(strict_types=1);

$failures = [];
$passes = [];

function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else { $f[] = $m; echo "FAIL: $m\n"; }
}

// p() / print_unescaped() helpers
if (!function_exists('p')) { function p($v): void { echo htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('print_unescaped')) { function print_unescaped($v): void { echo (string)$v; } }

// Stub OCP\Util
if (!class_exists('OCP\\Util')) {
    eval('namespace OCP; class Util { public static function addScript($a,$b){} public static function addStyle($a,$b){} }');
}

$l = new class { public function t(string $s, $p = []): string { return $s; } };

// ============================================================
// 1. info.xml
// ============================================================
$infoXml = file_get_contents('/app/appinfo/info.xml');
preg_match('#<id>([^<]+)</id>#', $infoXml, $im);
assertTrue(($im[1] ?? '') === 'souvera_mail', "info.xml <id> == souvera_mail (got: '".($im[1]??'')."')", $passes, $failures);
preg_match('#<namespace>([^<]+)</namespace>#', $infoXml, $nm);
assertTrue(($nm[1] ?? '') === 'SouveraMail', "info.xml <namespace> == SouveraMail", $passes, $failures);
preg_match('#<version>([^<]+)</version>#', $infoXml, $vm);
assertTrue(($vm[1] ?? '') === '0.11.0', "info.xml <version> == 0.11.0 (got: '".($vm[1]??'')."')", $passes, $failures);
assertTrue(str_contains($infoXml, '<admin>OCA\\SouveraMail\\Settings\\AdminSettings</admin>'), "info.xml <admin> uses new namespace", $passes, $failures);
assertTrue(str_contains($infoXml, '<admin-section>'), "info.xml has <admin-section>", $passes, $failures);
assertTrue(!str_contains($infoXml, '<personal>'), "info.xml has NO <personal>", $passes, $failures);
assertTrue(!str_contains($infoXml, '<personal-section>'), "info.xml has NO <personal-section>", $passes, $failures);

// ============================================================
// 2. composer.json
// ============================================================
$composer = json_decode(file_get_contents('/app/composer.json'), true);
$psr4 = $composer['autoload']['psr-4'] ?? [];
assertTrue(isset($psr4['OCA\\SouveraMail\\']), "composer.json psr-4 maps OCA\\SouveraMail\\", $passes, $failures);
assertTrue(($psr4['OCA\\SouveraMail\\'] ?? null) === 'lib/', "composer.json OCA\\SouveraMail\\ -> lib/", $passes, $failures);
assertTrue(!isset($psr4['OCA\\Smail\\']), "composer.json has NO OCA\\Smail\\ mapping", $passes, $failures);

// ============================================================
// 3. autoload_classmap.php
// ============================================================
$classmapContent = file_get_contents('/app/vendor/composer/autoload_classmap.php');
$entryCount = substr_count($classmapContent, '=>');
assertTrue($entryCount >= 270, "autoload_classmap.php has >= 270 entries (got: $entryCount)", $passes, $failures);
$oldNsCount = preg_match_all('#OCA\\\\\\\\Smail\\\\\\\\#', $classmapContent);
assertTrue($oldNsCount === 0, "autoload_classmap.php contains ZERO OCA\\\\Smail\\\\ entries (got: $oldNsCount)", $passes, $failures);
$newNsCount = preg_match_all('#OCA\\\\\\\\SouveraMail\\\\\\\\#', $classmapContent);
assertTrue($newNsCount >= 30, "autoload_classmap.php contains >= 30 OCA\\\\SouveraMail\\\\ entries (got: $newNsCount)", $passes, $failures);

// ============================================================
// 4. lib/ namespace declarations
// ============================================================
$libFiles = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/app/lib'));
foreach ($rii as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') { $libFiles[] = $f->getPathname(); }
}
$oldNsFiles = []; $newNsCount2 = 0; $syntaxErrors = [];
foreach ($libFiles as $fpath) {
    $head = file_get_contents($fpath);
    if (preg_match('#namespace\s+OCA\\\\Smail(\\\\|\s|;)#', $head)) $oldNsFiles[] = $fpath;
    if (preg_match('#namespace\s+OCA\\\\SouveraMail#', $head)) $newNsCount2++;
    $out = shell_exec('php -l ' . escapeshellarg($fpath) . ' 2>&1');
    if (!str_contains((string)$out, 'No syntax errors')) $syntaxErrors[] = "$fpath: $out";
}
assertTrue(count($oldNsFiles) === 0, "ZERO files in /app/lib declare namespace OCA\\Smail (got: ".count($oldNsFiles).")", $passes, $failures);
assertTrue($newNsCount2 >= 30, "/app/lib has >= 30 files with namespace OCA\\SouveraMail (got: $newNsCount2)", $passes, $failures);
assertTrue(empty($syntaxErrors), "All /app/lib PHP files pass `php -l` (errors: ".count($syntaxErrors).")", $passes, $failures);
if (!empty($syntaxErrors)) { foreach ($syntaxErrors as $se) echo "  $se\n"; }

// ============================================================
// 5. templates/settings.php — render BOTH branches
// ============================================================
$tplSettings = '/app/templates/settings.php';
$tplParams = [
    'brandName'                     => 'Souvera Mail',
    'backUrl'                       => '/index.php/apps/souvera_mail/',
    'dashboardMode'                 => 'unread',
    'dashboardModeUnread'           => 'unread',
    'dashboardModeAll'              => 'all',
    'dashboardModeUrl'              => '/index.php/apps/souvera_mail/preferences/dashboard-mode',
    'appPasswordsAvailable'         => true,
    'appPasswordsListUrl'           => '/index.php/apps/souvera_mail/app-passwords',
    'appPasswordsCreateUrl'         => '/index.php/apps/souvera_mail/app-passwords',
    'appPasswordsDestroyUrlTemplate'=> '/index.php/apps/souvera_mail/app-passwords/{id}',
];

// 5a. appPasswordsAvailable=true
$_ = $tplParams;
ob_start();
$renderErr = null;
try { include $tplSettings; } catch (Throwable $t) { $renderErr = $t->getMessage(); }
$outAvail = ob_get_clean();
assertTrue($renderErr === null, "settings.php renders WITHOUT error when appPasswordsAvailable=true (err: ".($renderErr??'')."')", $passes, $failures);
assertTrue(str_contains($outAvail, 'Back to inbox'), "settings.php contains 'Back to inbox' breadcrumb", $passes, $failures);
assertTrue(str_contains($outAvail, 'App passwords'), "settings.php contains App Passwords section heading", $passes, $failures);
assertTrue(str_contains($outAvail, 'Dashboard widget'), "settings.php contains Dashboard widget section", $passes, $failures);
assertTrue(str_contains($outAvail, 'souvera-mail-app-password-create-form'), "settings.php (avail=true) renders create form", $passes, $failures);
assertTrue(str_contains($outAvail, 'souvera-mail-app-passwords-table'), "settings.php (avail=true) renders passwords table", $passes, $failures);

// 5b. appPasswordsAvailable=false
$_ = array_merge($tplParams, ['appPasswordsAvailable' => false]);
ob_start();
$renderErr = null;
try { include $tplSettings; } catch (Throwable $t) { $renderErr = $t->getMessage(); }
$outNoAvail = ob_get_clean();
assertTrue($renderErr === null, "settings.php renders WITHOUT error when appPasswordsAvailable=false (err: ".($renderErr??'')."')", $passes, $failures);
assertTrue(str_contains($outNoAvail, 'not available'), "settings.php (avail=false) shows degraded-mode banner ('not available')", $passes, $failures);
assertTrue(!str_contains($outNoAvail, 'souvera-mail-app-password-create-form'), "settings.php (avail=false) does NOT render create form", $passes, $failures);

// ============================================================
// 6. templates/not_configured.php
// ============================================================
$_ = ['isAdmin' => true];
ob_start();
$renderErr = null;
try { include '/app/templates/not_configured.php'; } catch (Throwable $t) { $renderErr = $t->getMessage(); }
$ncAdmin = ob_get_clean();
assertTrue($renderErr === null, "not_configured.php (admin) renders without error", $passes, $failures);
assertTrue(str_contains($ncAdmin, 'occ souvera_mail:bootstrap'), "not_configured.php (admin) contains 'occ souvera_mail:bootstrap'", $passes, $failures);
assertTrue(!str_contains($ncAdmin, 'occ smail:bootstrap'), "not_configured.php (admin) does NOT contain 'occ smail:bootstrap'", $passes, $failures);

$_ = ['isAdmin' => false];
ob_start();
try { include '/app/templates/not_configured.php'; } catch (Throwable $t) {}
$ncUser = ob_get_clean();
assertTrue(strlen($ncUser) > 0, "not_configured.php (non-admin) renders some output", $passes, $failures);

// ============================================================
// 7. appinfo/routes.php
// ============================================================
$routes = require '/app/appinfo/routes.php';
assertTrue(is_array($routes) && isset($routes['routes']), "routes.php returns array with 'routes' key", $passes, $failures);
$routeCount = count($routes['routes']);
assertTrue($routeCount === 10, "routes.php registers exactly 10 routes (got: $routeCount)", $passes, $failures);
$routeNames = array_column($routes['routes'], 'name');
assertTrue(in_array('settings#index', $routeNames, true), "routes.php has settings#index", $passes, $failures);
$settingsRoute = null;
foreach ($routes['routes'] as $r) { if ($r['name'] === 'settings#index') { $settingsRoute = $r; break; } }
assertTrue($settingsRoute !== null && $settingsRoute['url'] === '/settings' && $settingsRoute['verb'] === 'GET', "settings#index is GET /settings", $passes, $failures);
// No literal 'smail' as name prefix
$routesSrc = file_get_contents('/app/appinfo/routes.php');
assertTrue(!preg_match("#'name'\s*=>\s*'smail#", $routesSrc), "routes.php has no route name starting with 'smail'", $passes, $failures);

// ============================================================
// 8. SettingsController
// ============================================================
$scPath = '/app/lib/Controller/SettingsController.php';
assertTrue(file_exists($scPath), "SettingsController.php exists", $passes, $failures);
$scSrc = file_get_contents($scPath);
assertTrue(str_contains($scSrc, 'namespace OCA\\SouveraMail\\Controller'), "SettingsController declares OCA\\SouveraMail\\Controller namespace", $passes, $failures);
assertTrue(preg_match('#public function index\(\)\s*:\s*TemplateResponse#', $scSrc) === 1, "SettingsController has index(): TemplateResponse", $passes, $failures);
assertTrue(str_contains($scSrc, '#[NoAdminRequired]'), "SettingsController has #[NoAdminRequired]", $passes, $failures);
assertTrue(str_contains($scSrc, '#[NoCSRFRequired]'), "SettingsController has #[NoCSRFRequired]", $passes, $failures);

// ============================================================
// 9. Deleted files
// ============================================================
assertTrue(!file_exists('/app/lib/Settings/PersonalSettings.php'), "PersonalSettings.php DELETED", $passes, $failures);
assertTrue(!file_exists('/app/lib/Settings/PersonalSection.php'), "PersonalSection.php DELETED", $passes, $failures);
assertTrue(!file_exists('/app/templates/personal_settings.php'), "personal_settings.php DELETED", $passes, $failures);

// ============================================================
// 10. L10N register key
// ============================================================
$l10nJs = glob('/app/l10n/*.js');
$registerSmail = 0; $registerSouvera = 0; $totalL10n = count($l10nJs);
foreach ($l10nJs as $f) {
    $c = file_get_contents($f);
    if (str_contains($c, 'OC.L10N.register(') && preg_match('#OC\.L10N\.register\(\s*"smail"#', $c)) $registerSmail++;
    if (preg_match('#OC\.L10N\.register\(\s*"souvera_mail"#', $c)) $registerSouvera++;
}
assertTrue($registerSmail === 0, "ZERO l10n .js files register against \"smail\" (got: $registerSmail)", $passes, $failures);
assertTrue($registerSouvera === $totalL10n && $totalL10n > 0, "All $totalL10n l10n .js files register against \"souvera_mail\" (got: $registerSouvera)", $passes, $failures);

// ============================================================
// 11. Engine plugin
// ============================================================
$enginePlugin = '/app/app/smail/v/current/app/plugins/nextcloud/index.php';
$epSrc = file_get_contents($enginePlugin);
assertTrue(str_contains($epSrc, 'OCA\\SouveraMail\\Util\\EngineHelper'), "Engine plugin references OCA\\SouveraMail\\Util\\EngineHelper", $passes, $failures);
assertTrue(!preg_match('#OCA\\\\Smail\\\\Util\\\\EngineHelper#', $epSrc), "Engine plugin has NO OCA\\Smail\\Util\\EngineHelper", $passes, $failures);
assertTrue(!preg_match("#(getAppValue|setAppValue|getAppKeys)\(['\"]smail['\"]#", $epSrc), "Engine plugin has NO 'smail' app-config keys (uses 'souvera_mail')", $passes, $failures);
assertTrue(preg_match("#(getAppValue|getUserValue)\(['\"]souvera_mail['\"]|getAppValue\(\s*['\"]souvera_mail#", $epSrc) === 1
        || substr_count($epSrc, "'souvera_mail'") >= 3, "Engine plugin reads from 'souvera_mail' app-config domain", $passes, $failures);
assertTrue(str_contains($epSrc, "'SmailQuotaUrl'"), "Engine plugin FilterAppData emits SmailQuotaUrl", $passes, $failures);
assertTrue(str_contains($epSrc, "'SmailSettingsUrl'"), "Engine plugin FilterAppData emits SmailSettingsUrl", $passes, $failures);
assertTrue(str_contains($epSrc, "linkToRoute('souvera_mail.quota.index')"), "SmailQuotaUrl uses linkToRoute('souvera_mail.quota.index')", $passes, $failures);
assertTrue(str_contains($epSrc, "linkToRoute('souvera_mail.settings.index')"), "SmailSettingsUrl uses linkToRoute('souvera_mail.settings.index')", $passes, $failures);

// ============================================================
// 12. quota.js
// ============================================================
$quotaJs = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/quota.js');
assertTrue(str_contains($quotaJs, 'cfg.SmailSettingsUrl'), "quota.js reads cfg.SmailSettingsUrl from FilterAppData payload", $passes, $failures);
assertTrue(preg_match("#createElement\(\s*settingsUrl\s*\?\s*'a'\s*:\s*'div'\s*\)#", $quotaJs) === 1, "quota.js creates <a> when settingsUrl present, else <div>", $passes, $failures);
assertTrue(str_contains($quotaJs, "el.target = '_blank'") || str_contains($quotaJs, 'el.target="_blank"'), "quota.js sets target='_blank' on <a> element", $passes, $failures);

// ============================================================
// 13. personal-settings.js IDs match settings.php IDs
// ============================================================
$psJs = file_get_contents('/app/js/personal-settings.js');
$tplSrc = file_get_contents('/app/templates/settings.php');
preg_match_all("#getElementById\(\s*['\"]([^'\"]+)['\"]\s*\)#", $psJs, $idMatches);
$jsIds = array_unique($idMatches[1]);
$missingIds = [];
foreach ($jsIds as $id) {
    if (!preg_match('#id\s*=\s*["\']' . preg_quote($id, '#') . '["\']#', $tplSrc)) $missingIds[] = $id;
}
assertTrue(empty($missingIds), "All getElementById IDs in personal-settings.js exist in settings.php (missing: ".implode(',', $missingIds).")", $passes, $failures);
assertTrue(count($jsIds) >= 5, "personal-settings.js has >= 5 getElementById calls (got: ".count($jsIds).")", $passes, $failures);

// ============================================================
// 14. No stray 'smail' refs in lib/, templates/, appinfo/, composer.json
//     (excluding allowed: Smail\Engine, Smail\Mail, app/smail/v/current, InstallStep)
// ============================================================
$grep = shell_exec("grep -rwn 'smail' /app/lib /app/templates /app/appinfo /app/composer.json 2>/dev/null");
$lines = array_filter(explode("\n", (string)$grep));
$violations = [];
foreach ($lines as $ln) {
    if (str_contains($ln, 'Smail\\Engine') || str_contains($ln, 'Smail\\Mail')) continue;
    if (str_contains($ln, 'app/smail/v/current')) continue;
    if (str_contains($ln, 'InstallStep.php')) continue;
    if (preg_match('#composer\.json:\d+:\s+"name":\s*"souvera/smail"#', $ln)) continue; // composer package name (not app id)
    if (preg_match('#templates/settings\.php.*souvera-mail#i', $ln)) continue;
    $violations[] = $ln;
}
assertTrue(empty($violations), "No stray 'smail' references in lib/templates/appinfo/composer.json (violations: ".count($violations).")", $passes, $failures);
if (!empty($violations)) { foreach ($violations as $v) echo "  -> $v\n"; }

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
