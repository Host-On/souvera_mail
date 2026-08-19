# Shared Contract: External Mail Account Settings

**Producer:** `souvera_central` (owns the persisted state — single source of
truth across every Souvera app on the instance)
**Consumer:** `souvera_mail` (reads via internal API; falls back to
"disabled" when the service isn't installed yet)
**Version:** 1.0 · 2026-02
**Status:** Draft — please implement in `souvera_central` and confirm the FQN
of the exposed service.

---

## Why this contract exists

Souvera Mail v0.15.0 introduces the ability for users to attach EXTERNAL
mail accounts (web.de, GMX, t-online.de, Gmail, Outlook…) to the same
webmail client, so they don't have to keep switching between apps.

The feature is:

* **disabled by default** on fresh installs,
* **admin-toggleable** via OCC on `souvera_central` (single place),
* **group-restrictable** — opt-in to a Nextcloud group only,
* **capped** — max N external accounts per user,
* **consent-gated** — GDPR modal shown on every new account add.

Because "Alle Einstellungen leben in Central", `souvera_mail` MUST NOT
duplicate these keys in its own `app_config`. It reads them from
`souvera_central` via a stable class contract.

---

## Service class

Please expose the following class in `souvera_central`:

```php
namespace OCA\SouveraCentral\Service;

/**
 * Read/write API for external-mail-account settings.
 *
 * Consumed by:
 *  - souvera_mail (read-only; boots `webmail.allow_additional_accounts` from here)
 *  - souvera_shield (read-only; may add extra spam scoring for external inbound)
 *
 * All writes must be gated behind admin auth or CLI (occ). No REST
 * endpoint should expose the setters to authenticated users.
 */
class ExternalAccountsConfigService
{
    // ================================================================
    // READ API — must be side-effect-free and cheap (call on every
    // page load without hitting a slow codepath).
    // ================================================================

    /**
     * Master switch. When false, the consumer disables the entire feature
     * regardless of the other keys.
     */
    public function isEnabled(): bool;

    /**
     * List of NC group IDs that are allowed to use the feature.
     *
     * Semantics:
     *  - Empty array   → every user is allowed (default when the feature
     *                    is enabled without --groups).
     *  - Non-empty     → the user MUST be a member of at least one listed
     *                    group. Groups that no longer exist are ignored.
     *
     * @return list<string>
     */
    public function getAllowedGroups(): array;

    /**
     * Maximum number of external accounts a user may attach. MUST be
     * ≥ 1 whenever isEnabled() is true. Default: 3.
     */
    public function getMaxAccountsPerUser(): int;

    /**
     * Whether the "keep this mailbox as an external account?" CTA at
     * the end of the one-shot IMAP migration wizard is offered.
     * Default: true.
     */
    public function isMigrationHandoffEnabled(): bool;

    /**
     * Whether the SMTP-fail auto-deactivation guard is active. When
     * true, 3 consecutive send failures within 24h on the same external
     * account will auto-disable that account and email the user.
     * Default: true.
     */
    public function isSmtpFailGuardEnabled(): bool;

    /**
     * Whether the DSGVO ("GDPR") consent modal must appear on EVERY new
     * account add. This is the option corresponding to the user's
     * choice `4c` in the roadmap. Default: true.
     */
    public function isConsentRequired(): bool;

    /**
     * One-stop per-user permission check. Encapsulates isEnabled() +
     * group membership so consumers don't have to replicate group logic.
     *
     * @param string $uid  Nextcloud user id
     */
    public function isAllowedForUser(string $uid): bool;

    /**
     * Serialised snapshot for `occ … :status --json` dumps. Every value
     * that a diagnostic/support script may need. MUST NOT contain
     * secrets.
     *
     * Expected shape:
     * {
     *   "enabled": bool,
     *   "allowed_groups": string[],
     *   "max_per_user": int,
     *   "migration_handoff": bool,
     *   "smtp_fail_guard": bool,
     *   "consent_required": bool,
     *   "central_version": string   // souvera_central app version
     * }
     */
    public function snapshot(): array;

    // ================================================================
    // WRITE API — called only from souvera_central's own OCC commands
    // or admin settings page. Consumers (souvera_mail, souvera_shield)
    // MUST NOT call these; if they need to change something they must
    // do it via occ on souvera_central.
    // ================================================================

    public function setEnabled(bool $enabled): void;
    public function setAllowedGroups(array $groupIds): void;
    public function setMaxAccountsPerUser(int $max): void;
    public function setMigrationHandoffEnabled(bool $enabled): void;
    public function setSmtpFailGuardEnabled(bool $enabled): void;
    public function setConsentRequired(bool $required): void;
}
```

---

## Underlying `app_config` keys (owned by `souvera_central`)

Suggested storage layout (implementation detail — consumers only touch
the service class, never the raw keys):

| Key                                                     | Type        | Default | Notes                        |
| ------------------------------------------------------- | ----------- | ------- | ---------------------------- |
| `souvera_central:external_accounts.enabled`             | bool (0/1)  | `0`     | Master switch                |
| `souvera_central:external_accounts.groups`              | JSON list   | `[]`    | Allowed NC group ids         |
| `souvera_central:external_accounts.max_per_user`        | int         | `3`     | Cap                          |
| `souvera_central:external_accounts.migration_handoff`   | bool (0/1)  | `1`     | Wizard follow-up             |
| `souvera_central:external_accounts.smtp_fail_guard`     | bool (0/1)  | `1`     | Auto-deactivation on 3× fail |
| `souvera_central:external_accounts.consent_required`    | bool (0/1)  | `1`     | GDPR modal                   |

---

## OCC commands `souvera_central` should ship

The commands must be idempotent and safe to run on any node of a
multi-server cluster (single-write path via `app_config`).

### `souvera_central:external:enable`

```
Usage:
  occ souvera_central:external:enable [options]

Options:
  --groups=<comma>         Restrict to these NC groups (e.g. --groups=power-users,mail-beta)
  --max-per-user=<n>       Cap per user (default 3, min 1, max 20)
  --consent-required=y|n   GDPR modal on every add (default y)
  --smtp-guard=y|n         Auto-deactivate on 3× SMTP fail (default y)
  --migration-handoff=y|n  Offer "keep as external" in wizard (default y)
  --json                   Emit result as machine-readable JSON

Exit codes:
  0  applied
  2  invalid input (e.g. max < 1)
  3  souvera_central not fully bootstrapped
```

### `souvera_central:external:disable`

```
Usage:
  occ souvera_central:external:disable [options]

Options:
  --purge          Also delete every external account already attached
                   by users (irreversible). Without --purge the feature
                   just becomes invisible; users can re-enable and their
                   accounts come back.
  --json           Machine-readable result.

Exit codes:
  0  disabled
  3  central not bootstrapped
```

### `souvera_central:external:status`

Read-only diagnostic. Prints the value of `snapshot()` — either as a
human-readable table or `--json`. Exits 0 always (this command is safe
to shell-pipe from health probes).

### `souvera_central:external:configure`

Fine-grained toggles without changing enable/disable state.

```
Usage:
  occ souvera_central:external:configure [options]

Options:
  --groups=<comma>         Overwrite the allowed-groups list.
  --max-per-user=<n>       Overwrite the cap.
  --consent-required=y|n   Overwrite consent flag.
  --smtp-guard=y|n         Overwrite guard flag.
  --migration-handoff=y|n  Overwrite migration handoff flag.
```

---

## How `souvera_mail` consumes this

* Constructor injects nothing; the service is resolved lazily via
  `\OCP\Server::get(\OCA\SouveraCentral\Service\ExternalAccountsConfigService::class)`
  so consumers stay loadable when `souvera_central` is not installed.
* If Central isn't installed / class not resolvable / method throws:
  the consumer treats the feature as **disabled** with a **degraded
  diagnostic** in `souvera_mail:status`.
* On every request `souvera_mail`'s `EngineHelper::loadApp()` calls
  `isEnabled()` and sets Snappymail's engine-side
  `webmail.allow_additional_accounts` accordingly.
* Group check happens in the `main.fabrica` hook of the Nextcloud
  Snappymail plugin: it substitutes `Capa::ADDITIONAL_ACCOUNTS` back to
  `false` for a signed-in user who is enabled globally but not member
  of any of `getAllowedGroups()`.

---

## Non-goals

* This contract does **not** own per-user data. The credentials for
  each external account remain in `souvera_mail`'s Snappymail engine
  storage (encrypted with `APP_SALT`). `souvera_central` only owns
  the **policy** (who's allowed, how many, under which safeguards).
* Central does not need to expose a REST endpoint. Direct PHP
  class-lookup is sufficient (mirrors the existing
  `ProviderTokenService` pattern used for provider.tools tokens).

---

## Migration path

1. `souvera_central` ships v1.x with `ExternalAccountsConfigService`
   and the four OCC commands. Existing `souvera_central` installs
   receive the new keys with their default values on `occ maintenance:repair`.
2. `souvera_mail` v0.15.0 ships with the CONSUMER side already
   implemented (this repo). Until Central is upgraded it degrades to
   "feature disabled" transparently.
3. Admins run `occ souvera_central:external:enable [--groups=…]` when
   they're ready.

---

## Test hooks Central should provide

For consumer regression tests to be reliable, expose (only in test
builds) a helper that resets every key to its default without touching
production data. E.g. a `\OCA\SouveraCentral\Testing\Reset::externalAccounts()`
static or a `--reset` flag on `configure`.

---

**Please implement `\OCA\SouveraCentral\Service\ExternalAccountsConfigService`
exactly as described here. If the FQN needs to change, tell me and I'll
adjust the consumer constant `ExternalAccountsConfig::CENTRAL_SERVICE_FQN`
in `souvera_mail`.**
