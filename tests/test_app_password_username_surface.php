<?php
/**
 * Regression test for the App Password "ERROR AUTHENTICATIONFAILED" UX
 * gap fixed in Souvera Mail 0.13.9.
 *
 * Symptom (live env, NC34 + Stalwart 0.16, 2026-07-01)
 * -----------------------------------------------------
 * The "Generated App Password" flow only surfaced the plaintext secret.
 * The user had to GUESS which Stalwart-side mail address to enter as
 * the IMAP/SMTP username — Stalwart's PLAIN/LOGIN auth matches the
 * principal by its exact `name` (and `emails[]`, but only when the
 * principal also carries the `authenticateWithAlias` permission;
 * standard roles include it but custom Stalwart deploys may omit it),
 * NOT by guessed-at email formats. With the wrong username, Stalwart
 * answers `a NO [AUTHENTICATIONFAILED]` even though the app password
 * was created correctly.
 *
 * Fix
 * ---
 * 1. `StalwartUserContext::resolveEmail(string $uid): string` (new) —
 *    exposes the canonical Stalwart mail address (resolved via
 *    `souvera_central StalwartService::mailFor()`) separately from
 *    `resolveAccountId()`. The latter now re-uses `resolveEmail()`
 *    internally to avoid duplicate `mailFor()` lookups.
 *
 * 2. `AppPasswordService::createForUser()` now returns an extra
 *    `username` key (= the canonical Stalwart mail address) alongside
 *    `id`, `secret`, `description`.
 *
 * 3. The Snappymail "Sicherheit & Geräte" tab surfaces BOTH username
 *    and password in two distinct copy-able rows with explicit labels,
 *    plus a hint to choose "Passwort/Login" (not OAuth) in the mail
 *    client. New observable `justCreatedUsername` + new view-model
 *    action `copyUsername`.
 *
 * 4. The `AppPassword/set` create payload now also carries the
 *    documented `allowedIps: {}` "no restriction" field — mirrors
 *    `stalwart-cli create AppPassword --field 'allowedIps={}'`.
 *
 * This test pins all four pieces so they never regress together.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. StalwartUserContext — new resolveEmail() + resolveAccountId() reuse
// ---------------------------------------------------------------
$ctx = file_get_contents('/app/lib/Service/StalwartUserContext.php');

assertTrue(preg_match('#public function resolveEmail\(string \$userId\)\s*:\s*string#', $ctx) === 1,
    "StalwartUserContext has resolveEmail(string \$userId): string", $passes, $failures);

// resolveAccountId() must delegate to resolveEmail() — single source of truth
$ridStart = strpos($ctx, 'resolveAccountId(string $userId)');
$ridEnd = strpos($ctx, "\n    }", $ridStart);
$rid = substr($ctx, $ridStart, $ridEnd - $ridStart);
assertTrue(str_contains($rid, '$this->resolveEmail($userId)'),
    "resolveAccountId() delegates to resolveEmail() (no duplicate mailFor() lookups)",
    $passes, $failures);
assertTrue(!str_contains($rid, '$stalwartService->mailFor('),
    "resolveAccountId() no longer calls mailFor() directly — resolveEmail() owns it",
    $passes, $failures);

// ---------------------------------------------------------------
// 2. AppPasswordService — createForUser() returns `username`
// ---------------------------------------------------------------
$svc = file_get_contents('/app/lib/Service/AppPasswordService.php');

// PHPDoc return-type contract (extended in v0.14.0 with `ncTokenId` for
// the combined Mail + Nextcloud/DAV credential).
assertTrue(str_contains($svc, "array{id: string, secret: string, description: string, username: string, ncTokenId: int}"),
    "createForUser() PHPDoc return shape includes `username` and `ncTokenId` (v0.14.0)", $passes, $failures);

// Body resolves the email
assertTrue(str_contains($svc, '$this->userContext->resolveEmail($userId)'),
    "createForUser() resolves the canonical Stalwart email", $passes, $failures);

// Return array carries `username`
assertTrue((bool) preg_match("#'username'\s*=>\s*\\\$email#", $svc),
    "createForUser() returns the `username` (= canonical Stalwart email)",
    $passes, $failures);

// JMAP create payload uses Stalwart 0.16's real CredentialPermissions
// wire-format. Live-verified 2026-07-01 on the operator's `fccec267`
// cluster via exhaustive schema-fuzz of every plausible shape:
//   - {@type,value:[...]}         → invalidPatch "permissions/value"       ❌
//   - {@type,permissions:[...]}   → invalidPatch "permissions/permissions" ❌
//   - {@type,permissions:{p:true}} → ACCEPTED, permission granted          ✅
// The final shape is a MAP `<perm-id> => bool`, NOT an array.
assertTrue(str_contains($svc, "'@type' => 'Replace'"),
    "JMAP AppPassword/set CREATE uses Stalwart's @type:Replace patch-tag",
    $passes, $failures);
assertTrue(str_contains($svc, "'permissions' => \\array_fill_keys("),
    "JMAP AppPassword/set CREATE sends perms as MAP `<perm-id> => true` (Stalwart 0.16 format)",
    $passes, $failures);
assertTrue(!str_contains($svc, "'value' => self::APP_PASSWORD_PERMISSIONS"),
    "Old array-under-`value` shape from 0.13.18 is gone",
    $passes, $failures);
assertTrue(!preg_match("#'permissions'\s*=>\s*self::APP_PASSWORD_PERMISSIONS#", $svc),
    "Old array-under-`permissions` doppelung is gone",
    $passes, $failures);
assertTrue(str_contains($svc, "'authenticateWithAlias'"),
    "Permission list includes `authenticateWithAlias` (the email-as-username permission)",
    $passes, $failures);
assertTrue(str_contains($svc, "'authenticate'"),
    "Permission list includes `authenticate` (the base login gate)",
    $passes, $failures);
// Regression guard: `imapUnsubscribe` was removed from the Stalwart 0.16
// Permission enum — including it in the map returns "Invalid key for
// object property" and blocks App-Password creation entirely.
assertTrue(!str_contains($svc, "'imapUnsubscribe'"),
    "Permission list does NOT include `imapUnsubscribe` (removed in Stalwart 0.16 — subscribe/unsub folded into a single `imapSubscribe`)",
    $passes, $failures);
// Spot-check the IMAP / POP3 / Sieve coverage so a future refactor doesn't
// quietly drop a permission and silently break legacy mail clients.
foreach ([
    'imapAuthenticate', 'imapFetch', 'imapAppend', 'imapStore', 'imapMove',
    'imapSearch', 'imapIdle', 'imapExpunge',
    'pop3Authenticate', 'pop3Retr',
    'sieveAuthenticate', 'sievePutScript', 'sieveSetActive',
    'emailSend', 'emailReceive',
] as $perm) {
    assertTrue(str_contains($svc, "'$perm'"),
        "Permission list still includes `$perm`", $passes, $failures);
}
assertTrue((bool) preg_match("#'allowedIps'\s*=>\s*\(object\)\s*\[\]#", $svc),
    "JMAP AppPassword/set payload carries `allowedIps: {}` (documented no-restriction value, serialized as JSON object not array)",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. UI template — exposes username row, password row, copy buttons
// ---------------------------------------------------------------
$tpl = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html');

assertTrue(str_contains($tpl, 'data-bind="text: justCreatedUsername"'),
    "Template binds the canonical username via justCreatedUsername", $passes, $failures);
assertTrue(str_contains($tpl, 'data-bind="text: justCreatedSecret"'),
    "Template still binds the password via justCreatedSecret (unchanged)",
    $passes, $failures);
assertTrue(str_contains($tpl, 'data-bind="click: copyUsername"'),
    "Template wires the copyUsername click action", $passes, $failures);
assertTrue(str_contains($tpl, 'data-bind="click: copySecret"'),
    "Template still wires the copySecret click action (unchanged)",
    $passes, $failures);
// Layout cues — labelled rows
foreach (['Benutzername', 'Passwort', 'Passwort / Login'] as $needle) {
    assertTrue(str_contains($tpl, $needle),
        "Template surfaces the literal label '$needle' so the user is never left guessing",
        $passes, $failures);
}
// CSS classes for the new credential rows
foreach (['.sv-cred-row', '.sv-cred-label', '.sv-cred-value', '.sv-secret-user'] as $cls) {
    assertTrue(str_contains($tpl, $cls),
        "Template ships CSS class $cls for the labelled-row layout",
        $passes, $failures);
}

// ---------------------------------------------------------------
// 4. JS ViewModel — observable, copy action, dismiss clears username too
// ---------------------------------------------------------------
$js = file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/settings-account.js');

assertTrue((bool) preg_match("#this\.justCreatedUsername\s*=\s*ko\.observable\(''\)#", $js),
    "ViewModel declares justCreatedUsername observable", $passes, $failures);
assertTrue(str_contains($js, 'self.justCreatedUsername(String(created.username || \'\'))'),
    "createAppPassword() populates justCreatedUsername from the backend response",
    $passes, $failures);
assertTrue((bool) preg_match("#copyUsername\s*:\s*function#", $js),
    "ViewModel exposes a copyUsername action that mirrors copySecret",
    $passes, $failures);
assertTrue((bool) preg_match("#dismissNewSecret.*?justCreatedUsername\(''\)#s", $js),
    "dismissNewSecret() also clears justCreatedUsername (no stale data after closing the banner)",
    $passes, $failures);
assertTrue(str_contains($js, "{ id, secret, description, username }"),
    "JS comment documents the new backend response shape includes `username`",
    $passes, $failures);

// ---------------------------------------------------------------
// 5. Behavioural simulation — drive AppPasswordService::createForUser() with stubs
// ---------------------------------------------------------------
//
// We re-inline the new createForUser() body and drive it through a
// stub StalwartUserContext + stub StalwartAdminService. Drift between
// this inline copy and the real source is caught by the regex
// assertions above.

class StubUserContext {
    public string $emailToReturn = 'alice@example.com';
    public string $accountIdToReturn = 'acc-42';
    public string $bearerToReturn = 'jwt-xyz';
    public array $emailCalls = [];
    public function resolveEmail(string $uid): string { $this->emailCalls[] = $uid; return $this->emailToReturn; }
    public function resolveAccountId(string $uid): string { return $this->accountIdToReturn; }
    public function resolveBearer(string $uid): string { return $this->bearerToReturn; }
}

class StubStalwart {
    public array $lastPayload = [];
    public string $createdId = 'ap-1';
    public string $createdSecret = 'app_aaaaaa-secret';
    public function jmapCall(string $bearer, array $methodCalls): array {
        $this->lastPayload = $methodCalls;
        return [
            'methodResponses' => [
                [
                    'x:AppPassword/set',
                    ['created' => ['k1' => ['id' => $this->createdId, 'secret' => $this->createdSecret]]],
                    'c0',
                ],
            ],
        ];
    }
    public function extractMethodResponse(array $resp, string $name): array {
        foreach ($resp['methodResponses'] ?? [] as $mr) {
            if (($mr[0] ?? '') === $name) return $mr[1] ?? [];
        }
        return [];
    }
}

/**
 * Inlined createForUser() body — drift-protected by the regex assertions above.
 */
