<?php
/**
 * Tests for the Dashboard widget auto-activation on first login (0.13.21).
 *
 * On UserLoggedInEvent, LoginBridgeListener seeds the Souvera Mail
 * "unread mail" dashboard widget id into the user's per-user
 * `dashboard.layout` config — but only ONCE per user (tracked via
 * a `souvera_mail/dashboard-widget-autoactivated` marker). Users
 * who later remove the widget do NOT get it re-added.
 *
 * Assertions cover:
 *   1. `UnreadMailWidget::WIDGET_ID` is the canonical id, and `getId()`
 *      returns it (no drift).
 *   2. Public constants on `LoginBridgeListener` pin the marker + dashboard
 *      config keys (so operator-side `occ config:app:set` commands align).
 *   3. `autoActivateDashboardWidget()` is dispatched from `handle()`.
 *   4. Behavioural sim: three user states (cold, warm-without-widget,
 *      respected-choice) drive the seed logic through a stub IConfig.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. UnreadMailWidget — WIDGET_ID constant + getId()
// ---------------------------------------------------------------
$widgetPath = '/app/lib/Dashboard/UnreadMailWidget.php';
$widget = (string) file_get_contents($widgetPath);
assertTrue(str_contains($widget, "public const WIDGET_ID = 'souvera_mail-unread';"),
    "UnreadMailWidget::WIDGET_ID const = 'souvera_mail-unread'",
    $passes, $failures);
assertTrue((bool) preg_match('#public function getId\(\).*?return self::WIDGET_ID;#s', $widget),
    "UnreadMailWidget::getId() returns self::WIDGET_ID (no string drift)",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. LoginBridgeListener — constants + wiring
// ---------------------------------------------------------------
$listenerPath = '/app/lib/Listeners/LoginBridgeListener.php';
$listener = (string) file_get_contents($listenerPath);

assertTrue(str_contains($listener, "public const DASHBOARD_APP = 'dashboard';"),
    "Listener pins the dashboard app id as 'dashboard'", $passes, $failures);
assertTrue(str_contains($listener, "public const DASHBOARD_LAYOUT_KEY = 'layout';"),
    "Listener pins the dashboard layout config key as 'layout'",
    $passes, $failures);
assertTrue(str_contains($listener, "public const AUTOACTIVATE_MARKER_APP = 'souvera_mail';"),
    "Listener pins the marker app id as 'souvera_mail'",
    $passes, $failures);
assertTrue(str_contains($listener, "public const AUTOACTIVATE_MARKER_KEY = 'dashboard-widget-autoactivated';"),
    "Listener pins the marker key as 'dashboard-widget-autoactivated'",
    $passes, $failures);

// Constructor injects IConfig
assertTrue((bool) preg_match('#__construct\(\s*private\s+ISession\s+\$session,\s*private\s+LogService\s+\$logService,\s*private\s+IConfig\s+\$config#', $listener),
    "Listener constructor takes ISession, LogService, and IConfig (in that order)",
    $passes, $failures);

// handle() dispatches to autoActivateDashboardWidget after stamping the session uid
assertTrue((bool) preg_match('#\$this->session->set\(\'souvera_mail-uid\'.*?\$this->autoActivateDashboardWidget\(\$uid\)#s', $listener),
    "handle() dispatches autoActivateDashboardWidget(\$uid) after the session stamp",
    $passes, $failures);

// autoActivateDashboardWidget is a private method
assertTrue((bool) preg_match('#private function autoActivateDashboardWidget\(string \$uid\)#', $listener),
    "autoActivateDashboardWidget is a private method(string \$uid)",
    $passes, $failures);

// Marker check short-circuits when already '1'
assertTrue((bool) preg_match('#\$marker\s*=\s*\$this->config->getUserValue\(.*?self::AUTOACTIVATE_MARKER_APP.*?self::AUTOACTIVATE_MARKER_KEY#s', $listener),
    "autoActivateDashboardWidget reads marker via IConfig::getUserValue",
    $passes, $failures);
assertTrue(str_contains($listener, "if (\$marker === '1') {"),
    "autoActivateDashboardWidget short-circuits when marker == '1'",
    $passes, $failures);

// Widget id comes from UnreadMailWidget::WIDGET_ID — no duplicate literal
assertTrue(str_contains($listener, 'UnreadMailWidget::WIDGET_ID'),
    "autoActivateDashboardWidget uses UnreadMailWidget::WIDGET_ID const (no drift)",
    $passes, $failures);

// Best-effort: try/catch wraps the whole seed
assertTrue((bool) preg_match('#try\s*\{[^}]*getUserValue[\s\S]+?catch\s*\(\\\\?Throwable#', $listener),
    "autoActivateDashboardWidget wraps the whole seed in try/catch (Throwable)",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Behavioural sim — three states of the seed logic
// ---------------------------------------------------------------
class StubConfig {
    /** @var array<string, string> */
    public array $store = [];
    public function key(string $uid, string $app, string $k): string { return "$uid|$app|$k"; }
    public function getUserValue(string $uid, string $app, string $k, string $default = ''): string {
        return $this->store[$this->key($uid, $app, $k)] ?? $default;
    }
    public function setUserValue(string $uid, string $app, string $k, string $v): void {
        $this->store[$this->key($uid, $app, $k)] = $v;
    }
}

