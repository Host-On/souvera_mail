# Souvera Mail

**Nextcloud-native webmail with Nextcloud as the OIDC Provider.**

Souvera Mail is a fully CLI-configurable webmail app for Nextcloud 33+. It uses
[H2CK/oidc](https://github.com/H2CK/oidc) — Nextcloud's own OpenID Connect Identity
Provider — to issue access tokens that authenticate the user against IMAP, SMTP submission,
and ManageSieve via `OAUTHBEARER` / `XOAUTH2`. No external IdP is required, no
browser-redirect flow, no second login form.

Built for automated deployments (Ansible, Helm, OCI containers, Nextcloud-AIO addons).
**Everything is configurable through `occ` commands — there is no browser-based setup
wizard.** A read-only status panel exists for diagnostics only.

---

## Architecture

Nextcloud itself acts as the OIDC Provider for the entire mail stack. Souvera Mail and
Stalwart (or Dovecot+Postfix) are both relying parties of the same provider.

```text
                          ┌──────────────────────────────────────┐
                          │                Nextcloud             │
                          │                                      │
   user authenticates ───►│  (any backend: local / LDAP /        │
   (browser session)      │   user_oidc / SAML / passkey / …)    │
                          │                                      │
                          │  ┌────────────────────────────────┐  │
                          │  │   H2CK/oidc  (OIDC Provider)   │  │
                          │  │   - /.well-known/openid-       │  │
                          │  │     configuration              │  │
                          │  │   - /apps/oidc/jwks            │  │
                          │  │   - /apps/oidc/token  etc.     │  │
                          │  └──────────────┬─────────────────┘  │
                          │                 │ TokenGenerationEvent│
                          │  ┌──────────────▼─────────────────┐  │
                          │  │        Souvera Mail            │  │
                          │  │   gets JWT access token        │  │
                          │  │   in-process for {client=smail,│  │
                          │  │   user=<current NC user>}      │  │
                          │  └──────────────┬─────────────────┘  │
                          └─────────────────┼────────────────────┘
                                            │ OAUTHBEARER <jwt>
                          ┌─────────────────▼────────────────────┐
                          │           Mail server                │
                          │  (Stalwart / Dovecot+Postfix)        │
                          │                                      │
                          │  validates JWT against               │
                          │  <NC>/apps/oidc/jwks (cached)        │
                          │  → mailbox opens                     │
                          └──────────────────────────────────────┘
```

### Why this design

- **One identity provider for the whole stack.** Nextcloud already authenticates the user;
  the mail server trusts NC's signed JWTs. No second IdP to operate.
- **No browser redirect for webmail SSO.** Souvera Mail receives its access token via
  H2CK/oidc's `TokenGenerationRequestEvent` (in-process PHP event dispatch — see
  [H2CK/oidc README ¶ Token generation](https://github.com/H2CK/oidc#generate-an-access-token-and-id-token)).
  The token is bound to the currently logged-in Nextcloud user and the registered Souvera
  Mail client.
- **Mail server validates offline via JWKS.** Stalwart / Dovecot fetch
  `<NC>/index.php/apps/oidc/jwks` and verify tokens with the published RS256 public keys —
  no introspection round-trip per IMAP command.
- **CLI-first, idempotent.** Every config write is reachable via `occ`; reruns converge to
  the same state, so the same playbook deploys cleanly to dev, staging, and production.

---

## Requirements

| Component | Version | Notes |
|---|---|---|
| Nextcloud | 33 – 35 | Tested against current LTS and release-1 |
| PHP | 8.3+ | Same as Nextcloud's own requirement |
| [H2CK/oidc](https://github.com/H2CK/oidc) | 1.17+ | Install + enable before `occ smail:bootstrap` |
| Mail server | Stalwart 0.16+ **or** Dovecot 2.4+ with Postfix 3.7+ | Must advertise `AUTH=OAUTHBEARER` / `AUTH=XOAUTH2` on IMAP + submission |
| Network | Mail server can reach `<NC>/index.php/apps/oidc/jwks` over HTTPS | TLS to NC must be trusted by the mail server's cert store |

Souvera Mail is a webmail client. It does **not** replace your MTA, gateway, or spam
filter — it sits next to whatever mail stack you already operate.

---

## Quick start (automated deploy)

The whole setup is three `occ` commands. They are idempotent — running them again on the
same instance is safe and a no-op when nothing changes.

```bash
# 1. Install + enable H2CK/oidc (Nextcloud becomes the OIDC Provider)
occ app:install oidc
occ app:enable  oidc

# 2. Install + enable Souvera Mail
occ app:install smail        # or: extract tarball into custom_apps/ and `occ app:enable smail`

# 3. Bootstrap everything (registers the OIDC client, wires up engine config, runs preflight)
occ smail:bootstrap \
    --mail-imap-host  mail.example.com --mail-imap-port  993 --mail-imap-ssl  ssl \
    --mail-smtp-host  mail.example.com --mail-smtp-port  465 --mail-smtp-ssl  ssl \
    --mail-sieve-host mail.example.com --mail-sieve-port 4190 --mail-sieve-ssl ssl \
    --domain          example.com \
    --client-secret-out /etc/souvera_mail/oidc-client-secret \
    --json
```

`occ smail:bootstrap` is one-shot and does, in order:

1. Verifies H2CK/oidc is installed and enabled.
2. Creates (or updates) an OIDC client named `smail` in H2CK/oidc via `occ oidc:create` —
   confidential client, JWT access tokens (RFC 9068), 30-minute access-token lifetime,
   `openid email profile offline_access` scopes, audience `smail`.
3. Writes the client's `client_id` and `client_secret` into Souvera Mail's app-config and
   optionally dumps the secret to the path passed via `--client-secret-out` (mode `0600`,
   owner `www-data`).
4. Calls `smail:setup` with the IMAP/SMTP/Sieve flags to set the active mail-domain
   profile.
5. Runs `smail:status --json` to confirm everything resolves — exits non-zero on any
   blocker so your deploy pipeline fails fast.

A full machine-readable report is printed on `--json`.

---

## `occ` command reference

| Command | Purpose | Important flags |
|---|---|---|
| `occ smail:bootstrap`   | One-shot install: register OIDC client, set engine defaults, run preflight | `--mail-imap-host`, `--mail-smtp-host`, `--mail-sieve-host`, `--domain`, `--client-secret-out`, `--json`, `--dry-run` |
| `occ smail:setup`       | Update mail-server profile (IMAP/SMTP/Sieve hosts, ports, TLS modes, audience) | `--imap-host`, `--imap-port`, `--imap-ssl`, `--smtp-host`, `--smtp-port`, `--smtp-ssl`, `--sieve`, `--sieve-host`, `--sieve-port`, `--sieve-ssl`, `--domain`, `--oidc-audience`, `--oidc-scopes`, `--skip-checks`, `--json`, `--dry-run` |
| `occ smail:oidc:register-client` | Re-register the OIDC client in H2CK/oidc (rotate secret, change redirect URIs) | `--redirect-uri` (multi), `--secret-out`, `--token-lifetime`, `--json`, `--dry-run` |
| `occ smail:status`      | Diagnose configuration, connectivity, OIDC, mail server preflight | `--json` |
| `occ smail:reset`       | Remove all Souvera Mail state (app-config, engine domain profile, optional OIDC client) | `--purge-oidc-client`, `--keep-engine-data`, `--json` |

All write-commands accept `--dry-run` to print exactly what would change without touching
anything, and `--json` to emit machine-readable output (one JSON object per invocation,
parseable from Ansible's `command:` module).

Defaults pulled from `occ config:app:set oidc default_token_type jwt` are required and set
automatically by `smail:bootstrap`.

---

## Mail server configuration

The mail server validates incoming `OAUTHBEARER` tokens against the Nextcloud JWKS
endpoint. Configuration recipes:

- [`docs/configs/stalwart-oauthbearer.md`](docs/configs/stalwart-oauthbearer.md) — Stalwart 0.16+ (integrated IMAP, SMTP, ManageSieve, JWT validation, optional LDAP directory)
- [`docs/configs/dovecot-postfix-oauthbearer.md`](docs/configs/dovecot-postfix-oauthbearer.md) — Dovecot 2.4+ + Postfix 3.7+ via Dovecot SASL OAuth2

Both recipes use the same JWKS URL (`<NC>/index.php/apps/oidc/jwks`) and accept tokens
issued by H2CK/oidc to the `smail` audience.

---

## Read-only admin status panel

Open **Settings → Souvera Mail** in the Nextcloud admin UI. The panel shows the current
configuration and preflight diagnostics — every interactive element is read-only and
points the operator at the matching `occ` command:

```text
✓ H2CK/oidc enabled, client `smail` registered, JWT access tokens, 1800s TTL
✓ Mail domain: example.com  (active profile)
✓ IMAP   mail.example.com:993   ssl   (advertises AUTH=OAUTHBEARER, XOAUTH2)
✓ SMTP   mail.example.com:465   ssl   (advertises AUTH=OAUTHBEARER, XOAUTH2)
✓ Sieve  mail.example.com:4190  ssl   (advertises AUTH=OAUTHBEARER)
✓ JWKS   reachable from this Nextcloud, 2 active keys
✓ Last bootstrap: 2026-01-15 14:22:08 UTC by user `admin` (via occ)

Need to change something? Run:  occ smail:setup --imap-host … (etc.)
```

The panel has zero write endpoints. Configuration changes happen only via `occ`.

---

## Troubleshooting

### `occ smail:status` reports H2CK/oidc not installed
Install and enable the app:
```bash
occ app:install oidc && occ app:enable oidc && occ smail:bootstrap …
```

### IMAP login fails with `AUTHENTICATIONFAILED`
1. Check `occ smail:status --json | jq .oidc` — confirm the `smail` client exists in
   H2CK/oidc and JWT access tokens are enabled (`default_token_type=jwt`).
2. Confirm the mail server can resolve and reach
   `<NC>/index.php/apps/oidc/jwks` (TLS, DNS, firewall).
3. Verify the mail server's expected `aud` claim matches the smail client's audience
   (default: `smail`). Override at deploy time:
   ```bash
   occ smail:setup --oidc-audience my-custom-audience
   ```

### Token expired during a long mailbox operation
Souvera Mail's `TokenRefreshMiddleware` re-issues a fresh JWT through H2CK/oidc's
`TokenGenerationRequestEvent` whenever the cached token is within 60 seconds of expiry.
Increase the lifetime if your operations regularly exceed the default 30 min:
```bash
occ config:app:set oidc expire_time --value "3600"
```

### Souvera Mail panel says "JWKS unreachable"
The Nextcloud host itself runs the preflight; if the panel says JWKS is unreachable, that
means PHP on Nextcloud cannot fetch its own JWKS URL (proxy, hosts file, TLS interception).
Check `occ smail:status` output and Nextcloud's `nextcloud.log` for the HTTP error.

---

## Why no browser wizard?

This app is built for environments where Nextcloud installs are templated, immutable, and
recreated from declarative manifests. Browser wizards that hold operator state are
incompatible with that. Everything Souvera Mail needs to know is expressible as `occ`
flags; everything Souvera Mail wants to tell you is shipped on `stdout` as JSON when you
ask. That keeps the app deployable from Ansible, Argo, Helm, Nextcloud-AIO addons, or
hand-rolled shell scripts without any UI scraping.

---

## Origin

Souvera Mail is a permanent fork of
[SnappyMail v2.38.2](https://github.com/the-djmaze/snappymail/releases/tag/v2.38.2),
rebuilt for Nextcloud 33+ with Nextcloud-OIDC-Provider native SSO. The bundled webmail
engine (`app/smail/v/current/`) preserves the upstream SnappyMail/RainLoop code structure
and license; the Nextcloud wrapper (`lib/`) and the H2CK/oidc integration are original
to this project.

## License

AGPL-3.0 — see [LICENSE](LICENSE).

See also: [CHANGELOG.md](CHANGELOG.md) · [RELEASE.md](RELEASE.md) · [SECURITY.md](SECURITY.md).
