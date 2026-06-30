<?php
/**
 * Regression tests for the three bugs reported live on 2026-02-17:
 *
 *  1. App-Password CREATE failed with "Creation failed" — the JS read
 *     `body.item.secret` while the controller returned `body.created.secret`.
 *
 *  2. "Verbundene Geräte" showed "Failed to load devices" because the
 *     ConnectedDevicesService blew up on tokens whose `getScopeAsArray()`
 *     or other getter threw / returned null (different NC token-provider
 *     implementations expose different surfaces).
 *
 *  3. The engine compose UI asked the user for a display name on first
 *     login because no default identity was seeded from the NC profile.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. App-Password CREATE — JS reads body.created.secret (not body.item)
// ---------------------------------------------------------------
$jsPath = '/app/app/smail/v/current/app/plugins/nextcloud/js/settings-account.js';
$js = file_get_contents($jsPath);

assertTrue(!preg_match("/body\.item\b/", $js),
    "settings-account.js does NOT read `body.item` (the shape the controller never returns)",
    $passes, $failures);
assertTrue(preg_match("/body\.created\b/", $js) === 1
        || str_contains($js, "var created = body.created || {};"),
    "settings-account.js reads `body.created` — matches AppPasswordController::create() response shape",
    $passes, $failures);
assertTrue(str_contains($js, 'created.secret'),
    "settings-account.js extracts `created.secret` for the one-time display",
    $passes, $failures);
assertTrue(str_contains($js, 'created.description'),
    "settings-account.js extracts `created.description` for the one-time display",
    $passes, $failures);
// Both error paths now surface body.message rather than a hard-coded literal —
// gives the user the actual server-side reason ("Stalwart refused…", "description
// must not be empty", etc.) instead of "Creation failed".
assertTrue(preg_match('/body\.message\s*\|\|\s*[\'"]?HTTP/', $js) === 1
        || str_contains($js, 'body.message || (\'HTTP \' + (r.body ? \'\' : \'error\')) || \'Creation failed\''),
    "settings-account.js falls back through body.message before showing 'Creation failed'",
    $passes, $failures);

// Back-end side: AppPasswordController returns { created: ... }, NOT { item: ... }
$ctrl = file_get_contents('/app/lib/Controller/AppPasswordController.php');
assertTrue(str_contains($ctrl, "'created' => \$created"),
    "AppPasswordController::create() returns { status, created }", $passes, $failures);
assertTrue(!preg_match("/['\"]item['\"]\s*=>\s*\\\$created/", $ctrl),
    "AppPasswordController::create() does NOT use the legacy 'item' key", $passes, $failures);

// ---------------------------------------------------------------
// 2. Connected Devices — service is now defensive on every token getter
// ---------------------------------------------------------------
$svc = file_get_contents('/app/lib/Service/ConnectedDevicesService.php');

foreach ([
    'safeName'         => 'getName() wrapper exists',
    'safeType'         => 'getType() wrapper exists',
    'safeLastActivity' => 'getLastActivity() wrapper exists',
    'safeScope'        => 'getScopeAsArray() wrapper exists',
] as $needle => $label) {
    assertTrue(str_contains($svc, "function $needle"),
        "ConnectedDevicesService — $label", $passes, $failures);
}

// safeScope must method_exists-guard before calling — that's the fix for
// NC token providers that don't ship getScopeAsArray() (the most likely
// cause of the live 500 reported by the operator).
$scopeStart = strpos($svc, 'function safeScope');
$scopeEnd = strpos($svc, "\n    }\n", $scopeStart);
$scopeBlock = substr($svc, $scopeStart, $scopeEnd - $scopeStart);
assertTrue(str_contains($scopeBlock, "method_exists(\$tok, 'getScopeAsArray')"),
    "safeScope() method_exists-guards before calling — handles NC token providers missing the method",
    $passes, $failures);
assertTrue(str_contains($scopeBlock, '} catch (\\Throwable $e) {'),
    "safeScope() catches Throwable from the call itself (token impl may throw on some scope kinds)",
    $passes, $failures);

// And the listForUser loop must skip un-readable token entries instead
// of failing the whole list:
assertTrue(str_contains($svc, "skipping unreadable token"),
    "listForUser() skips un-readable tokens with a logger warning, doesn't fail the whole call",
    $passes, $failures);

// JS surfaces the server message instead of the generic "Failed to load devices":
assertTrue(str_contains($js, "self.devicesError(body.message || 'Failed to load devices')"),
    "settings-account.js surfaces the server's body.message before 'Failed to load devices'",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Default identity seed — engine plugin writes one on first login
// ---------------------------------------------------------------
$plugin = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');

assertTrue(str_contains($plugin, 'seedDefaultIdentityFromNcProfile'),
    "Engine plugin defines seedDefaultIdentityFromNcProfile()", $passes, $failures);
assertTrue(str_contains($plugin, '$this->seedDefaultIdentityFromNcProfile($ocUser);'),
    "FilterAppData calls seedDefaultIdentityFromNcProfile(\$ocUser) on every payload build",
    $passes, $failures);

$seedStart = strpos($plugin, 'function seedDefaultIdentityFromNcProfile');
$seedEnd = strpos($plugin, "\n\t}\n", $seedStart);
$seed = substr($plugin, $seedStart, $seedEnd - $seedStart);

// Must read the existing identities and bail when one is already stored —
// never overwrite a user-edited identity.
assertTrue(str_contains($seed, "\$storage->Get(\$account, \$type, 'identities')"),
    "seedDefaultIdentityFromNcProfile reads the existing identities from storage first",
    $passes, $failures);
assertTrue(str_contains($seed, 'if (\\is_array($existing) && !empty($existing))') ||
           preg_match('/is_array\\(\\$existing\\)\\s*&&\\s*!empty\\(\\$existing\\)/', $seed) === 1,
    "seedDefaultIdentityFromNcProfile leaves existing identities untouched (no overwrite)",
    $passes, $failures);

// Must use the NC profile display name (not getEMailAddress, not UID):
assertTrue(str_contains($seed, '$ocUser->getDisplayName()'),
    "seedDefaultIdentityFromNcProfile sources the name from \$ocUser->getDisplayName()",
    $passes, $failures);

// Empty display name → no-op (we never seed an identity with no name)
assertTrue((bool)preg_match("/\\\$displayName\\s*===\\s*''/", $seed),
    "seedDefaultIdentityFromNcProfile bails on empty NC display name", $passes, $failures);

// Must use account email — never UID:
assertTrue(str_contains($seed, '$account->Email()'),
    "seedDefaultIdentityFromNcProfile uses the account's resolved email (never the NC UID)",
    $passes, $failures);

// Wraps in try/catch — never breaks the engine boot:
assertTrue(str_contains($seed, '} catch (\\Throwable $e) {'),
    "seedDefaultIdentityFromNcProfile swallows any Throwable (never breaks boot)",
    $passes, $failures);

// Shape mirrors Identity::ToSimpleJSON() — at minimum these fields:
foreach (['Email', 'Name', 'ReplyTo', 'Bcc', 'Signature'] as $k) {
    assertTrue(str_contains($seed, "'$k'"),
        "Identity seed includes the '$k' field (mirrors Identity::ToSimpleJSON shape)",
        $passes, $failures);
}

// ---------------------------------------------------------------
// 4. UI polish — settings template uses the new card-based layout
// ---------------------------------------------------------------
$tpl = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html');
foreach ([
    'class="souvera-settings"' => 'top-level scoping class for CSS',
    'sv-card'                  => 'card layout class',
    'sv-card-h'                => 'card header class',
    'sv-card-b'                => 'card body class',
    'sv-option'                => 'radio-card class',
    'sv-btn-primary'           => 'primary action button class',
    '<style>'                  => 'inline <style> block scoped to the template',
] as $needle => $label) {
    assertTrue(str_contains($tpl, $needle),
        "Template — $label", $passes, $failures);
}

// All previous Knockout bindings are still in place — no functional regression
foreach ([
    'foreach: appPasswords',
    'foreach: devices',
    'click: createAppPassword',
    'click: signOutOthers',
    'click: $root.revokeAppPassword',
    'click: $root.revokeDevice',
    'checked: dashboardMode',
] as $b) {
    assertTrue(str_contains($tpl, $b),
        "Template still binds: " . $b, $passes, $failures);
}

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