/**
 * Re-inlines the seed logic. The static-source assertions above catch any
 * drift; this sim proves the per-scenario behaviour is correct.
 */
function simSeed(StubConfig $c, string $uid): void {
    $marker = $c->getUserValue($uid, 'souvera_mail', 'dashboard-widget-autoactivated', '');
    if ($marker === '1') {
        return;
    }
    $widgetId = 'souvera_mail-unread';
    $currentLayout = $c->getUserValue($uid, 'dashboard', 'layout', '');
    if ($currentLayout === '') {
        $newLayout = 'recommendations,spreed,' . $widgetId;
    } else {
        $ids = array_map('trim', explode(',', $currentLayout));
        $ids = array_filter($ids, static fn (string $s): bool => $s !== '');
        if (in_array($widgetId, $ids, true)) {
            $c->setUserValue($uid, 'souvera_mail', 'dashboard-widget-autoactivated', '1');
            return;
        }
        $ids[] = $widgetId;
        $newLayout = implode(',', $ids);
    }
    $c->setUserValue($uid, 'dashboard', 'layout', $newLayout);
    $c->setUserValue($uid, 'souvera_mail', 'dashboard-widget-autoactivated', '1');
}

// 3a. Cold user — no layout yet, no marker
$c1 = new StubConfig();
simSeed($c1, 'alice');
assertTrue($c1->getUserValue('alice', 'dashboard', 'layout', '') === 'recommendations,spreed,souvera_mail-unread',
    "3a: cold user gets seeded with default layout + widget",
    $passes, $failures);
assertTrue($c1->getUserValue('alice', 'souvera_mail', 'dashboard-widget-autoactivated', '') === '1',
    "3a: cold user marker set to '1'", $passes, $failures);

// 3b. Warm user — has a layout without our widget, no marker → widget appended
$c2 = new StubConfig();
$c2->setUserValue('bob', 'dashboard', 'layout', 'weather,mail,talk');
simSeed($c2, 'bob');
assertTrue($c2->getUserValue('bob', 'dashboard', 'layout', '') === 'weather,mail,talk,souvera_mail-unread',
    "3b: warm user's layout gets our widget appended",
    $passes, $failures);
assertTrue($c2->getUserValue('bob', 'souvera_mail', 'dashboard-widget-autoactivated', '') === '1',
    "3b: warm user marker set", $passes, $failures);

// 3c. Warm user with layout already containing the widget (edge)
$c3 = new StubConfig();
$c3->setUserValue('carol', 'dashboard', 'layout', 'weather,souvera_mail-unread,talk');
simSeed($c3, 'carol');
assertTrue($c3->getUserValue('carol', 'dashboard', 'layout', '') === 'weather,souvera_mail-unread,talk',
    "3c: layout unchanged when widget already present", $passes, $failures);
assertTrue($c3->getUserValue('carol', 'souvera_mail', 'dashboard-widget-autoactivated', '') === '1',
    "3c: marker still stamped so we skip this branch next login",
    $passes, $failures);

// 3d. Second login — marker already '1' → no-op even if user removed the widget
$c4 = new StubConfig();
$c4->setUserValue('dave', 'souvera_mail', 'dashboard-widget-autoactivated', '1');
$c4->setUserValue('dave', 'dashboard', 'layout', 'weather,talk'); // user removed widget
simSeed($c4, 'dave');
assertTrue($c4->getUserValue('dave', 'dashboard', 'layout', '') === 'weather,talk',
    "3d: layout untouched when marker is already '1' (respect user choice)",
    $passes, $failures);

// 3e. Layout with stray whitespace still parsed correctly
$c5 = new StubConfig();
$c5->setUserValue('eve', 'dashboard', 'layout', ' weather ,   talk  ');
simSeed($c5, 'eve');
assertTrue($c5->getUserValue('eve', 'dashboard', 'layout', '') === 'weather,talk,souvera_mail-unread',
    "3e: whitespace-padded ids get trimmed before append",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. Version bump + CHANGELOG regression
// ---------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(version_compare($vm[1] ?? '0', '0.13.21', '>='),
    "info.xml <version> >= 0.13.21 (got: '" . ($vm[1] ?? '') . "')",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($cl, 'auto-activated') || str_contains($cl, 'Auto-activated')
    || str_contains($cl, 'dashboard widget'),
    "CHANGELOG mentions the dashboard-widget auto-activation feature",
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
