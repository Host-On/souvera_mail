# Stalwart 0.16+ with Nextcloud as OIDC Provider

Reference configuration for running **Souvera Mail** against
**Stalwart 0.16+** where **Nextcloud itself is the OIDC Provider** via the
[H2CK/oidc](https://github.com/H2CK/oidc) app — no external IdP required.

This is the supported architecture as of Souvera Mail 0.13.0. For setups
that still front their stack with Keycloak / Authentik / Authelia in
addition to H2CK/oidc, see [keycloak.md](keycloak.md) (kept as an
appendix; not the recommended path).

## Scope

| Concern             | Component                                         |
|---------------------|---------------------------------------------------|
| IdP / OIDC OP       | Nextcloud + H2CK/oidc 1.17+ (`apps/oidc`)         |
| Token validation    | Stalwart 0.16+ OIDC directory, JWKS               |
| IMAP auth           | Stalwart (`OAUTHBEARER` / `XOAUTH2`)              |
| SMTP submission     | Stalwart (`OAUTHBEARER` / `XOAUTH2`)              |
| ManageSieve auth    | Stalwart (`OAUTHBEARER` / `XOAUTH2`)              |
| Mailbox provisioner | `souvera_central` (separate Nextcloud app)        |
| Webmail client      | Souvera Mail (this app)                           |

## 1) Token shape

Souvera Mail never asks the browser for a token. It receives access
tokens **in-process** by dispatching H2CK/oidc's
`TokenGenerationRequestEvent` for the logged-in NC user, then uses the
JWT for OAUTHBEARER against Stalwart.

The JWT carries (as configured by H2CK/oidc when `--token_type=jwt`):

```jsonc
{
  "iss":    "https://nextcloud.example.com",
  "aud":    "souvera_mail",       // the OIDC client_id registered by `occ souvera_mail:oidc:register-client`
  "sub":    "<NC user id>",
  "email":  "user@example.com",   // routed via souvera_central -> Stalwart principal email
  "scope":  "openid profile email",
  "exp":    <unix ts>
}
```

JWKS endpoint that Stalwart validates against:

```
https://nextcloud.example.com/index.php/apps/oidc/jwks
```

## 2) Stalwart OIDC directory

`/etc/stalwart/config.toml` (excerpt — adapt host names):

```toml
[directory."oidc"]
type            = "oidc"
issuer          = "https://nextcloud.example.com"
jwks-url        = "https://nextcloud.example.com/index.php/apps/oidc/jwks"
audience        = "souvera_mail"
claim-username  = "email"
cache           = "5m"
```

```toml
[server.listener."imap"]
protocol = "imap"
bind     = "[::]:993"
tls.implicit = true
```

```toml
[storage]
directory = "oidc"          # use the OIDC directory above for principal lookup
```

Mailbox / quota objects themselves are provisioned by `souvera_central`
through Stalwart's management JMAP. The OIDC directory is only used for
authentication — never for storing mailboxes.

## 3) Listener strategy

Pick one TLS strategy and keep Souvera Mail values aligned.

- **STARTTLS**: IMAP `143` + SMTP `587` + Sieve `4190` (all `starttls`)
- **Implicit TLS**: IMAP `993` + SMTP `465` + Sieve `4190` (implicit
  listener configured)

## 4) Souvera Mail setup

The browser-based setup wizard was removed in 0.9.0; configuration is
CLI-only via the renamed `souvera_mail` command set:

```bash
sudo -u www-data php occ souvera_mail:bootstrap \
  --nc-base-url https://nextcloud.example.com \
  --imap-host  mail.example.com --imap-port 993  --imap-ssl ssl \
  --smtp-host  mail.example.com --smtp-port 465  --smtp-ssl ssl \
  --sieve --sieve-host mail.example.com --sieve-port 4190 --sieve-ssl ssl \
  --domain     example.com \
  --json
```

`souvera_mail:bootstrap` is **idempotent** — re-running converges to the
desired state. It will:

1. Verify H2CK/oidc is installed and has a signing key.
2. Register Souvera Mail as a confidential OIDC client (`client_id =
   souvera_mail`, `token_type = jwt`) via `occ oidc:create`.
3. Write the IMAP/SMTP/Sieve domain profile to Souvera Mail's app-config.
4. Run `occ souvera_mail:status` to confirm the result.

Other commands:

| Command                                        | Purpose                                                                  |
|------------------------------------------------|--------------------------------------------------------------------------|
| `occ souvera_mail:setup`                       | Update the IMAP/SMTP/Sieve domain profile without re-running OIDC steps  |
| `occ souvera_mail:status [--json]`             | Diagnostic dump; exits non-zero on any blocker                           |
| `occ souvera_mail:oidc:register-client`        | (Re-)register Souvera Mail as an H2CK/oidc client                        |
| `occ souvera_mail:reset`                       | Tear down all Souvera Mail config; optional `--purge-oidc-client`        |

## 5) Group restriction

Souvera Mail 0.13.0+ binds itself to the Nextcloud group
**`souvera-users`** automatically on every `app:enable` and `upgrade`
(via `OCA\SouveraMail\Migration\EnforceGroupRestriction`). Members of
other groups never see the navigation entry and cannot open
`/index.php/apps/souvera_mail/…`. To grant a user access:

```bash
sudo -u www-data php occ group:adduser souvera-users <uid>
```

To use a different allowed group, override the constant
`OCA\SouveraMail\AppInfo\Application::RESTRICTED_GROUP_ID` in your build
or contact the maintainers — `occ app:enable souvera_mail --groups
<other>` works at runtime but is reset on every upgrade by the repair
step.

## 6) Identity and audience checklist

The H2CK/oidc client registration done by `souvera_mail:bootstrap`
already sets these correctly. The list below is for operators
debugging an existing setup.

- `aud` includes `souvera_mail` — set by H2CK at client registration
  time.
- `email` claim present and matches the mailbox principal — H2CK pulls
  it from NC's user account; `souvera_central` is responsible for
  keeping that NC user ↔ Stalwart principal mapping in sync.
- Stalwart `audience` setting matches the H2CK client id
  (`souvera_mail`).
- Stalwart `claim-username` is `email` (or the claim your
  `souvera_central` provisions principals against).

## 7) Troubleshooting patterns

| Symptom                                         | Likely cause                                                                                          |
|-------------------------------------------------|-------------------------------------------------------------------------------------------------------|
| `AUTHENTICATIONFAILED` + domain errors          | mail domain missing/disabled in Stalwart, or `souvera_central` has not provisioned the mailbox        |
| `AUTHENTICATIONFAILED` + `aud` mismatch         | H2CK client id was rotated; re-run `occ souvera_mail:oidc:register-client --force`                    |
| `OIDC: jwks-url unreachable`                    | Stalwart cannot reach `<NC>/index.php/apps/oidc/jwks` — firewall, DNS, or wrong base URL              |
| `Mail` nav entry missing for an existing user   | user not in `souvera-users`: `occ group:adduser souvera-users <uid>`                                  |
| Sieve TLS/auth mismatch                         | `--sieve-ssl` does not match the Stalwart Sieve listener TLS mode                                     |
| App passwords UI shows "not available"          | `souvera_central.stalwart_api_url` not set, or `souvera_central` app is missing                       |
| Quota pill never appears                        | same prerequisites as App passwords + JMAP capability `urn:stalwart:jmap` must be enabled in Stalwart |

## 8) References

- H2CK/oidc — <https://github.com/H2CK/oidc>
- Stalwart Mail Server — <https://github.com/stalwartlabs/mail-server>
- Stalwart App Passwords spec — <https://stalw.art/docs/auth/authentication/app-password/>
- Souvera Central — internal Nextcloud app responsible for the NC ↔
  Stalwart principal mapping (mailbox provisioning, quota)
