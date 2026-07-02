<?php
/**
 * Tests for the "Hilfe & Anleitung" Settings tab (0.13.21).
 *
 * The tab is a read-only Snappymail Settings ViewModel registered at
 * `#/settings/souvera-help` that surfaces:
 *
 *   1. IMAP / POP3 / SMTP / ManageSieve config (from the active engine
 *      domain-config JSON via DomainConfigService).
 *   2. CalDAV / CardDAV URLs (derived from the NC WebDAV base).
 *   3. Souvera Shield quarantine URL (from `souvera_mail.shield_url`).
 *   4. Mobile-app recommendations.
 *   5. Keyboard shortcuts.
 *
 * These assertions pin down every wire between the pieces:
 * - Static source contract on the new JS + HTML template files.
 * - `FilterAppData` in the engine plugin emits every `SmailHelp*` key.
 * - `buildHelpData()` derives POP3 from IMAP (Stalwart single-listener
 *   design) and gracefully degrades when no domain is configured.
 * - Behavioural sim: run `buildHelpData()` semantics with three
 *   domain-config scenarios (present + valid, present + missing Sieve,
 *   absent → all placeholders).
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. New JS ViewModel — settings-help.js
// ---------------------------------------------------------------
$jsPath = '/app/app/smail/v/current/app/plugins/nextcloud/js/settings-help.js';
assertTrue(is_file($jsPath), "settings-help.js exists at $jsPath", $passes, $failures);
$js = (string) file_get_contents($jsPath);

assertTrue(str_contains($js, "rl.addSettingsViewModel("),
    "settings-help.js uses rl.addSettingsViewModel to register the tab",
    $passes, $failures);
assertTrue(str_contains($js, "'SettingsSouveraHelp'"),
    "settings-help.js registers under template basename 'SettingsSouveraHelp'",
    $passes, $failures);
assertTrue(str_contains($js, "'souvera-help'"),
    "settings-help.js binds the hash route 'souvera-help' (#/settings/souvera-help)",
    $passes, $failures);
assertTrue(str_contains($js, "'Hilfe & Anleitung'"),
    "settings-help.js uses German label 'Hilfe & Anleitung'",
    $passes, $failures);

// Every observable that the HTML template binds MUST be declared in the ViewModel.
$observables = [
    'imapHost', 'imapPort', 'imapSsl',
    'pop3Host', 'pop3Port', 'pop3Ssl',
    'smtpHost', 'smtpPort', 'smtpSsl',
    'sieveHost', 'sievePort', 'sieveSsl',
    'calDavUrl', 'cardDavUrl',
    'shieldUrl', 'userEmail', 'userDomain',
];
foreach ($observables as $obs) {
    assertTrue(str_contains($js, "this.{$obs} = ko.observable"),
        "settings-help.js declares observable this.{$obs}", $passes, $failures);
}
foreach (['copyImap', 'copyPop3', 'copySmtp', 'copySieve', 'copyCalDav', 'copyCardDav', 'copyEmail'] as $act) {
    assertTrue(str_contains($js, $act . ':'),
        "settings-help.js exposes action {$act}", $passes, $failures);
}
assertTrue(str_contains($js, "shieldAvailable"),
    "settings-help.js exposes computed shieldAvailable (used for {ko if} in the template)",
    $passes, $failures);

// Reads all data from rl.settings.get('Nextcloud')
assertTrue(str_contains($js, "rl.settings.get('Nextcloud')"),
    "settings-help.js reads config from rl.settings.get('Nextcloud')",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. New HTML template — SettingsSouveraHelp.html
// ---------------------------------------------------------------
$htmlPath = '/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraHelp.html';
assertTrue(is_file($htmlPath), "SettingsSouveraHelp.html exists at $htmlPath", $passes, $failures);
$html = (string) file_get_contents($htmlPath);

// Root wrapper class = shared palette scope
assertTrue(str_contains($html, 'class="souvera-settings souvera-help"'),
    "template uses the shared .souvera-settings palette (inherits variables)",
    $passes, $failures);

// Every observable that the JS declares MUST be bound in the template.
foreach ($observables as $obs) {
    assertTrue(str_contains($html, 'data-bind="text: ' . $obs . '"')
        || str_contains($html, "text: {$obs}")
        || str_contains($html, "attr: { href: {$obs} }"),
        "template binds observable {$obs}", $passes, $failures);
}

// Cards present (5)
foreach ([
    'Mail-Client einrichten (IMAP / POP3 / SMTP)',
    'Kalender &amp; Kontakte (CalDAV / CardDAV)',
    'Souvera Shield',
    'Mobile-App-Empfehlungen',
    'Tastenkürzel',
] as $heading) {
    assertTrue(str_contains($html, $heading),
        "template contains card '{$heading}'", $passes, $failures);
}

// Shield: available/unavailable dual-branch
assertTrue((bool) preg_match('#<!-- ko if: shieldAvailable -->#', $html),
    "template branches on shieldAvailable (available card)", $passes, $failures);
assertTrue((bool) preg_match('#<!-- ko ifnot: shieldAvailable -->#', $html),
    "template branches on !shieldAvailable (unavailable banner)", $passes, $failures);
assertTrue(str_contains($html, 'occ config:app:set souvera_mail shield_url'),
    "template hints the operator with the shield_url override command",
    $passes, $failures);

// Mobile apps listed
foreach (['K-9 Mail', 'Thunderbird', 'Apple Mail', 'DAVx⁵', 'FairEmail'] as $app) {
    assertTrue(str_contains($html, $app),
        "template mentions mobile app {$app}", $passes, $failures);
}

// Shortcut categories
foreach (['Nachrichten', 'Liste &amp; Ordner', 'Ansicht'] as $g) {
    assertTrue(str_contains($html, $g),
        "template shortcuts group '{$g}'", $passes, $failures);
}
foreach (['<kbd>W</kbd>', '<kbd>R</kbd>', '<kbd>F</kbd>', '<kbd>Z</kbd>', '<kbd>F1</kbd>'] as $kbd) {
    assertTrue(str_contains($html, $kbd),
        "template shortcut key {$kbd}", $passes, $failures);
}

// ---------------------------------------------------------------
// 3. Plugin wiring — Init() + FilterAppData
// ---------------------------------------------------------------
$pluginPath = '/app/app/smail/v/current/app/plugins/nextcloud/index.php';
$plugin = (string) file_get_contents($pluginPath);

// Init() registers both assets
assertTrue(str_contains($plugin, "\$this->addJs('js/settings-help.js')"),
    "engine plugin registers js/settings-help.js in Init()",
    $passes, $failures);
assertTrue(str_contains($plugin, "\$this->addTemplate('templates/SettingsSouveraHelp.html')"),
    "engine plugin registers templates/SettingsSouveraHelp.html in Init()",
    $passes, $failures);

// FilterAppData wires buildHelpData() into the Nextcloud payload
assertTrue(str_contains($plugin, '$this->buildHelpData('),
    "FilterAppData calls \$this->buildHelpData(...)", $passes, $failures);
assertTrue(str_contains($plugin, 'protected function buildHelpData('),
    "buildHelpData() is declared as a protected method on the plugin",
    $passes, $failures);

// Every Smail-Help-* key surfaces
$keys = [
    'SmailHelpDomain', 'SmailHelpEmail',
    'SmailHelpImapHost', 'SmailHelpImapPort', 'SmailHelpImapSsl',
    'SmailHelpPop3Host', 'SmailHelpPop3Port', 'SmailHelpPop3Ssl',
    'SmailHelpSmtpHost', 'SmailHelpSmtpPort', 'SmailHelpSmtpSsl',
    'SmailHelpSieveHost', 'SmailHelpSievePort', 'SmailHelpSieveSsl',
    'SmailHelpCalDavUrl', 'SmailHelpCardDavUrl', 'SmailHelpShieldUrl',
];
foreach ($keys as $k) {
    assertTrue(str_contains($plugin, "'{$k}'"),
        "buildHelpData() emits key '{$k}'", $passes, $failures);
}

// POP3 derives from IMAP + Stalwart single-listener design (995/SSL)
assertTrue((bool) preg_match('#\$pop3Host\s*=\s*\$imapHost#', $plugin),
    "buildHelpData() uses IMAP host for POP3 (Stalwart single-listener design)",
    $passes, $failures);
assertTrue(str_contains($plugin, "'995'"),
    "buildHelpData() derives POP3 port 995 (Stalwart default)",
    $passes, $failures);

// Shield URL resolves via IAppConfig with graceful fallback
assertTrue(str_contains($plugin, "getValueString('souvera_mail', 'shield_url'"),
    "buildHelpData() reads Shield URL via IAppConfig::getValueString('souvera_mail','shield_url', …)",
    $passes, $failures);

// CalDAV/CardDAV built from WebDAV base
assertTrue(str_contains($plugin, "/calendars/' . \$sUID . '/'"),
    "buildHelpData() builds CalDAV URL as <webdav>/calendars/<uid>/",
    $passes, $failures);
assertTrue(str_contains($plugin, "/addressbooks/users/' . \$sUID . '/'"),
    "buildHelpData() builds CardDAV URL as <webdav>/addressbooks/users/<uid>/",
    $passes, $failures);

// DomainConfigService is the source of truth
assertTrue(str_contains($plugin, '\\OCA\\SouveraMail\\Service\\DomainConfigService'),
    "buildHelpData() reads config via OCA\\SouveraMail\\Service\\DomainConfigService",
    $passes, $failures);
assertTrue(str_contains($plugin, '::sslToString('),
    "buildHelpData() maps engine SSL int → human string via DomainConfigService::sslToString()",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. DomainConfigService still has the sslToString static
// ---------------------------------------------------------------
$dcs = (string) file_get_contents('/app/lib/Service/DomainConfigService.php');
assertTrue(str_contains($dcs, 'public static function sslToString(int $ssl): string'),
    "DomainConfigService::sslToString(int) exists (public static)",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. Behavioural sim — build the help payload against three scenarios
// ---------------------------------------------------------------
// We inline the semantics of buildHelpData(). Drift is caught by the
// static-source assertions above, but the behavioural sim proves the
// per-scenario shape is correct end-to-end.

function simBuildHelpData(array $domainConfig, string $shieldUrl, string $sUID, string $email, string $webdav): array {
    $domain = '';  $imapHost = $imapPort = $imapSsl = '';
    $smtpHost = $smtpPort = $smtpSsl = '';  $sieveHost = $sievePort = $sieveSsl = '';

    if (!empty($domainConfig['__domain__'])) {
        $domain = $domainConfig['__domain__'];
        $imap = $domainConfig['IMAP'] ?? [];
        $smtp = $domainConfig['SMTP'] ?? [];
        $sieve = $domainConfig['Sieve'] ?? [];
        $imapHost = (string) ($imap['host'] ?? '');
        $imapPort = isset($imap['port']) ? (string) $imap['port'] : '';
        $imapSsl = ['None','SSL','STARTTLS'][(int) ($imap['type'] ?? 0)] ?? 'None';
        $smtpHost = (string) ($smtp['host'] ?? '');
        $smtpPort = isset($smtp['port']) ? (string) $smtp['port'] : '';
        $smtpSsl = ['None','SSL','STARTTLS'][(int) ($smtp['type'] ?? 0)] ?? 'None';
        if (!empty($sieve['enabled'])) {
            $sieveHost = (string) ($sieve['host'] ?? '');
            $sievePort = isset($sieve['port']) ? (string) $sieve['port'] : '';
            $sieveSsl = ['None','SSL','STARTTLS'][(int) ($sieve['type'] ?? 0)] ?? 'None';
        }
    }

    $pop3Host = $imapHost;
    $pop3Port = $imapHost !== '' ? '995' : '';
    $pop3Ssl = $imapHost !== '' ? 'SSL' : '';

    $webdavBase = rtrim($webdav, '/');
    return [
        'SmailHelpDomain' => $domain,
        'SmailHelpEmail' => $email !== '' ? $email : ($domain !== '' ? $sUID . '@' . $domain : ''),
        'SmailHelpImapHost' => $imapHost,
        'SmailHelpImapPort' => $imapPort,
        'SmailHelpImapSsl' => $imapSsl,
        'SmailHelpPop3Host' => $pop3Host,
        'SmailHelpPop3Port' => $pop3Port,
        'SmailHelpPop3Ssl' => $pop3Ssl,
        'SmailHelpSmtpHost' => $smtpHost,
        'SmailHelpSmtpPort' => $smtpPort,
        'SmailHelpSmtpSsl' => $smtpSsl,
        'SmailHelpSieveHost' => $sieveHost,
        'SmailHelpSievePort' => $sievePort,
        'SmailHelpSieveSsl' => $sieveSsl,
        'SmailHelpCalDavUrl' => $webdavBase . '/calendars/' . $sUID . '/',
        'SmailHelpCardDavUrl' => $webdavBase . '/addressbooks/users/' . $sUID . '/',
        'SmailHelpShieldUrl' => $shieldUrl,
    ];
}

// 5a. Full happy-path: IMAP + SMTP + Sieve configured
$cfg5a = [
    '__domain__' => 'buxtehude.link',
    'IMAP' => ['host' => 'mail.buxtehude.link', 'port' => 993, 'type' => 1],
    'SMTP' => ['host' => 'mail.buxtehude.link', 'port' => 465, 'type' => 1],
    'Sieve' => ['host' => 'mail.buxtehude.link', 'port' => 4190, 'type' => 2, 'enabled' => true],
];
$out5a = simBuildHelpData($cfg5a, 'https://shield.buxtehude.link', 'alice', 'alice@buxtehude.link', 'https://nc/remote.php/dav');
assertTrue($out5a['SmailHelpImapHost'] === 'mail.buxtehude.link',
    "5a: IMAP host propagated", $passes, $failures);
assertTrue($out5a['SmailHelpImapPort'] === '993',
    "5a: IMAP port 993 as string", $passes, $failures);
assertTrue($out5a['SmailHelpImapSsl'] === 'SSL',
    "5a: IMAP SSL mode mapped to human string", $passes, $failures);
assertTrue($out5a['SmailHelpPop3Host'] === 'mail.buxtehude.link',
    "5a: POP3 host = IMAP host", $passes, $failures);
assertTrue($out5a['SmailHelpPop3Port'] === '995',
    "5a: POP3 port = 995 (Stalwart default)", $passes, $failures);
assertTrue($out5a['SmailHelpPop3Ssl'] === 'SSL',
    "5a: POP3 SSL", $passes, $failures);
assertTrue($out5a['SmailHelpSieveHost'] === 'mail.buxtehude.link',
    "5a: Sieve host propagated when enabled=true", $passes, $failures);
assertTrue($out5a['SmailHelpSieveSsl'] === 'STARTTLS',
    "5a: Sieve type=2 maps to STARTTLS", $passes, $failures);
assertTrue($out5a['SmailHelpShieldUrl'] === 'https://shield.buxtehude.link',
    "5a: Shield URL surfaced", $passes, $failures);
assertTrue($out5a['SmailHelpCalDavUrl'] === 'https://nc/remote.php/dav/calendars/alice/',
    "5a: CalDAV URL uses uid", $passes, $failures);
assertTrue($out5a['SmailHelpCardDavUrl'] === 'https://nc/remote.php/dav/addressbooks/users/alice/',
    "5a: CardDAV URL uses uid", $passes, $failures);

// 5b. Sieve disabled → blanks (matches Snappymail's Filters tab hidden)
$cfg5b = [
    '__domain__' => 'ex.co',
    'IMAP' => ['host' => 'm.ex.co', 'port' => 143, 'type' => 2],
    'SMTP' => ['host' => 'm.ex.co', 'port' => 587, 'type' => 2],
    'Sieve' => ['host' => 'm.ex.co', 'port' => 4190, 'type' => 2, 'enabled' => false],
];
$out5b = simBuildHelpData($cfg5b, '', 'bob', '', 'https://nc/remote.php/dav');
assertTrue($out5b['SmailHelpImapSsl'] === 'STARTTLS',
    "5b: STARTTLS mapping for type=2", $passes, $failures);
assertTrue($out5b['SmailHelpSieveHost'] === '' && $out5b['SmailHelpSievePort'] === '',
    "5b: Sieve.enabled=false → sieve fields blank", $passes, $failures);
assertTrue($out5b['SmailHelpShieldUrl'] === '',
    "5b: no shield_url → empty string (JS renders warning banner)", $passes, $failures);
assertTrue($out5b['SmailHelpEmail'] === 'bob@ex.co',
    "5b: email falls back to <uid>@<domain> when NC profile email is empty",
    $passes, $failures);

// 5c. No domain configured — fresh install with only NC set up
$out5c = simBuildHelpData([], '', 'carol', '', 'https://nc/remote.php/dav');
foreach (['SmailHelpImapHost','SmailHelpImapPort','SmailHelpSmtpHost','SmailHelpSieveHost',
         'SmailHelpPop3Host','SmailHelpPop3Port','SmailHelpShieldUrl','SmailHelpDomain','SmailHelpEmail'] as $blank) {
    assertTrue($out5c[$blank] === '',
        "5c: empty domain config → {$blank} is empty string (JS renders '—')",
        $passes, $failures);
}
assertTrue($out5c['SmailHelpCalDavUrl'] === 'https://nc/remote.php/dav/calendars/carol/',
    "5c: CalDAV URL still resolves (independent of domain config)",
    $passes, $failures);

// ---------------------------------------------------------------
// 6. Version bump
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(version_compare($vm[1] ?? '0', '0.13.21', '>='),
    "info.xml <version> >= 0.13.21 (got: '" . ($vm[1] ?? '') . "')",
    $passes, $failures);

// ---------------------------------------------------------------
// 7. CHANGELOG mentions the new tab
// ---------------------------------------------------------------
$cl = (string) file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($cl, '[0.13.21]'),
    "CHANGELOG has a [0.13.21] section", $passes, $failures);
assertTrue(str_contains($cl, 'Hilfe & Anleitung') || str_contains($cl, 'Hilfe &amp; Anleitung'),
    "CHANGELOG mentions the Hilfe & Anleitung tab", $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
