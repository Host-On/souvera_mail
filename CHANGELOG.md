# Changelog

All notable changes to Souvera Mail will be documented in this file.

Format: [Semantic Versioning](https://semver.org/) — MAJOR.MINOR.PATCH

## [Unreleased]

## [0.13.9] — 2026-02-17

### Fixed (P0 — generated App Passwords failed IMAP login with `AUTHENTICATIONFAILED`, reported 2026-07-01)
- **Generated App Passwords now work on the first try with legacy IMAP/SMTP clients.** The plaintext secret returned by Stalwart's `x:AppPassword/set` JMAP call was correct, the documented `{'@type': 'Inherit'}` permissions payload was correct, but the UI only surfaced the *password* — never the *username*. The user had to guess which Stalwart-side mail address to enter and a guess like `<user>@<NC-domain>` would fail because Stalwart's PLAIN/LOGIN auth matches the principal by its canonical `name` and `emails[]` (and only falls back to alias lookup when the principal carries the `authenticateWithAlias` permission, which standard roles include but custom Stalwart deploys may omit). Stalwart's response was the bare `a NO [AUTHENTICATIONFAILED]` shown in the operator's reproduction.

### Architecture
- **`lib/Service/StalwartUserContext.php`**:
  - New `resolveEmail(string $userId): string` exposes the canonical Stalwart mail address (resolved via souvera_central's `StalwartService::mailFor()`) without re-issuing the `findAccountId()` round-trip.
  - `resolveAccountId()` now delegates the mail-address lookup to `resolveEmail()` — single source of truth, no duplicate `mailFor()` calls per request.
- **`lib/Service/AppPasswordService.php`**:
  - `createForUser()` return shape extended from `{id, secret, description}` to `{id, secret, description, username}`. The `username` is the canonical Stalwart mail address — exactly what the user must paste into the IMAP/SMTP client's "Username" field.
  - JMAP create payload now also carries `allowedIps: {}` (the documented "no IP restriction" value, serialized as a JSON object via `(object) []` so json_encode emits `{}` not `[]` — Stalwart's deserializer rejects the array form).
- **Snappymail "Sicherheit & Geräte" tab** (`SettingsSouveraAccount.html` + `settings-account.js`):
  - New `justCreatedUsername` observable + `copyUsername` view-model action.
  - The post-create banner now renders TWO labelled rows (`Benutzername` / `Passwort`) each with its own copy button, plus a hint to choose "Passwort / Login" (not OAuth) in the mail client.
  - `dismissNewSecret()` also clears `justCreatedUsername` — no stale data after closing.
  - New CSS classes `.sv-cred-row`, `.sv-cred-label`, `.sv-cred-value`, `.sv-secret-user`, `.sv-btn-sm` keep the new layout confined to the tab.

### Verification
- `php -l` clean on both modified PHP files.
- **`tests/test_app_password_username_surface.php`: 32/32 PASS** (NEW). Static-source contract on all four touched files + a behavioural simulation that drives `createForUser()` end-to-end against stub `StalwartUserContext` + stub `StalwartAdminService`. Verifies:
  - `resolveAccountId()` delegates to `resolveEmail()` (no duplicate lookups).
  - Return array carries `username` = canonical Stalwart email.
  - JMAP payload carries `permissions: {@type: Inherit}` and `allowedIps: {}` (serialized as JSON object, not array).
  - UI template renders both labelled rows with copy buttons.
  - ViewModel observable + `copyUsername` action + `dismissNewSecret` reset.
- Full local suite **497/497 PASS** across 15 test files (was 465/465 across 14). Zero regressions.

### Operator action
If your Stalwart deploy uses a custom role that omits `authenticateWithAlias` AND the principal's `name` differs from its primary `email`, you may still see `AUTHENTICATIONFAILED` when entering the email shown by Souvera Mail — paste the principal's `name` instead, or grant `authenticateWithAlias` to the role (recommended: it is part of Stalwart's default user role).

## [0.13.8] — 2026-02-17

### Fixed (P0 — `Connected Devices` runtime TypeError on NC34, reported 2026-07-01)
- **The "Verbundene Geräte" tab no longer crashes with `OC\Authentication\Token\Manager::getTokenByUser(): Argument #1 ($uid) must be of type string, OC\User\User given`.** Nextcloud 34 tightened the `OCP\Authentication\Token\IProvider` contract: both `getTokenByUser()` and `invalidateTokenById()` now take `string $uid` (previously `IUser $user`). Our `ConnectedDevicesService` was still forwarding the `IUser` instance returned from `requireUser()` — strict typing rejected it at runtime, the Settings tab rendered empty after the head row.
- **All four token-provider call sites in `lib/Service/ConnectedDevicesService.php` now pass `$user->getUID()` (the canonical UID string).** `requireUser()` is kept (it still validates that the user exists), but its return value is only used for `getUID()` — never forwarded to NC's token API.

### Fixed (P1 — `Settings → Filters` showed generic `ERROR 1`, reported 2026-07-01)
- **Opening the in-engine Filters tab no longer produces `ERROR 1`.** Root cause traced to `occ souvera_mail:setup`: the `--sieve` flag was declared as `VALUE_NONE` (opt-in, default false), so any operator who didn't pass `--sieve` shipped a domain config with `Sieve.enabled = false`. The engine's `Actions::Capa()` then resolved `Capa::SIEVE → false`, `DoFilters()` returned `FalseResponse()`, and the frontend rendered the catch-all `Notifications::RequestError = 1` ("ERROR 1").
- **`--sieve` is now `VALUE_NEGATABLE` and defaults to `true`.** Stalwart 0.16 ships ManageSieve on port 4190 natively and accepts the exact same OAUTHBEARER + H2CK JWT as IMAP/SMTP — there is no scenario where shipping a Stalwart profile with IMAP+SMTP on but Sieve off is useful. Operators who want Sieve off pass `--no-sieve` explicitly. Existing deploys can pick the fix up with a no-arg re-run: `occ souvera_mail:setup --imap-host <…> --domain <…>` — every other previously-set option is preserved via the existing defaults.

### Architecture
- **`lib/Service/ConnectedDevicesService.php`**:
  - `listForUser()`: `getTokenByUser($uid)` (was `$user`).
  - `revoke()`: `invalidateTokenById($uid, $tokenId)` (was `$user`).
  - `revokeAllOthers()`: `getTokenByUser($uid)` + `invalidateTokenById($uid, $id)` on every iteration (was `$user`).
  - `requireUser()` unchanged — still validates existence; callers extract `$uid = $user->getUID()` once at the top and forward it to all token-provider calls.
- **`lib/Command/Setup.php`**:
  - `--sieve` flag: `InputOption::VALUE_NONE` → `InputOption::VALUE_NEGATABLE`, default `true`. Help text rewritten to mention Stalwart 0.16's native ManageSieve and the `--no-sieve` opt-out.
  - `execute()`: `$sieveEnabled = (bool) $input->getOption('sieve');` → `$sieveEnabled = (bool) ($input->getOption('sieve') ?? true);` (honours the default when the operator passes neither `--sieve` nor `--no-sieve`).

### Verification
- `php -l` clean on both modified files.
- **`tests/test_connected_devices.php`: 78/78 PASS** (was 71). Three new static-source assertions pin the four token-provider call sites + reject any regression that would re-introduce the `$user` instance pass. Stub `IProvider` interface + `StubTokenProvider::getTokenByUser/invalidateTokenById` updated to the NC34 string-typed signature; two new behavioural assertions record `uidsSeen` and verify the canonical UID string `'alice'` is forwarded on every call (and the `IUser` instance never is).
- **`tests/test_sieve_default_on.php`: 13/13 PASS** (NEW). Static-source contract on the `--sieve` flag shape + the `?? true` default expression + help-text mentions + DomainConfigService wiring (`Sieve.enabled => $sieve` still flows through OAUTHBEARER), plus a 3-state behavioural simulation of Symfony Console's `VALUE_NEGATABLE` resolution: `--sieve`, `--no-sieve`, and neither-flag (the default-on regression).
- Full local PHP test suite: **463/463 PASS** across 14 test files (was 445/445 across 13). Zero regressions.

## [0.13.7] — 2026-02-17

### Fixed (P0 — IMAP `AUTHENTICATIONFAILED` after 15 min, reported 2026-07-01)
- **Long-lived IMAP / SMTP / Sieve sessions no longer fail with `AUTHENTICATIONFAILED` once the OIDC access token's 15 min TTL elapses.** H2CK/oidc issues 15 min JWTs. Souvera Mail cached them in NC's distributed cache. On reconnects (dashboard widget refresh, cron sync, background engine-token-cookie reconnect, Sieve-from-CLI) the cached entry was returned blindly even when `exp - now` was already at or past 0. Different cache backends (Redis, APCu, Memcached, NoLocal) honour TTLs with subtly different semantics; combined with clock drift between NC and Stalwart this opened a multi-second window in which the cache would hand out a token Stalwart immediately rejected with `ExpiredSignature`. Verified end-to-end by the operator against Stalwart 0.16 trace logs.
- **`OidcProviderService::generateAccessToken()` now re-validates the cached JWT's `exp` claim on every cache hit.** The cache TTL is treated as a coarse hint, not a contract:
  - cached JWT with `exp - now >= 60 s` → safe, returned verbatim;
  - cached JWT with `exp - now <  60 s` → cache entry actively evicted, fresh JWT minted via `OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent`;
  - opaque (non-JWT) cached token → trusted (no `exp` claim available; legacy H2CK pre-1.x).
- **`extractCacheTtl()` no longer silently extends a near-expired token's life by 60 s.** A parsed JWT with non-positive remaining lifetime now returns TTL=0 (do-not-cache) instead of the `FALLBACK_TTL_SECONDS` fallback. The 60 s fallback is reserved exclusively for opaque tokens (where `extractJwtExp()` returned `null`).
- `generateAccessToken()` skips `$cache->set()` entirely when the freshly minted token would be cached for ≤ 0 s — avoids ever persisting a token whose remaining usable life is below the safety margin.

### Architecture
- **`lib/Service/OidcProviderService.php`**:
  - New private `extractJwtExp(string $jwt): ?int` — parses the JWT payload's `exp` claim without verifying the signature (Stalwart re-verifies on its side).
  - New private `isJwtStillSafe(string $jwt): bool` — composes `extractJwtExp()` with `CACHE_SAFETY_MARGIN_SECONDS = 60`; opaque tokens default to safe.
  - `extractCacheTtl()` refactored on top of `extractJwtExp()`. Returns 0 for parsed JWTs with non-positive remaining lifetime, `FALLBACK_TTL_SECONDS` only when the payload was opaque/unparseable.
  - `generateAccessToken()` runs `isJwtStillSafe()` on every cache hit and removes the stale entry before falling through to the dispatcher.

### Verification
- `php -l` clean on `lib/Service/OidcProviderService.php`.
- **`tests/test_oidc_token_refresh.php`: 25/25 PASS** (NEW). Static-source contract assertions on `isJwtStillSafe()`, `extractJwtExp()`, `generateAccessToken()` and `extractCacheTtl()`, plus a 7-state behavioural simulation driven by a stub distributed cache + stub H2CK dispatcher:
  - 2a cold cache: mint once, cache with TTL ≈ `exp - 60 s`;
  - 2b warm hit with safe JWT: no re-mint;
  - 2c warm hit with `exp - now < 60 s`: evict + re-mint (the actual bug fix);
  - 2d warm hit with already-past `exp`: evict + re-mint;
  - 2e fresh mint of near-expired upstream token: returned but NOT cached;
  - 2f opaque token: cached for `FALLBACK_TTL_SECONDS`;
  - 2g empty uid: bails out without touching dispatcher or cache.
- Regression: full local test suite still green — **445/445 PASS** across 13 test files (was 420/420 across 12).

## [0.13.6] — 2026-02-17

### Fixed (P0 — NC34 guest-page TypeError, reported 2026-06-30)
- **`/login`, public-share pages and every other guest-rendered page no longer 500 with `TypeError: IAppManager::isEnabledForUser() expects parameter 1 to be string, null given`.** The crash chain: NC34's `NavigationManager::add()` (called by `init()` while resolving registered closure entries) does `$id = $entry['id']` followed by `isEnabledForUser($id)` (strict `string $appId` since NC30+). Our pre-0.13.6 navigation closure returned `[]` for the pre-auth case (no NC user) and the out-of-group case (user not in `souvera-users`) — `$entry['id']` was therefore `null`, the strict type-check threw, the InitialStateService rendering inside `layout.guest.php` died, every guest page along with it.
- **The closure now never returns `[]`.** The user-presence + group-membership gate is moved *out* of the closure into `Application::boot()`'s body. When the gate fails, the closure is simply not registered — there is no poisoned empty array left for `NavigationManager::init()` to trip over later. When the gate passes, the closure unconditionally returns the full 5-key payload (`id`, `name`, `href`, `icon`, `order`).

### Architecture
- **`lib/AppInfo/Application.php` :: `boot()`** rewritten:
  - Old: `$navigationManager->add(function () { … if ($user === null) return []; if (!$appManager->isEnabledForUser(…)) return []; return [/* full entry */]; })`
  - New: branch BEFORE `add()`:
    - `if ($user === null) { return; }` — pre-auth: nothing registered, guest pages crash-safe.
    - `if (!$appManager->isEnabledForUser(self::APP_ID, $user)) { return; }` — out-of-group: nothing registered.
    - Otherwise: `$navigationManager->add(closure)` with a closure that ALWAYS returns the full payload, no `return [];` anywhere.

### Verification
- `php -l` clean on `lib/AppInfo/Application.php`.
- info.xml hygiene re-audited: no empty `<settings/>`, `<repair-steps/>`, `<navigations/>`, `<background-jobs/>`, `<commands/>`, `<sabre/>`, `<collaboration/>`, `<two-factor-providers/>`, `<public/>`, `<activity/>`, `<trash/>`, `<types/>` containers (those crash NC's InfoParser cluster-wide per the operator's Shield v2.0.0 → 2.0.1 incident). All container elements present in info.xml carry children. We deliberately ship NO `<navigations>` element — navigation is registered programmatically because the group gate needs runtime access to `IUserSession`.
- **`tests/test_guest_page_navigation_crash.php`: 41/41 PASS** (NEW). Static assertions on the new `boot()` shape + info.xml hygiene + version, plus a 3-state behavioural sim with stub `IUserSession` / `IAppManager` / `INavigationManager`. The stub `INavigationManager::add()` mirrors NC34's `$id = $entry['id']` validation and would itself throw TypeError on any empty-array regression — that's the regression guard. Sim states:
  - 5a **pre-auth (no user)**: no closure registered, no `isEnabledForUser` calls, no crash.
  - 5b **user out-of-group**: no closure registered, `isEnabledForUser` consulted exactly once.
  - 5c **happy path**: exactly one closure registered, full payload with `id`/`name`/`href`/`icon`/`order`.
- `tests/test_navigation_gate.php` updated for the new gate-outside-closure shape: getUser/null-check/isEnabledForUser must appear BEFORE the `add()` call; zero `return [];` anywhere in `Application.php`.
- Full regression — **413/413 PASS** across 12 test files.

## [0.13.5] — 2026-02-17

### Fixed (P0 — fatal QueryNotFoundException on every app load, reported 2026-06-30)
- **`/index.php/apps/souvera_mail/` no longer crashes with `Could not resolve OCA\Souvera_mail\Controller\PageController!`** Nextcloud 34's `IAppManager::getAppNamespace()` derives the PHP namespace for an app from its id with `ucfirst($appId)` whenever the cached `<namespace>` tag from `info.xml` is stale in the `core.appinfo` memcache. For our id `souvera_mail` that yields `OCA\Souvera_mail\…` (lower-case `m`), which doesn't match our canonical `OCA\SouveraMail\…` PSR-4 path → every controller lookup throws `QueryNotFoundException`. Rather than depend on the operator's memcache state we now ship a **two-part namespace bridge** that accepts both shapes (see Architecture below). Once the operator's cache catches up, the bridge becomes a silent no-op (the spl_autoload hook short-circuits on every lookup that does not start with the underscore prefix).

### Removed
- **Redundant `⚙ Settings` fallback pill in the engine UI.** When the live quota endpoint was unreachable the engine plugin's `quota.js` degraded the quota pill into a standalone "⚙ Settings" pill linking to the legacy NC-chrome settings page. With settings now living as a tab inside the engine itself ("Sicherheit & Geräte"), the fallback pill was both redundant and confusing — clicking it took the user out of the inbox to a redirect-only URL. The quota pill now simply disappears when quota data is unavailable.

### Architecture
- **`lib-bridge/namespace-bridge.php`** (NEW). Registered under composer's `autoload.files`, so it loads eagerly on every `require vendor/autoload.php` (which Nextcloud's `OC_App::registerAutoloading()` does for every enabled app at bootstrap). Installs an `spl_autoload_register(..., prepend=true, throw=true)` hook that rewrites `OCA\Souvera_mail\<sub>` → `OCA\SouveraMail\<sub>` via `class_alias`. Handles classes, interfaces AND traits (NC's DI graph touches all three). Re-entrancy-guarded by a `SOUVERA_MAIL_NAMESPACE_BRIDGE_INSTALLED` define so multiple `require_once` calls don't re-register the hook.
- **`lib-bridge/Souvera_mail/AppInfo/Application.php`** (NEW). Registered under composer's classmap. `final class Application extends \OCA\SouveraMail\AppInfo\Application` — empty body, inherits the entire boot/registration surface from the real Application. Gives NC's DI container something to `new` when it asks for `OCA\Souvera_mail\AppInfo\Application` directly.
- **`composer.json`** autoload section extended:
  - `"files": ["lib-bridge/namespace-bridge.php"]` — eager-load the spl hook before any class lookup.
  - `"classmap": [..., "lib-bridge/"]` — register the bridge Application class.
  - PSR-4 mapping for `OCA\SouveraMail\` is unchanged.
- **`app/smail/v/current/app/plugins/nextcloud/js/quota.js`**: `renderSettingsOnly()` now simply calls `removePill()` instead of rendering a fallback "Settings" pill.

### Verification
- `php -l` clean on both bridge files.
- `composer dump-autoload -o` regenerated; classmap holds 274 classes (was 273 — exactly +1 for `OCA\Souvera_mail\AppInfo\Application`).
- **`tests/test_namespace_bridge.php`: 18/18 PASS** (NEW). Covers:
  - Composer artefacts (`autoload_classmap.php`, `autoload_files.php`) contain both bridge entries.
  - `composer.json` declares both bridge entries under the right sections.
  - The bridge file's spl_autoload_register call is correctly parameterised (prepend=true, throw=true), targets exactly the underscore prefix, rewrites to the canonical CamelCase prefix, uses `class_alias` so the underscore name stays resolvable, handles classes/interfaces/traits, is re-entrancy-guarded.
  - The bridge Application class declares the correct namespace, is `final`, and `extends \OCA\SouveraMail\AppInfo\Application`.
  - **End-to-end PHP sub-process sim**: a stubbed `OCA\SouveraMail\Smoke\Target` class becomes reachable under `OCA\Souvera_mail\Smoke\Target` after loading only the bridge file. `new` works, reflection returns the canonical name (proving alias semantics), interface and trait variants work, and unrelated namespaces are NOT touched by the hook (no bleed-out).
  - `quota.js` no longer renders the fallback "⚙ Settings" pill; the `renderSettingsOnly()` degrades to `removePill()` cleanly.
  - `info.xml` still declares `<namespace>SouveraMail</namespace>` (the canonical CamelCase path for operators with a fresh cache).
- Full regression — **397/397 PASS** across 11 test files.

## [0.13.4] — 2026-02-17

### Fixed (three live bugs reported by operator)
- **App-Password creation silently failed with "Creation failed".** The JS read `body.item.secret` while `AppPasswordController::create()` returns `{status:'ok', created:{secret,...}}`. JS now reads `body.created.secret`. Also surfaces the actual server-side error message (`body.message`) instead of always showing the generic literal — operators now see the real reason ("Stalwart refused…", "description must not be empty", etc.).
- **"Verbundene Geräte" showed `Failed to load devices` and an empty list.** `ConnectedDevicesService::listForUser()` blew up on tokens whose `getScopeAsArray()` did not exist (NC34 token-provider variants) or whose other getters threw. Service hardened with `safeName/safeType/safeLastActivity/safeScope` wrappers; `safeScope` uses `method_exists()` before calling, so token providers that don't implement it return an empty array instead of crashing the whole request. Un-readable token entries are now skipped with a logger warning rather than failing the whole list. The JS surfaces `body.message` so operators see the actual server-side reason.
- **First-time engine login no longer asks for the display name.** The plugin's `FilterAppData` hook now seeds a default mail Identity from the NC profile (`$ocUser->getDisplayName()` + `$account->Email()`) on the very first request after first login. Idempotent — once the user has any stored identity, the seed is a no-op (we never overwrite a user-edited identity). Wrapped in try/catch — never breaks engine boot.

### Changed (UI polish — Souvera Central-aligned)
- **Settings tab redesigned**: card-based layout, accent palette aligned with Souvera Central, Lucide-style line icons in section headers, radio "cards" instead of inline radios (clickable rows that highlight on selection), proper banner styles for warn/error/success states, sleeker buttons (primary / danger), modern table with current-session row highlighting, scoped CSS via `.souvera-settings { … }` so nothing leaks into the rest of the engine UI. Dark-mode tweaks via `prefers-color-scheme: dark`.

### Architecture
- **`lib/Service/ConnectedDevicesService.php`**: 4 new private `safe*` helpers wrap each NC IToken getter. Listing loop also try/catches around `getId()` (worst-case: that row is silently dropped, never the whole call).
- **`app/smail/v/current/app/plugins/nextcloud/index.php`**: new `seedDefaultIdentityFromNcProfile(IUser)` helper (~50 LoC). Reads existing identities via `Smail\Engine\Api::Actions()->LocalStorageProvider()`, writes a default only when none exists. Shape mirrors `Smail\Engine\Model\Identity::ToSimpleJSON()`. Logs success/failure via `Smail\Engine\Log` so operators can see what happened in the engine log.
- **`app/smail/v/current/app/plugins/nextcloud/js/settings-account.js`**: `createAppPassword` and `loadDevices` now read JSON bodies even on non-2xx responses (the NC controllers always return JSON, even on 4xx/5xx) — error display surfaces the actual `body.message` from the server.
- **`app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html`**: rewritten with card-based markup + ~200 lines of scoped CSS. All Knockout bindings preserved.

### Verification
- `php -l` clean on all touched files.
- `eslint` clean on `settings-account.js`.
- **`tests/test_settings_three_bugs.php`: 42/42 PASS** (NEW). Covers all three bug fixes and the UI polish:
  - App-Password: JS no longer reads `body.item`, reads `body.created.{secret,description}` instead; controller still returns the `created` key.
  - Devices: 4 `safe*` wrappers exist, `safeScope` method_exists-guards, listing loop skips un-readable tokens, JS surfaces `body.message`.
  - Identity seed: helper exists, called from FilterAppData, reads existing identities first, bails on existing identity, sources name from `getDisplayName()`, bails on empty name, uses account email, swallows Throwable, full shape match against `Identity::ToSimpleJSON()`.
  - UI polish: card classes present, all Knockout bindings preserved.
- Full regression — **361/361 PASS** across 10 test files.

## [0.13.3] — 2026-02-17

### Fixed (P0 — IMAP/SMTP/Sieve subrequest auth)
- **Background mail-server reconnects no longer fail with `AUTHENTICATIONFAILED`.** The engine plugin's `beforeLogin` hook used to swap the in-engine sentinel password `oidc_login|<uid>` for a fresh H2CK/oidc JWT *only* when `EngineHelper::isOIDCLogin()` returned true. That helper required `IUserSession::getUser() !== null` — i.e. an active Nextcloud session in the current request. On every code path where the IMAP/SMTP/Sieve connect was driven by an account record *without* an active NC session — dashboard widget background refresh, cron, engine-token-cookie reconnects, Sieve-from-CLI — the guard returned false, the literal sentinel was sent to Stalwart as the password, and Stalwart rejected the connect with `AUTHENTICATIONFAILED`. Operators saw the failure as "Mailbox connection failed after a while" or "Dashboard widget shows 0 unread until I reload the page".

### Architecture
- **`OCA\SouveraMail\Util\EngineHelper`** gains two session-free helpers (alongside the existing `isOIDCLogin()` / `getOidcAccessToken()` which keep their semantics for live-session callers):
  - `isOIDCEnabledServerSide(): bool` — config flag (`autologin-oidc='1'`) AND H2CK/oidc app available. Does **not** consult `IUserSession`.
  - `getOidcAccessTokenForUid(string $uid): ?string` — dispatches `OidcProviderService::generateAccessToken($uid)` with an explicit uid (the new bug-fix entry point). Guards on `isOIDCEnabledServerSide()`. Returns null cleanly (never throws) when prerequisites are missing or H2CK refuses to mint the token.
- **Engine plugin `beforeLogin` hook** rewritten:
  - Removed `isOIDCLogin()` guard — the sentinel itself (`oidc_login|<uid>`, written by `Smail\Engine\Actions\UserAuth::accountFromNcSession()` at first login and persisted in the encrypted account store) is now the authoritative identity marker for OIDC-based accounts.
  - Parses `<uid>` directly out of the sentinel via `substr` + `strlen('oidc_login|')`.
  - Calls `EngineHelper::getOidcAccessTokenForUid($uid)` instead of the session-coupled `getOidcAccessToken()`.
  - Bails cleanly on malformed sentinel (empty `<uid>`) — the original sentinel is preserved so the engine's normal IMAP error path surfaces a useful diagnostic rather than us silently masking a corrupt account record.
- **`isOIDCLogin()` itself** is refactored to delegate to `isOIDCEnabledServerSide()` for the operator-controlled checks (no duplication) and only adds the live-session check on top. Semantics for browser callers (`PageController`, `UserAuth::accountFromNcSession`) are unchanged.

### Verification
- `php -l` clean on both touched files (`lib/Util/EngineHelper.php`, `app/smail/v/current/app/plugins/nextcloud/index.php`).
- **`tests/test_before_login_token_swap.php`: 31/31 PASS** (NEW). Covers:
  - All three `EngineHelper` methods do/do-not consult `IUserSession`/`getSsoUid()` as appropriate.
  - The plugin `beforeLogin` hook no longer calls `isOIDCLogin()`, parses the sentinel correctly, calls the new `getOidcAccessTokenForUid()`, and no longer calls the legacy session-coupled `getOidcAccessToken()`.
  - 6-state behavioural simulation: happy session-full path, **happy session-FREE path (the bug fix)**, H2CK refuses → sentinel preserved + no SASL leak, regular password account untouched, additional account untouched, malformed sentinel untouched.
- Full regression — **319/319 PASS** across 9 test files.

## [0.13.2] — 2026-02-17

### Fixed (P0 reported by operator on 2026-06-29)
- **`/index.php/apps/souvera_mail/settings` no longer crashes with `Symfony\Component\Routing\Exception\InvalidParameterException`** (`Parameter "id" for route "souvera_mail.connecteddevices.destroy" must match "\d+" ("__ID__" given)`). Symfony's URL generator validates the route `requirements` regex *at generation time*, not just at routing time — `linkToRoute('…connectedDevices.destroy', ['id' => '__ID__'])` therefore blew up because `__ID__` does not match `\d+`. The destroy-URL templates are now built by string concatenation (`linkToRoute('…connectedDevices.index') . '/__ID__'`), which skips the generator's requirement check while keeping the server-side `\d+` constraint enforced at routing time. Same fix applied to the AppPasswords destroy template for consistency.

### Changed (UX consolidation)
- **Settings live inside the mailbox.** Per product feedback the user-facing settings (Dashboard widget mode, App Passwords for legacy IMAP/POP3/SMTP clients, Connected Devices) are no longer a separate Nextcloud-chrome page at `/index.php/apps/souvera_mail/settings`. They are now a **native Snappymail Settings tab** registered via `rl.addSettingsViewModel(...)` at the hash route `#/settings/souvera-account`, labelled **"Sicherheit & Geräte"**, appearing next to *Allgemein / Kontakte / Filter / Sicherheit / Ordner* in the mailbox's own Settings sidebar. The user never leaves the inbox to manage these any more.
- **Legacy URL still works.** `/index.php/apps/souvera_mail/settings` now serves a `RedirectResponse` to the in-engine hash route, so existing operator bookmarks continue to resolve.
- **Quota pill is in-tab.** Clicking the live mailbox-quota pill in the engine UI now opens the new Settings tab in the *same* browser tab (`target="_self"` + hash navigation), instead of opening a separate NC settings page in a new tab.

### Architecture
- **`OCA\SouveraMail\Controller\SettingsController`** rewritten as a thin redirect controller (~50 LoC). Removes its dependencies on `INavigationManager`, `IConfig`, `EngineHelper`, `AppPasswordService`, `UnreadMailWidget` constants and `userId`. The behaviour-bearing logic is now owned by the engine plugin.
- **`app/smail/v/current/app/plugins/nextcloud/js/settings-account.js`** (NEW, ~320 LoC). Vanilla JS Knockout ViewModel class `SouveraAccountSettings`. Reads all URLs + `appPasswordsAvailable` + initial dashboard mode from `rl.settings.get('Nextcloud')`. Implements the full CRUD for the three sections — POST to `…/preferences/dashboard-mode`, GET/POST/DELETE on `…/app-passwords`, GET/DELETE/POST on `…/connected-devices`. CSRF header (`requesttoken`) populated from `OC.requestToken`.
- **`app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html`** (NEW, ~150 LoC). Knockout-bound HTML template; matches Snappymail's existing settings-tab styling conventions (`.form-horizontal`, `.legend`, `.control-group`, `.button-vue`).
- **`app/smail/v/current/app/plugins/nextcloud/index.php`**:
  - `Init()`: registers the new JS + template via `addJs('js/settings-account.js')` + `addTemplate('templates/SettingsSouveraAccount.html')`.
  - `FilterAppData()`: emits 9 new `Smail*` keys into the Nextcloud payload so the JS finds every URL it needs in `rl.settings.get('Nextcloud')`. Destroy templates use the `…/index . '/__ID__'` pattern for the P0 fix above.
  - Two new helpers — `resolveDashboardModeForNextcloud(uid)` and `isAppPasswordsAvailable()` — wrap the existing NC services defensively (silent fallback if `souvera_central` / H2CK/oidc are missing, so the engine boot never crashes).

### Removed
- `templates/settings.php` — replaced by the in-engine Knockout template.
- `js/personal-settings.js` — its full CRUD logic is now in `settings-account.js`.

### Verification
- `php -l` clean on all changed files (`SettingsController.php`, `app/plugins/nextcloud/index.php`).
- `eslint` clean on the new `settings-account.js` (after declaring `rl`, `ko`, `OC` as globals, matching the pattern in the existing `quota.js`).
- **`tests/test_settings_tab_integration.php`: 47/47 PASS** (NEW) — covers the redirect controller, the ViewModel registration, the Knockout template bindings, the engine-plugin wiring, FilterAppData payload, quota.js navigation behaviour, deletion of the old NC-chrome assets, and the version bump.
- **`tests/test_settings_url_template_regression.php`** updated to verify the URL templates now live in the engine plugin (not in `SettingsController`), still asserts that the broken `linkToRoute(['id' => '__ID__'])` pattern is forbidden in either location, and still asserts that `routes.php` keeps the server-side `\d+` requirement.
- **`tests/test_connected_devices.php` (74→71 assertions)**: ConnectedDevices UI assertions retargeted from the deleted NC-chrome template + JS to the new Knockout template + ViewModel. Behavioural sims on the PHP service layer are unchanged (covered: 3 states of `revoke()` + 3 states of `revokeAllOthers()`).
- **`tests/test_souvera_mail_rename.php` (58→55)** and **`tests/test_navigation_title.php` (15→16)** updated for the redirect controller and the absence of the legacy NC-chrome template.

## [0.13.1] — 2026-02-17

### Changed
- **Sidebar nav label shortened to "Mail".** The Nextcloud sidebar (top navigation bar / left rail in mobile) now shows the short label `Mail` instead of the full `Souvera Mail`. The long brand name wrapped / overflowed at typical NC sidebar widths and looked broken. The product brand stays `Souvera Mail` everywhere else (info.xml `<name>`, settings page heading, breadcrumb, dashboard widget title, App Store listing, About page). Operators who want a different sidebar label can still override it via `occ config:app:set souvera_mail menu-title --value '<label>'` — only the *default* changed.

### Architecture
- `NavigationTitle::DEFAULT` (in `lib/Util/NavigationTitle.php`) changed from `'Souvera Mail'` to `'Mail'`. Everything else (`menu-title` app-config key, `resolve()` semantics, `validate()` checks) is unchanged.

### Verification
- **`tests/test_navigation_title.php`: 15/15 PASS** (NEW) — confirms `DEFAULT === 'Mail'`, `resolve()` returns the short default when no override is stored, returns the operator override when one is, trims whitespace, falls back when override is whitespace-only, `validate()` still rejects > 64 chars + control chars, info.xml `<name>` still says `Souvera Mail` (no full-app rename), `SettingsController` still seeds the long brand for the in-app settings header, and the navigation closure in `Application::boot()` does NOT hard-code a brand name (would override the operator's `menu-title` setting).
- Full regression suite (`test_souvera_mail_rename.php`, `test_navigation_gate.php`, `test_connected_devices.php`, `test_enforce_group_restriction.php`, `test_docs_alignment.php`): **228/228 PASS** after version bump.

## [0.13.0] — 2026-02-17

### Added
- **Strict group-restriction enforcement.** Souvera Mail is now bound to the Nextcloud group `souvera-users` automatically on every `occ app:enable souvera_mail` and every `occ upgrade`. Members of any other group never see the navigation entry and cannot open `/index.php/apps/souvera_mail/…` — Nextcloud's built-in app-permission layer rejects the request before any controller runs. The binding converges towards the desired state every time the repair-step executes; manual `occ app:enable souvera_mail --groups <other-group>` deviations are reset on the next upgrade. The allowed group id lives in a single constant (`Application::RESTRICTED_GROUP_ID`) so downstream builds can patch it in one place.

### Architecture
- **`OCA\SouveraMail\Migration\EnforceGroupRestriction`** (NEW, ~95 LoC). Implements `OCP\Migration\IRepairStep`. Registered in `appinfo/info.xml` under both `<install>` and `<post-update>` `<repair-steps>` blocks so fresh installs *and* every upgrade re-converge:
  - Ensures the `souvera-users` group exists (`IGroupManager::createGroup()`); throws a verbose `RuntimeException` if the group manager refuses (LDAP read-only backend, misconfigured group manager — gives the operator the exact `occ group:add souvera-users` recovery command).
  - Calls `IAppManager::enableAppForGroups('souvera_mail', [$group])` to bind the app. Any `Throwable` from the binding is swallowed: a `warning()` is emitted on the `IOutput` and logged via `LoggerInterface` (so the rest of `occ upgrade` keeps going), but the failure is loud enough that a deploy pipeline can detect it via the `occ upgrade --verbose` output.
- **`Application::RESTRICTED_GROUP_ID = 'souvera-users'`** — single source of truth for the allowed group id. Referenced by the new repair-step and documented at the constant declaration.

### Verification
- `php -l` clean on all touched files.
- `composer dump-autoload -o` regenerated; classmap holds 273 classes (was 272 — exactly +1 for the new class).
- **`/app/tests/test_enforce_group_restriction.php`: 27/27 PASS** — file/namespace/interface, info.xml `<install>` + `<post-update>` registration with InstallStep regression guard, classmap entry, constant value, version + CHANGELOG checks, and a four-state behavioural sim of `run()` (group exists, group missing-but-created, `createGroup` returns null → helpful error, `enableAppForGroups` throws → swallowed + warning).
- **Regression: `/app/tests/test_souvera_mail_rename.php` and `test_navigation_gate.php` and `test_connected_devices.php`** updated for version `0.12.0 → 0.13.0` and pass.

## [0.12.0] — 2026-02-16

### Added
- **Connected Devices section** in the in-app Settings page (`/apps/souvera_mail/settings`). Lists every active Nextcloud session belonging to the current user — browsers + NC client apps (Files, Talk, Calendar, …) — with last-activity timestamp and per-row "Sign out" action. The current session is detected via `ISession::getId()` → `ITokenProvider::getToken()` and pinned to the top with a green "this device" badge; its "Sign out" button is disabled to prevent self-revocation mid-request (server also refuses with HTTP 400). A prominent **"Sign out all other devices"** button at the section header revokes every session except the current one in a single round-trip — the key security feature for shared-computer scenarios, lost phones, and post-password-reset cleanup.

#### Architecture
- **`OCA\SouveraMail\Service\ConnectedDevicesService`** (NEW, ~120 LoC). Pure Nextcloud-scoped — does **not** touch Stalwart. Wraps `OCP\Authentication\Token\IProvider`:
  - `listForUser($userId)`: enumerates tokens, classifies as `app` vs `browser`, sorts current-first then most-recent-activity-first.
  - `revoke($userId, $tokenId)`: refuses self-revocation, otherwise `invalidateTokenById`.
  - `revokeAllOthers($userId)`: loops tokens, skips current, swallows per-token failures (logged with `souvera_mail` app context).
- **`OCA\SouveraMail\Controller\ConnectedDevicesController`** (NEW). 3 endpoints, all `#[NoAdminRequired]`, CSRF-enforced for the mutating ones:
  - `GET    /connected-devices`
  - `DELETE /connected-devices/{id}` (numeric-id constrained via routes.php `requirements`)
  - `POST   /connected-devices/sign-out-others`
- Template + JS extended: a fourth row of `data-*` attributes on the section bootstraps the JS, which mirrors the existing App-Passwords pattern (XSS-safe row rendering, confirm-revoke dialog, inline traffic-light badges).

#### Reality check vs. original sketch
- The original "Nice-to-have" mentioned reading active mail-client sessions from a Stalwart `x:UserSession/query` JMAP endpoint. **No such endpoint exists in Stalwart 0.16** — verified against upstream `crates/jmap/src/registry/get.rs` (no `Session` / `Login` / `AuthToken` variant in the public ObjectType list; the only "session" object is `MtaInboundSession`, which is SMTP-server config). Stalwart treats mail-client identity persistently via `AppPassword` (already covered) and only ephemerally via raw IMAP/SMTP TCP connections (not exposed as queryable JMAP). So this release implements the Nextcloud half — the higher-value half, because users routinely keep browsers logged in on shared machines — and explicitly documents in the UI ("App Passwords are not part of this list") that mail-client revocation lives in the section above.

#### Routes added
```
GET    /connected-devices                  → connectedDevices#index
DELETE /connected-devices/{id}             → connectedDevices#destroy   (id constrained to \d+)
POST   /connected-devices/sign-out-others  → connectedDevices#signOutOthers
```

## [0.11.1] — 2026-02-16

### Fixed
- **Navigation entry honours group restrictions.** When the app was restricted to a group (e.g. `occ app:enable souvera_mail --groups mail-users`), members of *other* groups still saw a "Mail" entry in Nextcloud's top navigation. Clicking it landed on a Nextcloud "App is not enabled" error page. The lazy closure registered against `INavigationManager` in `Application::boot()` unconditionally returned the entry payload for every authenticated request. The closure now consults `OCP\App\IAppManager::isEnabledForUser($appId, $user)` first and returns an empty array when the user is unauthenticated *or* outside the allowed group set — Nextcloud's NavigationManager then drops the provider for this request, so the misleading menu item never appears.

## [0.11.0] — 2026-02-16

### Breaking
- **App ID renamed `smail` → `souvera_mail`.** The Nextcloud `<id>` in `appinfo/info.xml`, the PHP wrapper namespace (`OCA\Smail\…` → `OCA\SouveraMail\…`), all URL paths (`/apps/smail/…` → `/apps/souvera_mail/…`), every `IConfig` app-domain key, every `IL10N` / logger context, every cache namespace and every CLI command name (`occ smail:bootstrap` → `occ souvera_mail:bootstrap`, etc.) now use the long form. This is a fresh install — there is no automatic migration of old `smail` config values. Operators redeploying an existing `smail` install must run `occ app:remove smail` followed by `occ app:install souvera_mail` and re-run `occ souvera_mail:bootstrap …`.
- **Personal Settings section removed.** Souvera Mail no longer registers a `<personal>` settings section under Nextcloud's `/settings/user/souvera_mail`. The user-facing configuration UI (App Passwords, Dashboard widget mode) now lives **inside the app** at `/index.php/apps/souvera_mail/settings`. This sidesteps two long-standing issues: (a) it puts settings where the user already is (the mailbox), and (b) it removes the only entry path to a recurring L10N TypeError in an unrelated sibling app (`souvera_shield::PersonalSection::getName`) that was triggered whenever Nextcloud enumerated all Personal Sections to render the page.

### Added
- **In-app Settings page** at `/apps/souvera_mail/settings` rendered as a full-page TemplateResponse with NC's standard `'user'` chrome. Shows the existing Dashboard widget mode toggle and the App Password management UI. Includes a "← Back to inbox" breadcrumb that returns the user to the mailbox.
- **Quota pill is now an entry-point to settings.** Clicking the live mailbox-quota pill in the engine's top-right corner opens the in-app settings page in a new tab. When the quota endpoint is unavailable (no `souvera_central` mailbox, no Stalwart URL), the pill degrades to a `⚙ Settings` button so the entry-point is always visible.
- Engine plugin's `FilterAppData` hook now emits `Nextcloud.SmailSettingsUrl` in addition to `Nextcloud.SmailQuotaUrl`, consumed by `app/plugins/nextcloud/js/quota.js`.

### Changed
- All NC-wrapper PHP files updated to namespace `OCA\SouveraMail`. Composer PSR-4 mapping updated to `OCA\\SouveraMail\\` → `lib/`. Autoload classmap regenerated.
- All 137 L10N JS/JSON files now register against `OC.L10N.register("souvera_mail", …)`.
- Engine plugin (`app/smail/v/current/app/plugins/nextcloud/`) updated to reference `OCA\SouveraMail\Util\EngineHelper` and read app-config from the `'souvera_mail'` domain.
- Internal HTML / CSS / `data-testid` identifiers use the `souvera-mail-` (hyphenated) prefix for full consistency with the new app id.
- Engine namespace `Smail\Engine\…` and the physical engine directory `app/smail/v/current/` are intentionally **not** renamed — they are internal engine identifiers, not exposed as the app id; renaming would force 700+ unrelated file touches without any user-visible benefit.

### Removed
- `lib/Settings/PersonalSettings.php`, `lib/Settings/PersonalSection.php`, `templates/personal_settings.php` — replaced by the in-app `SettingsController` + `templates/settings.php`.
- `css/setup-wizard.css` — leftover stylesheet from the long-removed browser setup wizard, no references anywhere.

### Migration notes for operators
| Old | New |
|---|---|
| App URL `https://nc/index.php/apps/smail/` | `https://nc/index.php/apps/souvera_mail/` |
| Settings URL `https://nc/settings/user/smail` | `https://nc/index.php/apps/souvera_mail/settings` |
| OCC command `occ smail:bootstrap` | `occ souvera_mail:bootstrap` |
| OCC command `occ smail:status` | `occ souvera_mail:status` |
| OCC command `occ smail:reset` | `occ souvera_mail:reset` |
| OCC command `occ smail:setup` | `occ souvera_mail:setup` |
| OCC command `occ smail:oidc:register-client` | `occ souvera_mail:oidc:register-client` |
| User preference `occ user:setting <uid> smail dashboard-mode …` | `occ user:setting <uid> souvera_mail dashboard-mode …` |
| App data directory `appdata_smail/` | `appdata_souvera_mail/` |

## [0.10.2] — 2026-02-16

### Fixed
- **`templates/not_configured.php` no longer points users at a non-existent setup wizard.** The empty-state page used to render a primary button labelled *Setup Wizard* that linked to `/settings/admin/smail`. That link contradicts the project's CLI-only doctrine (the browser wizard was removed in 0.9.0), and on real deployments it also surfaced a completely unrelated TypeError from a sibling app (`souvera_shield::PersonalSection::getName()` → `LazyL10N::t()` → `array_merge(null)`) because the personal-settings page enumerates every installed app's section. Replacing the button with a static CLI snippet (`sudo -u www-data php occ smail:bootstrap …`) sidesteps both the doctrinal mismatch and the third-party crash entry-point.

### Documentation
- The empty-state page now explicitly states the prerequisites for `occ smail:bootstrap` (H2CK/oidc 1.17+ enabled with a signing key, Stalwart mailbox provisioned by souvera_central) and points operators at `occ smail:status` for inspection and `occ smail:reset` for re-running. After bootstrap, SSO via H2CK/oidc is automatic — no per-user configuration is required (answering the recurring deployment question).

## [0.10.1] — 2026-02-16

### Added
- **Live mailbox-quota pill** in the Souvera Mail engine UI (top-right). Reads `usedDiskQuota` + `quotas.MaxDiskQuota` for the current user from Stalwart 0.16 via a single `x:Account/get` JMAP call. Shown as a small pill `"382 MB / 5 GB"` that turns orange at ≥75 % and red at ≥90 %. Hidden gracefully if any prerequisite is missing (no `souvera_central`, no Stalwart URL, no H2CK/oidc) — the regular UI is unaffected.

#### Architecture
- **`OCA\Smail\Service\StalwartUserContext`** (NEW) — shared helper. Extracts `resolveAccountId(string $userId)` + `resolveBearer(string $userId)` from the previous `AppPasswordService` into one reusable class so both AppPassword + Quota flows share the souvera_central principal lookup + H2CK/oidc token acquisition without duplication.
- **`OCA\Smail\Service\StalwartAdminService::extractMethodResponse()`** is now a public reusable helper (moved from `AppPasswordService`).
- **`OCA\Smail\Service\QuotaService`** (NEW) — wraps `x:Account/get` with `properties: ['usedDiskQuota', 'quotas']`. Result cached in NC's distributed cache for 60 s per user (Stalwart's quota numbers do not change every second; engine polls every 60 s anyway).
- **`OCA\Smail\Controller\QuotaController`** (NEW) — single endpoint `GET /index.php/apps/smail/quota` returning `{ status, used, total, percentage, unlimited, formatted: { used, total } }`. `#[NoAdminRequired]`, session-cookie auth, no CSRF needed (GET).
- **Engine plugin** (`app/smail/v/current/app/plugins/nextcloud/`):
  - `index.php` `FilterAppData` hook now emits `Nextcloud.SmailQuotaUrl = absoluteUrl('smail.quota.index')` so the engine JS can reach the endpoint without hard-coding the NC webroot.
  - **`js/quota.js`** (NEW, ~100 LoC) — fetches the URL on engine boot (1.5 s delay to avoid racing the login flow) and every 60 s afterwards, renders a `position: fixed` pill in the top-right corner with traffic-light colours based on percentage. Idempotent — removes the pill on any error so the UI never shows stale info.

#### Refactored
- `AppPasswordService` constructor signature simplified: now injects `StalwartAdminService` + `StalwartUserContext` instead of (Oidc + UserManager + Container). Private `resolveAccountId` / `resolveBearer` / `extractMethodResponse` methods removed (now on `StalwartUserContext` / `StalwartAdminService`). No behaviour change.

#### Routes added
```
GET /quota → quota#index
```

## [0.10.0] — 2026-02-16

### Added
- **App Passwords for legacy mail clients.** Souvera Mail now talks to the Stalwart 0.16+ management surface (JMAP under `urn:stalwart:jmap` capability) so end users can self-issue IMAP / POP3 / SMTP credentials directly from *Settings → Souvera Mail* for mail apps that do not support OAUTHBEARER (older Thunderbird, Apple Mail iOS, Outlook, automated scripts, …). Verified against upstream `crates/registry/src/schema/structs.rs` (`AppPassword`) and `crates/jmap-proto/src/request/method.rs` line 238 (`x:<ObjectType>/<function>` method name format).

#### Architecture
- **`OCA\Smail\Service\StalwartAdminService`** — minimal JMAP HTTP wrapper. Reads the API URL from system config `souvera_central.stalwart_api_url`, posts to `<url>/jmap` with `Authorization: Bearer <jwt>`, 8 s timeout.
- **`OCA\Smail\Service\AppPasswordService`** — domain logic. Resolves the user's mail address via the parallel-installed Souvera Central app's `OCA\SouveraCentral\Service\StalwartService::mailFor(IUser)`, looks up the opaque Stalwart account ID via `findAccountId($email, 'User')`, acquires a user-scoped JWT via H2CK/oidc, then dispatches `x:AppPassword/get` and `x:AppPassword/set`. All operations run as the user — Stalwart 0.16 explicitly forbids admins from creating AppPasswords on behalf of users (`stalw.art/docs/auth/authentication/app-password/`).
- **`OCA\Smail\Controller\AppPasswordController`** — CSRF-protected, `#[NoAdminRequired]`, never accepts a user id from the client (always derives from session). Three endpoints under `/index.php/apps/smail/`:
  - `GET    /app-passwords`           — list (description + createdAt only, no secrets)
  - `POST   /app-passwords`           — create, returns `{id, secret, description}` (plaintext secret ONCE — Stalwart stores only the hash)
  - `DELETE /app-passwords/{id}`      — revoke
- **Personal Settings UI** — adds a third section listing existing app passwords (description / created / Revoke button) and a create form. On successful creation the plaintext secret is rendered in a one-time card with a copy-to-clipboard button and an explicit "save now — you'll never see this again" warning.

#### Permissions
The created AppPassword carries `permissions = {"@type": "Inherit"}`, i.e. it grants exactly the same access scope as the user's primary account (IMAP + POP3 + SMTP + JMAP for that mailbox). No per-protocol toggle in this release (per user choice).

#### Routes added
```
GET    /app-passwords        → appPassword#index
POST   /app-passwords        → appPassword#create
DELETE /app-passwords/{id}   → appPassword#destroy
```

#### Notes for operators
- Required system-config keys (set by Souvera Central in production deploys):
  - `souvera_central.stalwart_api_url` — e.g. `http://stalwart:8080`
- Required apps enabled in the same Nextcloud instance: `oidc` (H2CK), `souvera_central` (for the mail-address ⇆ Stalwart-principal mapping).
- The Personal Settings page degrades gracefully: if any prerequisite is missing it shows a yellow banner ("App passwords are not available — Souvera Central and the Stalwart API URL must be configured by the administrator first") and hides the form.

## [0.9.4] — 2026-02-16

### Added
- **Dashboard widget mode toggle.** The Souvera Mail dashboard widget (`smail-unread`) now reads a per-user preference `smail/dashboard-mode` and switches between two modes:
  - `unread` (default): only unread INBOX messages — matches the previous behaviour.
  - `all`: the most recent INBOX messages regardless of seen state — useful for users who treat the dashboard as a glance-pane for everything new.
- Each widget row links straight to the message via the engine hash-router (`#/mailbox/INBOX/m<UID>`) — one click opens the mail in Souvera Mail.
- **Personal settings UI** at *Settings → Souvera Mail* exposes the toggle as two radio buttons, persists via a new `POST /preferences/dashboard-mode` endpoint (`OCA\Smail\Controller\PreferenceController`), and shows an inline ✓/✗ confirmation. Stored via Nextcloud's standard `IConfig::setUserValue()`, so the same setting is also reachable from the CLI for declarative deploys:
  ```
  occ user:setting <uid> smail dashboard-mode all
  occ user:setting <uid> smail dashboard-mode unread
  ```

### Changed
- Widget title is now `Souvera Mail · Inbox` (was `Unread mail`) — the title is mode-agnostic; the empty-state message communicates the active mode (`No unread mail` vs `Inbox is empty`).
- Widget item links now use `getAbsoluteURL()` instead of the relative `linkToRoute()` result, so the dashboard's anchor follow works from any embed context (Nextcloud Talk smart picker, public dashboards, etc.).

## [0.9.3] — 2026-02-16

### Changed
- Version bump-only release. No code changes vs. 0.9.2. Required so Nextcloud's repair step / `occ upgrade` re-processes the app after the 0.9.2 `RegisterClient.php` patch was applied on top of an already-installed 0.9.2 build. The H2CK/oidc `oidc:create` signature implemented in 0.9.2 has been verified against the upstream `lib/Command/Clients/OIDCCreate.php` (positional `name` + `redirect_uris` `REQUIRED|IS_ARRAY` argument, `--token_type` option with underscore, JSON-encoded `Client::jsonSerialize()` output with keys `client_id` / `client_secret`).

## [0.9.2] — 2026-01-16

### Fixed
- `occ smail:bootstrap` exited 1 with `register_client → "oidc:create dispatch failed: …"` against H2CK/oidc 1.17+. Three independent assumptions in `lib/Command/Oidc/RegisterClient.php` were wrong against the actual H2CK CLI signature:
  - `redirect_uris` is a **positional `IS_ARRAY` argument**, not the `--redirect-uri` option we were sending. The Symfony console rejected the unknown option before the command body ever ran.
  - The access-token-type flag is `--token_type` (with underscore) and is set **per-client at creation time**, not by flipping the global `default_token_type` app-config after the fact. We now pass `--token_type=jwt` directly to `oidc:create`, so the JWT (RFC 9068) format is requested even when the global default is left at `opaque`.
  - The created client is emitted as a **pretty-printed JSON object** (`Client::jsonSerialize()` in `lib/Db/Client.php`) with keys `client_id` / `client_secret` — not the human-readable `Client ID: …` / `Client Secret: …` text our regex parser expected. The wrapper now `json_decode()`s the output first and falls back to the regex parser for compatibility with pre-1.14 H2CK builds.
- The bootstrap error report now includes `raw_oidc_create_output` and `raw_invocation_args` keys so the next-level operator can paste a single JSON blob into a bug report instead of digging through logs.

### Notes for operators
- If you tried `occ smail:bootstrap` against 0.9.0/0.9.1 and it failed at `register_client`, just re-run after upgrading to 0.9.2 — `smail:bootstrap` is idempotent and resumes cleanly. The OIDC client may already exist in H2CK from the failed run; use `occ oidc:list` to verify, and `occ smail:oidc:register-client --force` to rotate the client_secret if you don't have the old one.

## [0.9.1] — 2026-01-16

### Fixed
- `occ app:enable smail` crashed in the post-install repair step with `Class "Smail\Engine\Upgrade" not found`. The Souvera Mail engine ships with `Smail\…` namespace classes whose files live under `app/smail/v/current/app/libraries/Smail/`. Nextcloud's built-in autoloader maps only `OCA\Smail\` → `lib/`, so the engine namespace was unreachable until `EngineHelper::loadApp()` registered a fallback autoloader. That fallback had an off-by-one bug introduced by the 0.9.0 rename: the magic offset for stripping the `Smail\Engine\` prefix was left at the previous `X2Mail\Engine\` length (14 → 13 chars). Both autoloader branches now use the correct offset.

### Added
- **`composer.json`** with mixed PSR-4 + classmap autoload (`OCA\Smail\` PSR-4 → `lib/`, `Smail\…` classmap → `app/smail/v/current/app/libraries/Smail/`). The classmap is required because the upstream engine uses lowercase filenames (`upgrade.php`, `mail/config.php`) for CamelCase classes (`Upgrade`, `Mail\Config`), which standard PSR-4 cannot resolve.
- **`vendor/composer/`** generated by `composer dump-autoload --optimize` (~124 KB, 288 classes hard-mapped — 263 engine + 25 NC wrapper).
- **`require_once vendor/autoload.php`** at the top of `lib/AppInfo/Application.php`, so the autoloader is active before the very first service-container lookup. This covers the `app:enable` repair-step path, where `OCA\Smail\Migration\InstallStep::run()` references `\Smail\Engine\Upgrade::fixPermissions()` directly.

### Notes for downstream / packagers
- The release tarball must include `composer.json` and `vendor/`. The manual fallback autoloader in `EngineHelper::loadApp()` still works (it is the off-by-one fix), but composer is the canonical path.
- Build pipelines should run `composer dump-autoload --optimize --no-dev` whenever engine PHP files are added/renamed, otherwise the classmap drifts.

## [0.9.0] — 2026-01-15

### Brand rename
- The app's previous identity (X2Mail / souvera_mail, namespace `OCA\X2Mail\…`, engine namespace `X2Mail\Engine\…`) was completely collapsed to a single canonical short form across the entire codebase:
  - **Display name** is now **Souvera Mail** everywhere user-visible (Nextcloud settings, navigation, engine PWA, l10n, README).
  - **App id** is now `smail` (`<id>`, route names, app-config namespace, image paths, storage dir `appdata_smail/`, log file `smail.log`, CLI commands `occ smail:*`).
  - **PHP namespace** is now `OCA\Smail\…` (NC wrapper, 25 files) and `Smail\Engine\…` / `Smail\Mail\…` (bundled engine, ~600 files).
  - **Constants**: `SMAIL_LIBRARIES_PATH`, `SMAIL_INCLUDE_AS_API`.
  - **Engine directory tree** physically moved: `app/x2mail/v/` → `app/smail/v/`; engine library directory `…/libraries/X2Mail/` → `…/libraries/Smail/`; engine theme directory `…/themes/x2mail/` → `…/themes/smail/`.
  - **Asset filenames**: `images/x2mail-logo.png` → `images/smail-logo.png`; `fonts/x2mail.{woff,woff2}` → `fonts/smail.{woff,woff2}`; openssl S/MIME config sections `[x2mail_req]` → `[smail_req]`, `[x2mail_ca]` → `[smail_ca]`.

### Added (new architecture)
- **Nextcloud is now the OIDC Provider for the whole mail stack** via the [H2CK/oidc](https://github.com/H2CK/oidc) app. Souvera Mail consumes access tokens directly from Nextcloud's OIDC OP through in-process PHP event dispatch (`OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent`) — no browser redirect, no token caching in the NC session, no external IdP required.
- **`OCA\Smail\Service\OidcProviderService`** — wraps the H2CK/oidc event dispatch with defensive `isProviderAvailable()` checks, per-user JWT caching in NC's distributed cache (`exp - 60s` TTL), and clean failure modes when H2CK/oidc is missing.
- **`occ smail:bootstrap`** — one-shot install for declarative deploys: preflight checks H2CK/oidc, registers the smail OIDC client (via `occ oidc:create`), writes the IMAP/SMTP/Sieve domain profile, and runs `occ smail:status` to verify. Idempotent. Supports `--json` and `--dry-run`.
- **`occ smail:oidc:register-client`** — registers Souvera Mail as a confidential OIDC client in H2CK/oidc. Optionally dumps the generated client_secret to a deploy-supplied file (mode 0600). Sets `default_token_type=jwt` (RFC 9068) globally for H2CK so issued tokens are JWT.
- **`occ smail:reset`** — tears down all Souvera Mail state for a clean re-deploy: app-config, domain profile, cached tokens. Optional `--purge-oidc-client` and `--purge-engine-data` flags.
- **`--json`** and **`--dry-run`** flags on every write-command for machine-readable status reports and pipeline-safe planning runs.
- Read-only admin status panel at **Settings → Souvera Mail** — every interactive element is informational only; configuration changes happen exclusively through `occ`.

### Removed (CLI-only architecture, no browser wizard)
- `templates/setup-wizard.php` — browser-based setup wizard
- `js/setup-wizard.js` — wizard JavaScript
- `lib/Controller/SetupController.php` — all wizard write endpoints
- `lib/Listeners/TokenBridgeListener.php` — bridged `user_oidc` tokens into the engine session
- `lib/Middleware/TokenRefreshMiddleware.php` — token refresh dance for `user_oidc` flow
- All `oidc_login` and `user_oidc` runtime dependencies (the engine never asks for them again)
- Legacy migration code in `lib/Migration/InstallStep.php` — Souvera Mail is fresh-install only; there are no legacy `x2mail` / `souvera_mail` / `snappymail` data to migrate from.

### Changed
- `EngineHelper::getOidcAccessToken()` resolves through `OidcProviderService` instead of the `user_oidc` event listener. `isOIDCLogin()` simplifies to "NC user is logged in AND H2CK/oidc is available".
- `LoginBridgeListener` only stamps `smail-uid` into the session — no more `is_oidc` / `oidc_access_token` session markers.
- `appinfo/routes.php` now contains only the 4 page routes; every wizard endpoint is gone.
- `appinfo/info.xml` description rewritten for the new NC-as-OP architecture; all 5 occ commands registered.
- `README.md` rewritten end-to-end for the new architecture (NC-as-OP, CLI-first deploy, `occ smail:bootstrap` quick-start, `occ` command reference, troubleshooting).

### Migration / upgrade notes
- **Fresh install only.** Souvera Mail 0.9.0 does not migrate data from earlier `x2mail`/`souvera_mail`/SnappyMail/RainLoop instances. Operators upgrading from a previous deployment should plan a clean reinstall: export their mail data through IMAP, install Souvera Mail 0.9.0, and reconnect.

## [0.8.0] — 2026-06-10

### Removed
- Support for the unmaintained `oidc_login` app — `user_oidc` is the only supported SSO provider now (`oidc_login` does not support Nextcloud 34+). Existing `oidc_login` setups must migrate to `user_oidc`.

### Added
- Optional extra scopes for OIDC token exchange — configurable in the setup wizard next to the token audience, via `occ smail:setup --oidc-scopes "scope1 scope2"`, or directly: `occ config:app:set smail oidc-exchange-scopes --value "scope1 scope2"`. The wizard's Test Login uses the typed scopes.
- Test Login diagnostics show which token was actually used: exchanged token (with audience, scopes and remaining lifetime), a warning when the token exchange fell back to the login token, or the plain login token

### Security
- Bearer tokens are never sent over unencrypted connections to remote mail servers (loopback connections are exempt)

### Fixed
- An expired or rejected token during SMTP authentication now terminates the SASL exchange cleanly (RFC 7628) and the mail server's error details are logged, instead of failing with a generic error
- Token exchange/refresh failures now log the identity provider's error response and the remaining token lifetime, making SSO issues diagnosable from the log
- The engine log no longer fills with `CRITICAL: Caught SIGCHLD` entries — these fired on every normal helper-process exit and were not errors
- The webmail UI keeps loading even if a future Nextcloud release removes the internal CSP nonce API (the self-generated fallback nonce is now correctly referenced by the app's content security policy)

## [0.7.3] — 2026-06-10

### Fixed
- Nextcloud 34 compatibility: the content security policy no longer depends on the `allowEvalScript()` API removed in NC 34. A leftover `$evalScriptAllowed` subclass property additionally crashed NC 34's reflection-based policy merge with an HTTP 500 on every page load; the property is gone and `'unsafe-eval'` — required by the Knockout-based UI — is now added to `script-src` via the version-appropriate path (direct keyword on NC 34, `allowEvalScript()` on NC 33).

### Changed
- CSP nonce lookup now degrades to a self-generated nonce if the internal `ContentSecurityPolicyNonceManager` is removed in a future release, turning a potential fatal error into a soft failure (no public nonce API exists yet — see #181).

## [0.7.2] — 2026-06-05

### Changed
- Authentication state now lives entirely in the Nextcloud session — Souvera Mail no longer stores its own authentication cookies

## [0.7.1] — 2026-05-31

### Security
- S/MIME certificates are now generated as per-user self-signed end-entity certificates, signed with their own key (no shared signing key)
- S/MIME signatures now report the signer identity and a trust state (trusted only for known signers) instead of marking every valid signature as verified
- External image proxy rejects URLs resolving to private, loopback, link-local or reserved addresses (SSRF hardening) and caps proxied response size
- Mail authentication offers only OAuth SASL mechanisms (OAUTHBEARER/XOAUTH2); password-based SASL fallbacks were removed so a malicious server cannot downgrade the connection

### Fixed
- S/MIME certificate creation now works for identities without a display name

## [0.7.0] — 2026-05-28

### Removed
- Password/plain login — Souvera Mail is SSO/OIDC-only (`--auth plain`, `occ smail:settings`, and the manual password login form are no longer available)
- Legacy engine admin panel (`/?admin`) — all administration moves to Nextcloud Settings → Souvera Mail
- SnappyMail legacy domain blocklist seed (`app/domains/disabled`) — fresh installs no longer copy a public-provider deny list into engine data

### Added
- Setup wizard **Test Login** — verifies live `OAUTHBEARER` login to IMAP, SMTP submission, and ManageSieve with the current SSO token
- Configurable ManageSieve in setup: `--sieve-host`, `--sieve-port`, `--sieve-ssl` (CLI) and matching fields in the setup wizard
- **Allgemein** + **Info** sections in Nextcloud Settings → Souvera Mail (attachment limits, OpenPGP/GnuPG, version info)
- Real OAUTHBEARER auth-test in the setup wizard (replaces the old engine connectivity test)

### Changed
- Mail authentication is OAuth SASL only (`OAUTHBEARER` / `XOAUTH2`) for IMAP, SMTP, and Sieve
- Setup wizard is SSO-only (no password auth mode); SMTP authentication is enabled automatically in generated domain config
- Setup wizard mail server section: **IMAP → SMTP → Sieve**
- Updated bundled OpenPGP.js to **6.3.0** — modern WebCrypto/WebAssembly, smaller bundle (drops the legacy asm.js fallback)
- IMAP client supports **IMAP4rev2** (RFC 9051) when the mail server advertises it — unread counts use ESEARCH instead of deprecated SELECT UNSEEN
- Updated bundled Sabre VObject (**4.5.8**) and Sabre Xml (**4.0.6**) for vCard/iCal parsing

### Fixed
- ManageSieve setup and **Test Login** now use the configured Sieve host, port, and TLS mode (supports both STARTTLS and implicit TLS listeners)
- Sieve filtering works with the same OAuth SSO flow as IMAP and SMTP

### Verified
- End-to-end **Stalwart 0.16.6** with Keycloak + LDAP directory (IMAP/SMTP/Sieve OAUTHBEARER via setup wizard). See [docs/configs/stalwart-oauthbearer.md](docs/configs/stalwart-oauthbearer.md).

## [0.6.4] — 2026-05-27

### Added
- Optional OAuth token exchange: `occ smail:setup --imap-audience <client>` (and a matching setup-wizard field) lets the mail server use a different OIDC client than the Nextcloud login client, for IdPs that support token exchange

### Changed
- SSO token refresh now uses the official Nextcloud `user_oidc` token API for better forward compatibility
- `occ smail:setup` default `--smtp-port` is now 587 (standard submission port) instead of 25

### Fixed
- SSO mailbox reconnect after token expiry is now reliable in persistent-login sessions

## [0.6.3] — 2026-04-13

### Changed
- JS/CSS minification in build pipeline (terser + clean-css)
- Setup wizard and `occ smail:setup` now enforce one active domain profile and consolidate stale extra configs
- OAuth domain configs now advertise only `OAUTHBEARER` and `XOAUTH2` SASL mechanisms by default

### Fixed
- SMTP OAUTHBEARER authentication now works in SSO mode — `useAuth` is enforced when `authType=oauth` so `SmtpClient::Login()` is no longer skipped
- Preflight checks now perform real IMAP/SMTP `STARTTLS` negotiation instead of plain TCP reachability checks
- Preflight TLS checks now inherit current Souvera Mail SSL defaults and fall back to relaxed diagnostics with a visible warning instead of hard-failing selfhosted certificate setups
- SMTP OAuth capability is now validated when authenticated sending is enabled in SSO mode
- Setup wizard now writes the new active domain before cleaning up stale profiles and reports cleanup warnings instead of risking config loss
- Release defaults for `autologout`, `contacts_autosave`, `show_login_alert`, and identity handling are restored through targeted migration/default application
- `occ smail:status` now reports the actual IMAP/SMTP security mode and the stored OIDC provider selection
- `occ smail:settings` and password-login persistence now store secrets with sensitive/internal flags
- Repair step no longer wipes legacy passphrases on every update and no longer resets broad engine config on every post-update

## [0.6.2] — 2026-03-30

### Fixed
- SSO login works reliably after App Store upgrades
- Plugin updates no longer leave stale files that break the frontend

## [0.6.1] — 2026-03-30

### Added
- Dashboard widget: unread mails stay visible after OIDC token expiry (auto-refresh)
- Nextcloud search: mail search works reliably with OIDC token refresh
- Calendar save: duplicate detection — warns when event already exists, option to update or cancel
- Calendar save: visual feedback — shows Created/Updated/Error states on save button
- Contacts: address book name shown in contact detail view
- Setup Wizard: unified Mail-Server layout, domain tabs, OIDC provider visible by default
- Password auth: credentials persist across sessions (automatic on Nextcloud login)

### Changed
- Auth type switch (SSO/Password) applies cleanly without re-login required
- Password encryption uses Nextcloud-native cryptography
- All 26 engine enumerations migrated to native PHP 8.1 enum types
- Engine static analysis raised from PHPStan Level 1 to Level 2
- Contacts detail font size reduced for cleaner layout

### Fixed
- File attachments from Nextcloud Files work again (NC33 API migration)
- Save email/attachments to Nextcloud Files works again (NC33 API migration)
- Email address shown correctly in login field (was showing username only)
- Switching between SSO and password auth no longer causes authentication errors
- Calendar save to Nextcloud works reliably
- Setup wizard token diagnostics display correctly for all OIDC providers

### Removed
- Manual email/password settings page (SSO handles authentication automatically)
- Admin panel: password/TOTP authentication removed (SSO-only)
- Engine dead code: ~14,000 lines removed (unused libraries, standalone contacts system, admin auth)

## [0.6.0] — 2026-03-29

### Breaking
- Complete rebrand: SnappyMail/RainLoop → Souvera Mail across all namespaces, directories, DB tables, config keys, and UI
- Existing installations are migrated automatically

### Added
- Admin panel authenticates via Nextcloud SSO
- Setup wizard with OIDC verification and JWT token diagnostics
- Info page when no mail server is configured (with link to setup wizard for admins)
- About page shows latest GitHub release version

### Changed
- SSO-first defaults: OAuth as default auth type
- Single-domain setup: wizard manages one mail server configuration
- Domain field auto-suggested from admin email address
- `occ smail:status` shows compact SSO diagnostics
- Translations updated for all 97 locales

### Removed
- Separate admin password/cookie authentication
- Admin panel menus: Security, Plugins, Branding, Packages, Login Screen
- Multi-domain management and domain alias in admin panel
- External plugin manager
- iframe embedding mode

## [0.5.9] — 2026-03-26

### Added
- Personal settings page with Identity & Signatures management link
- Own settings section with app icon in Nextcloud sidebar
- Dynamic page title from admin-configured branding

### Fixed
- PSR-12 code style compliance
- CSS isolation for Nextcloud header and user menu
- Admin panel branding
- German translations

## [0.5.8] — 2026-03-26

### Added
- ICS Event Card: calendar invitations displayed prominently above message body
- Event details: date/time, organizer, location, attendees with formatted display
- One-click "Save to Calendar" button with CalDAV integration
- Calendar picker filters read-only calendars (Deck-generated etc.)
- Toast notification on successful calendar save
- German and English translations for event card UI
- App Store screenshot for calendar integration

## [0.5.7] — 2026-03-26

### Fixed
- SideMenu app compatibility: SnappyMail's global CSS (ul/li margin resets) no longer leaks into Nextcloud UI
- CSS selector scoping: all embed.css rules prefixed with `#rl-app` to prevent style leakage
- Boot CSS: strip body/html rules from SnappyMail's inline boot stylesheet

## [0.5.6] — 2026-03-26

### Changed
- SSO defaults: disable contacts autosave
- Hide theme selector on fresh install (smail theme is default)

## [0.5.5] — 2026-03-26

### Fixed
- Default theme set to smail on fresh install (was falling back to "Default")

## [0.5.4] — 2026-03-26

### Added
- First release on Nextcloud App Store
- Signed with official Nextcloud Code Signing certificate

### Changed
- Updated screenshots for App Store listing

## [0.5.3] — 2026-03-25

### Added
- PHPUnit test infrastructure with 18 unit tests (DomainConfigService, TokenRefreshMiddleware)
- CI: automated test execution in pipeline

### Changed
- Event listeners moved from `boot()` closures to dedicated `IEventListener` classes (PasswordLogin, Logout, Impersonate)

### Fixed
- Domain validation: reject `.` and `..` as domain names (found by unit tests)

## [0.5.2] — 2026-03-25

### Added
- Dashboard widget for unread mail (`IAPIWidgetV2`, auto-reload every 120s)
- Complete German translations for all UI strings

### Changed
- Migrate 47 deprecated `IConfig` calls to `IAppConfig`/`IUserConfig` (NC33 public API)
- Replace private `OC\Core\Command\Base` with `Symfony\Component\Console\Command\Command`
- Template escaping: `p()` for values, `print_unescaped()` for engine content
- Replace "SnappyMail" with "Souvera Mail" in admin panel UI

### Fixed
- Null-guard for `$this->userId` in FetchController personal settings
- Add `declare(strict_types=1)` to Settings command
- Dashboard widget icon uses NC URL generator instead of internal SM path

## [0.5.1] — 2026-03-25

### Fixed
- SSO setup incorrectly disabled identity management (allow_additional_identities, popup_identity)

## [0.5.0] — 2026-03-25

### Added
- New `smail` theme for Nextcloud 33+ design system
  - 3-tier color mapping: pastel backgrounds, element colors for icons, text colors for readability
  - Alerts follow NC33 NoteCard pattern (pastel bg + colored left border)
  - Buttons follow NC33 NcButton pattern (focus-visible box-shadow, transitions)
  - Inputs with NC33 focus-visible inset box-shadow
  - NC33 info status color support
  - Light + dark mode with NC33 theme values
  - Updated border-radius, font stack, disabled states to NC33 defaults

### Fixed
- Identity popup close button navigated away instead of showing confirm dialog (href="#" in embedded mode)
- Error tooltips used aggressive red background instead of NC33 NoteCard pattern
- Priority-high indicators, attachment errors, virus warnings now use NC33 color system
- btn-danger/btn-warning hover states were overridden by generic hover rule

### Changed
- Default theme switched from `NextcloudV25+` to `smail` (InstallStep, AdminSettings, RainLoop)
- Remove 20 unused bundled SnappyMail themes (A, BlackWood, Blurred, etc.)
- Hide auto-logout setting in SSO/embedded mode (NC manages the session)

## [0.4.10] — 2026-03-25

### Fixed
- SSO: auto-disable "Add account" and "Manage identities" when OIDC is configured (Setup Wizard, CLI, and upgrade)
- SSO: SM plugin read autologin config from wrong app namespace (`snappymail` → `smail`), breaking fresh installs

## [0.4.8] — 2026-03-23

### Fixed
- Fix unreadable error messages in Compose view (dark red text on dark background in NC dark theme)
- Position compose error tooltip inline in toolbar row instead of overlapping fields

## [0.4.7] — 2026-03-23

### Fixed
- Fix double-slash in `app_path` when `overwritewebroot=/` (normalize `getAppWebPath()` output in InstallStep, Setup, AdminSettings, FetchController)

## [0.4.6] — 2026-03-22

### Security
- Fix ContactsSync password leaked to browser in AppData JSON response
- Fix path traversal via unvalidated domain in DomainConfigService
- Fix SM plugin file/folder paths without directory traversal check
- Fix Setup Wizard missing hostname validation and error message redaction
- Fix `app_path` missing `..` traversal check in admin settings
- Fix IMAP connection failure permanently wiping stored credentials
- Add email format validation to personal settings
- Restrict log file permissions to 0600 on creation

## [0.4.5] — 2026-03-22

### Added
- **PHPStan Level 7 static analysis** — catches type errors, undefined methods, wrong argument types at build time
- CI pipeline with automated lint, build, validate, and deploy

### Fixed
- Removed 3 unused injected properties (`FetchController::$appManager`, `Provider::$l10n`, `AdminSection::$l`)
- Removed redundant runtime checks (`is_callable`, `method_exists`) that always evaluate to true
- Fixed SnappyMail API calls: `bUseSortIfSupported` → `bUseSort`, `MailClient::IsLoggined()` → `ImapClient()->IsLoggined()`
- Added type guards for `file_get_contents()` return values
- Fixed private method access pattern in `SnappyMailHelper`
- Added missing return type declarations and PHPDoc type annotations across 20 files

## [0.4.4] — 2026-03-19

### Fixed
- Skip SM bootstrap for app-password/token logins (bots, DAV clients, API)
- Graceful degradation when app/index.php is temporarily unreadable
- Guard against APP_DATA_FOLDER_PATH redefinition on retry after partial bootstrap

## [0.4.3] — 2026-03-19

### Fixed
- Setup and InstallStep now set title and loading_description to "Souvera Mail"
- Restored original minified app.min.js — no more broken JS from unminified overwrites
- Regenerated compressed .gz/.br static files to match modified JS/CSS
- Reverted PageController mailto handling to upstream SM ServiceMailto flow

## [0.4.2] — 2026-03-19

### Fixed
- Contact detail view now shows name and email for read-only (system) contacts
- Contact CRUD uses CardDAV backend directly — proper vCard N property support
- Numeric contact IDs for SnappyMail JS compatibility
- Contact tab restructured to match business tab layout (label + span + input)
- German labels corrected: "Vorname:" / "Nachname:" (singular + colon)
- Read-only contact spans visible via CSS specificity fix
- Empty name fields (middle name, prefix, suffix) hidden for read-only contacts

## [0.4.1] — 2026-03-19

### Fixed
- Bundled nextcloud plugin now syncs to SM data directory on every app enable/upgrade
- Contacts from all address books (including system/users) are now visible, system contacts marked read-only
- Contacts without email address are hidden from the contacts list
- `IManager::delete()` type handling fixed for NC CardDAV backend compatibility
- Search queries capped at 10,000 results for safety in large address books
- Double-slash in `app_path` when `overwritewebroot = /` prevented

## [0.4.0] — 2026-03-19

### Added
- **Nextcloud-native Contacts integration**: read, create, edit, and delete contacts directly in Nextcloud Contacts — no CardDAV sync, no separate database
- Autocomplete suggestions in To/Cc/Bcc fields now pull from Nextcloud Contacts
- `occ smail:setup` now enables contacts automatically

### Changed
- Contacts provider replaced: PdoAddressBook/SQLite → NextcloudAddressBook via NC IManager API
- Separate suggestions driver removed (unified into AddressBook provider)

### Fixed
- Dovecot OAuth2 docs link updated to 2.4+ documentation
- Added Dovecot 2.4+ version requirement to README

## [0.3.1] — 2026-03-18

### Fixed
- MailSo: SMTP CRLF injection prevention in MailFrom/Rcpt
- MailSo: IMAP EscapeString strips CR/LF/NUL from quoted strings
- MailSo: MIME parser recursion depth limit (max 50 levels)
- MailSo: SSLContext property whitelist in fromArray()
- MailSo: Sieve script name CRLF stripping
- MailSo: fix undefined variable in IdnToUtf8/IdnToAscii
- MailSo: Xxtea return type and parameter type for PHP 8.4
- NC Plugin: replace all `\OC::$server` with `\OCP\Server::get()`

### Changed
- Static version path — no renames on version bumps
- Version read from info.xml at runtime (single source of truth)
- Update check against own GitHub releases
- Auto-update disabled (managed releases only)
- About page: Souvera Mail branding with GitHub link

## [0.3.0] — 2026-03-18

### Security
- Fix S/MIME signature verification bypass (PKCS7_NOSIGS removed)
- Fix unsafe `unserialize()` in upgrade.php — restrict to scalars (prevent RCE)
- Fix TAR path traversal in plugin/update extraction
- Fix XSS via crafted RTF content (htmlspecialchars on output)
- Fix JWT broken encoding (wrong variable name)
- Add image decompression bomb protection (25MP limit)
- Fix SSO hash Time=0 bypass — require valid timestamp
- S/MIME cert path: basename() to prevent directory traversal
- Temp file: basename() to prevent path traversal
- TAR/ZIP: restrict Content-Type header chars to printable ASCII
- RTF: add recursion depth limit (max 100 levels)
- HTTP socket: instance-level Authorization storage (prevent cross-request leak)
- EXIF: validate MIME type before data:// URI construction
- Strict === comparison for session UID check

### Fixed
- PHP 8.4: OAuth2 MAC nonce — `uniqid()` replaced with `random_bytes()`
- PHP 8.4: JWT `openssl_pkey_free()` removed (deprecated since PHP 8.0)
- PHP 8.4: JWT `is_resource()` check updated for OpenSSLAsymmetricKey objects
- PHP 8.4: Imagick `setImageMatte()` replaced with `setImageAlphaChannel()`
- PHP 8.4: RTF `mb_convert_encoding` HTML-ENTITIES replaced with `html_entity_decode`
- PHP 8.4: OAuth2 SSL verification enabled by default (was disabled — MITM risk)
- PHP 8.4: HTTP socket `\split()` replaced with `\explode()` (removed since PHP 7)
- PHP 8.4: HTTP socket `\random_int()` fixed with required arguments
- PHP 8.4: CRAM SASL property declaration added
- PHP 8.4: `auto_detect_line_endings` removed (deprecated since PHP 8.1)
- PHP 8.4: lessphp class property declarations (15 dynamic properties)
- IMAP: OAUTHBEARER removed from wrong PLAIN/SCRAM branch (dead code fix)
- HTTP: `verify_peer` default changed to `true`, `CURLOPT_SSL_VERIFYHOST` enabled
- AdditionalAccount: fix `$aData` → `$aAccountHash` variable name bug
- Folders: fix undefined `$iErrorCode` variable
- TNEFDecoder: missing break in switch, null coalescing for buffer reads, typed property defaults
- TAR stream: fix undefined variable in addFromString
- S/MIME encrypt(): fix dead code return, remove duplicate fopen in sign()
- TNEFAttachment: buffer length sanity check

### Changed
- **Fork migration: SM Core v2.38.2 now tracked in git** (was gitignored + sed patches)
- Full SM Core audit completed: 6 CRITICAL, 15 HIGH, 16 MEDIUM findings fixed
- Automated release flow (build, sign, GitHub, NC App Store)

## [0.2.0] — 2026-03-18

### Added
- Setup Wizard web UI in admin settings with preflight checks
- Build and signing targets for NC App Store
- App Store metadata: author, repository, bugs, screenshots in info.xml

### Security
- Fix XSS via unescaped iframe src in templates/index.php
- Fix arbitrary file require via custom_config_file in InstallStep (realpath validation)
- Replace all `$_POST`/`$_GET`/`$_SERVER` direct access with `$this->request->getParam()`
- Add `hash_equals()` for admin panel key comparison (timing-safe)
- Add port range validation to `saveSetup()`
- Validate `app_path` to prevent protocol injection

### Fixed
- PHP 8.4: Remove deprecated `E_STRICT` constant from SM Logger
- PHP 8.4: Fix undefined array key "secure" in SM ConnectSettings
- PHP 8.4: Fix 32 implicit nullable parameters in SM PGP/GPG and Sabre VObject
- Chrome 117+: Fix invalid RegExp v-flag in folder create pattern
- NC 33: Replace 28 deprecated `\OC::$server` calls with `\OCP\Server::get()` or constructor DI
- NC 33: Replace 2 deprecated `\OC_User::isAdminUser()` with `IGroupManager::isAdmin()`
- NC 33: Replace 3 deprecated `getSystemConfig()` with `IConfig::getSystemValue()`
- NC 33: PHP 8 attributes replace deprecated PHPDoc annotations
- NC 33: DI autowiring replaces manual `$c->query()` controller registration
- Setup Wizard: API error feedback shown in UI instead of silent console.error
- Preflight check box and Delete Domain button contrast for light and dark themes

### Changed
- SM Core pinned at v2.38.2 with PHP 8.4 + browser compat patch set
- Auth type selection unified: OAUTHBEARER and XOAUTH2 merged into single SSO option
- SSO mode: hide Logout, Add Account, and redundant folder settings icon
- SSO mode: hide toggleLeftPanel button in settings view
- InstallStep removes SM default domains (gmail.com, hotmail.com, etc.) on install
- Licence updated to AGPL-3.0-or-later (SPDX format)
- GitHub URLs corrected to PhiGi87/souvera_mail

## [0.1.0] — 2026-03-18

### Added
- First working version
- SnappyMail v2.38.2 core engine
- OAUTHBEARER/XOAUTH2 IMAP authentication
- Automatic OIDC token refresh
- `occ smail:setup` command
- `occ smail:status` command
- Nextcloud 28-35 support
- PHP 8.1+ required
