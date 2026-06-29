<?php
/**
 * Verify that the Nextcloud sidebar nav entry renders as the short
 * label "Mail" (not "Souvera Mail") — the long brand name overflows
 * the NC sidebar at smaller widths.
 *
 * Confirms:
 *  - NavigationTitle::DEFAULT === 'Mail'
 *  - NavigationTitle::resolve() returns the short default when no
 *    `menu-title` override is stored
 *  - NavigationTitle::resolve() returns the operator override when
 *    one IS stored (`occ config:app:set souvera_mail menu-title …`)
 *  - The full brand name "Souvera Mail" is still present in
 *    info.xml `<name>` (i.e. we did NOT renamne the whole app)
 *  - templates/settings.php heading still shows the long brand
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// Need OCP\IAppConfig contract — stub.
if (!interface_exists('OCP\\IAppConfig')) {
    eval('namespace OCP; interface IAppConfig {
        public function getValueString(string $app, string $key, string $default = \'\'): string;
    }');
}
class StubAppConfig implements \OCP\IAppConfig {
    public string $stored = '';
    public function getValueString(string $app, string $key, string $default = ''): string {
        if ($app === 'souvera_mail' && $key === 'menu-title') return $this->stored !== '' ? $this->stored : $default;
        return $default;
    }
}

require_once '/app/lib/Util/NavigationTitle.php';

use OCA\SouveraMail\Util\NavigationTitle;

// 1. DEFAULT constant
assertTrue(NavigationTitle::DEFAULT === 'Mail',
    "NavigationTitle::DEFAULT === 'Mail' (got: '" . NavigationTitle::DEFAULT . "')",
    $passes, $failures);

// 2. APP_CONFIG_KEY unchanged
assertTrue(NavigationTitle::APP_CONFIG_KEY === 'menu-title',
    "NavigationTitle::APP_CONFIG_KEY === 'menu-title'", $passes, $failures);

// 3. resolve() defaults to 'Mail' when no override is stored
$cfg = new StubAppConfig();
assertTrue(NavigationTitle::resolve($cfg) === 'Mail',
    "resolve() returns 'Mail' when no override stored", $passes, $failures);

// 4. resolve() returns operator override when one IS stored
$cfg->stored = 'Posteingang';
assertTrue(NavigationTitle::resolve($cfg) === 'Posteingang',
    "resolve() returns operator override when set ('Posteingang')", $passes, $failures);

// 5. Whitespace trimming
$cfg->stored = "   Inbox   ";
assertTrue(NavigationTitle::resolve($cfg) === 'Inbox',
    "resolve() trims operator override whitespace", $passes, $failures);

// 6. Empty override falls back to DEFAULT
$cfg->stored = "   ";
assertTrue(NavigationTitle::resolve($cfg) === 'Mail',
    "resolve() falls back to 'Mail' when override is whitespace-only", $passes, $failures);

// 7. validate() still enforces sane lengths / control chars
assertTrue(NavigationTitle::validate('Mail') === null,
    "validate('Mail') === null (accepted)", $passes, $failures);
assertTrue(NavigationTitle::validate(str_repeat('a', 65)) === 'Invalid menu title',
    "validate() rejects > 64 chars", $passes, $failures);
assertTrue(NavigationTitle::validate("bad\x01char") === 'Invalid menu title',
    "validate() rejects control chars", $passes, $failures);

// 8. The full brand name "Souvera Mail" is still present in info.xml <name>
$info = file_get_contents('/app/appinfo/info.xml');
preg_match('#<name>([^<]+)</name>#', $info, $nm);
assertTrue(($nm[1] ?? '') === 'Souvera Mail',
    "info.xml <name> still says 'Souvera Mail' (got: '" . ($nm[1] ?? '') . "')",
    $passes, $failures);

// 9. info.xml <summary> still uses the long brand
assertTrue(str_contains($info, 'Nextcloud-native webmail'),
    "info.xml summary still uses the long product description", $passes, $failures);

// 10. The settings page header still shows the long brand
//     0.13.2: the NC-chrome settings.php was deleted; the brand
//     now lives in the engine-side Settings tab template.
$tplPath = '/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html';
assertTrue(file_exists($tplPath), "Engine plugin ships SettingsSouveraAccount.html template", $passes, $failures);
$tpl = file_get_contents($tplPath);
assertTrue(str_contains($tpl, 'Sicherheit') && str_contains($tpl, 'Geräte'),
    "Settings tab template header is 'Sicherheit & Geräte' (the short, fitting label)",
    $passes, $failures);

// 11. SettingsController is now a redirect-only entry point — no brand string needed.
$sc = file_get_contents('/app/lib/Controller/SettingsController.php');
assertTrue(str_contains($sc, 'RedirectResponse') && str_contains($sc, '#/settings/souvera-account'),
    "SettingsController is a RedirectResponse to the in-engine #/settings/souvera-account route",
    $passes, $failures);

// 12. Application.php still uses NavigationTitle::resolve() (no regression)
$appSrc = file_get_contents('/app/lib/AppInfo/Application.php');
assertTrue(str_contains($appSrc, 'NavigationTitle::resolve($appConfig)'),
    "Application::boot() still resolves nav title via NavigationTitle::resolve()", $passes, $failures);

// 13. No stray hard-coded "Souvera Mail" *inside* the navigation closure
//     (would override the menu-title operator setting)
$navStart = strpos($appSrc, '$navigationManager->add');
$navEnd = strpos($appSrc, '});', $navStart);
$navBlock = substr($appSrc, $navStart, $navEnd - $navStart);
assertTrue(!str_contains($navBlock, "'Souvera Mail'") && !str_contains($navBlock, '"Souvera Mail"'),
    "Navigation closure does not hard-code 'Souvera Mail' — uses NavigationTitle::resolve()",
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