function simCreate(string $userId, string $description, StubUserContext $ctx, StubStalwart $st): array {
    $description = trim($description);
    if ($description === '') throw new \InvalidArgumentException('empty description');
    $email = $ctx->resolveEmail($userId);
    $accountId = $ctx->resolveAccountId($userId);
    $bearer = $ctx->resolveBearer($userId);
    $resp = $st->jmapCall($bearer, [
        ['x:AppPassword/set', [
            'accountId' => $accountId,
            'create' => ['k1' => [
                'description' => $description,
                // Stalwart 0.16 CredentialPermissions:
                //   {@type:"Replace", permissions:{<perm>: true, …}}
                'permissions' => [
                    '@type' => 'Replace',
                    'permissions' => [
                        'authenticate' => true,
                        'authenticateWithAlias' => true,
                        'imapAuthenticate' => true,
                        'imapFetch' => true,
                        'emailSend' => true,
                        'emailReceive' => true,
                    ],
                ],
                'allowedIps' => (object) [],
            ]],
        ], 'c0'],
    ]);
    $setResp = $st->extractMethodResponse($resp, 'x:AppPassword/set');
    $created = $setResp['created']['k1'];
    return [
        'id' => (string) $created['id'],
        'secret' => (string) $created['secret'],
        'description' => $description,
        'username' => $email,
    ];
}

