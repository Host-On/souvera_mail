<?php
/**
 * Regression test for the Symfony InvalidParameterException reported
 * against `/index.php/apps/souvera_mail/settings` on 2026-06-29:
 *
 *     Parameter "id" for route "souvera_mail.connecteddevices.destroy"
 *     must match "\d+" ("__ID__" given) to generate a corresponding URL.
 *
 * Symfony's UrlGenerator validates the route `requirements` regex
 * *at generation time*, not only at routing time. So
 * `linkToRoute('souvera_mail.connectedDevices.destroy', ['id' => '__ID__'])`
 * blows up with InvalidParameterException because `__ID__` is not `\d+`.
 *
 * The fix builds the template by string concatenation
 * (`linkToRoute('...connectedDevices.index') . '/__ID__'`), which
 * skips the URL-generator check while keeping the server-side `\d+`
 * constraint (enforced at routing time, when the JS substitutes a
 * real numeric token id).
 *
 * This test ensures we never regress to the broken call pattern.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void
{
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$src = file_get_contents('/app/lib/Controller/SettingsController.php');

// 1. The forbidden patterns are NOT used any more.
//    These are exactly the calls that crash Symfony.
assertTrue(!preg_match("#linkToRoute\(\s*'souvera_mail\.connectedDevices\.destroy'\s*,\s*\[\s*'id'\s*=>\s*'__ID__'\s*\]\s*\)#", $src),
    "SettingsController does NOT call linkToRoute('connectedDevices.destroy', ['id' => '__ID__']) — that crashes Symfony",
    $passes, $failures);

// 2. The destroy-URL template is built by string concatenation.
assertTrue(str_contains($src, "linkToRoute('souvera_mail.connectedDevices.index') . '/__ID__'"),
    "SettingsController builds connectedDevices destroy template via index-URL + '/__ID__'",
    $passes, $failures);

// 3. The same fix is applied to appPasswords for consistency.
assertTrue(str_contains($src, "linkToRoute('souvera_mail.appPassword.index') . '/__ID__'"),
    "SettingsController builds appPasswords destroy template via index-URL + '/__ID__'",
    $passes, $failures);

// 4. routes.php still enforces `\d+` on /connected-devices/{id} — the
//    server-side constraint must NOT be removed as part of this fix.
$routesSrc = file_get_contents('/app/appinfo/routes.php');
$routes = require '/app/appinfo/routes.php';
$destroyRoute = null;
foreach ($routes['routes'] as $r) {
    if (($r['name'] ?? '') === 'connectedDevices#destroy') { $destroyRoute = $r; break; }
}
assertTrue($destroyRoute !== null, "routes.php has connectedDevices#destroy", $passes, $failures);
assertTrue(($destroyRoute['requirements']['id'] ?? '') === '\d+',
    "connectedDevices#destroy still constrains id to \\d+ (server-side)",
    $passes, $failures);

// 5. Behavioural sim — confirm the new template string mid-replace
//    yields a route-matching URL.
//
//    Given the template `/index.php/apps/souvera_mail/connected-devices/__ID__`,
//    the JS does `template.replace('__ID__', tokenId)`. We simulate that here.
$template = '/index.php/apps/souvera_mail/connected-devices/__ID__';
$result   = str_replace('__ID__', '42', $template);
assertTrue($result === '/index.php/apps/souvera_mail/connected-devices/42',
    "Template substitution yields the expected URL", $passes, $failures);

// 6. The pattern that Symfony will match against the destroy route
//    (`/connected-devices/{id}` with `id` constrained to `\d+`) is
//    satisfied by a numeric replacement.
assertTrue(preg_match('#/connected-devices/\d+$#', $result) === 1,
    "Numeric token id matches the `\\d+` route constraint after substitution",
    $passes, $failures);

// 7. Test that the JS side actually does `.replace('__ID__', …)`
//    so the template-string fix has an actual consumer.
$psJs = file_get_contents('/app/js/personal-settings.js');
assertTrue(str_contains($psJs, "'__ID__'") || str_contains($psJs, '"__ID__"'),
    "personal-settings.js uses the '__ID__' placeholder for substitution",
    $passes, $failures);
assertTrue(preg_match("#replace\(\s*['\"]__ID__['\"]\s*,#", $psJs) === 1
        || preg_match("#\.replace\(\s*['\"]__ID__['\"]#", $psJs) === 1,
    "personal-settings.js calls .replace('__ID__', …) on a URL template",
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