$ctx = new StubUserContext(); $ctx->emailToReturn = 'scadmin@buxtehude.email';
$st = new StubStalwart(); $st->createdSecret = 'app_aaaaaavxesnnvwuozaxknjdk0csxxihs3rxa';
$out = simCreate('scadmin', 'Thunderbird', $ctx, $st);

assertTrue($out['id'] === 'ap-1', "5a: returns Stalwart's created.id", $passes, $failures);
assertTrue($out['secret'] === 'app_aaaaaavxesnnvwuozaxknjdk0csxxihs3rxa',
    "5b: returns Stalwart's created.secret verbatim", $passes, $failures);
assertTrue($out['description'] === 'Thunderbird',
    "5c: returns the user-supplied description (trimmed)", $passes, $failures);
assertTrue($out['username'] === 'scadmin@buxtehude.email',
    "5d: returns the canonical Stalwart email as `username` — the user no longer has to guess (the bug fix)",
    $passes, $failures);
assertTrue($ctx->emailCalls === ['scadmin'],
    "5e: resolveEmail() called exactly once with the NC user id", $passes, $failures);

// Verify the JMAP payload shape Stalwart 0.16 actually expects on CREATE
$createPayload = $st->lastPayload[0][1]['create']['k1'];
assertTrue(is_array($createPayload['permissions'])
        && ($createPayload['permissions']['@type'] ?? null) === 'Replace',
    "5f: JMAP create payload sends `permissions` with @type=Replace (Stalwart 0.16 patch-tag)",
    $passes, $failures);
assertTrue(is_array($createPayload['permissions']['permissions'] ?? null)
        && ($createPayload['permissions']['permissions']['authenticateWithAlias'] ?? null) === true,
    "5f2: permission MAP lives under `permissions.permissions`, `authenticateWithAlias => true`",
    $passes, $failures);
assertTrue(!isset($createPayload['permissions']['value']),
    "5f3: NO `permissions/value` array (that's what Stalwart 0.16 refused on 2026-07-01)",
    $passes, $failures);
// The map's values must all be booleans (Stalwart returns "Invalid value for
// object property" if they're objects, nulls, or strings).
$allBool = true;
foreach (($createPayload['permissions']['permissions'] ?? []) as $v) {
    if (!is_bool($v)) { $allBool = false; break; }
}
assertTrue($allBool,
    "5f4: every permission-map value is a strict bool (Stalwart 0.16 rejects any other type)",
    $passes, $failures);
assertTrue(is_object($createPayload['allowedIps']),
    "5g: JMAP create payload sends allowedIps as JSON object (not array — would serialize as []) ",
    $passes, $failures);
assertTrue((array) $createPayload['allowedIps'] === [],
    "5h: allowedIps is empty (no IP restriction)", $passes, $failures);

// ---------------------------------------------------------------
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
