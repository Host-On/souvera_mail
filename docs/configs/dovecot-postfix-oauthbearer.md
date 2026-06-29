# Dovecot 2.4+ / Postfix with Nextcloud as OIDC Provider

Reference configuration for running **Souvera Mail** against
**Dovecot 2.4+** (IMAP / ManageSieve) and **Postfix 3.7+** (submission)
where **Nextcloud itself is the OIDC Provider** via the
[H2CK/oidc](https://github.com/H2CK/oidc) app.

The recommended deployment target is Stalwart 0.16+ — see
[stalwart-oauthbearer.md](stalwart-oauthbearer.md). This document is
kept for operators on Dovecot/Postfix stacks who do not (yet) want to
migrate the mail server itself. Souvera Mail talks only standard
OAUTHBEARER/XOAUTH2 — both stacks are supported.

For an external IdP (Keycloak / Authentik / Authelia) chained in front
of Nextcloud, see [keycloak.md](keycloak.md).

## Scope

| Concern           | Component                                          |
|-------------------|----------------------------------------------------|
| IdP / OIDC OP     | Nextcloud + H2CK/oidc 1.17+                        |
| IMAP auth         | Dovecot 2.4+ (`OAUTHBEARER` / `XOAUTH2`)           |
| SMTP submission   | Postfix 3.7+ via Dovecot SASL socket               |
| ManageSieve auth  | Dovecot 2.4+                                       |
| Token validation  | Dovecot OAuth2 passdb, **local JWKS** (preferred)  |
| Webmail client    | Souvera Mail (this app)                            |

## 1) Token shape

Souvera Mail dispatches H2CK/oidc's `TokenGenerationRequestEvent` and
forwards the JWT via OAUTHBEARER. The JWT carries:

```jsonc
{
  "iss":    "https://nextcloud.example.com",
  "aud":    "souvera_mail",
  "sub":    "<NC user id>",
  "email":  "user@example.com",
  "scope":  "openid profile email",
  "exp":    <unix ts>
}
```

JWKS endpoint for offline validation:

```
https://nextcloud.example.com/index.php/apps/oidc/jwks
```

## 2) Dovecot OAuth2 passdb

Prefer **local JWT validation** against the H2CK/oidc JWKS endpoint
(no round-trip to Nextcloud on every auth) over `introspection_mode`.

`/etc/dovecot/dovecot-oauth2.conf.ext`:

```ini
# Local JWT validation against H2CK/oidc's JWKS
local_validation_key_dict = proxy::oauth2_jwks
tokeninfo_url             =                                                # leave empty -> no introspection
introspection_url         =                                                # leave empty -> no introspection
introspection_mode        = local

# Identity mapping
username_attribute        = email
active_attribute          = sub
active_value              =                                                # any sub is fine — group restriction is enforced by Nextcloud

# Audience + issuer pinning
issuers                   = https://nextcloud.example.com
audience                  = souvera_mail
```

`/etc/dovecot/conf.d/auth-oauth2.conf.ext`:

```ini
passdb {
  driver      = oauth2
  mechanisms  = xoauth2 oauthbearer
  args        = /etc/dovecot/dovecot-oauth2.conf.ext
}
```

`/etc/dovecot/conf.d/10-auth.conf`:

```ini
auth_mechanisms = $auth_mechanisms xoauth2 oauthbearer
!include auth-oauth2.conf.ext
```

JWKS proxy (the bit Dovecot's `proxy::oauth2_jwks` resolves):

`/etc/dovecot/conf.d/95-oauth2-jwks.conf`:

```ini
dict {
  oauth2_jwks = proxy::oauth2_jwks
}

dict_server oauth2_jwks {
  driver = http
  url    = https://nextcloud.example.com/index.php/apps/oidc/jwks
}
```

## 3) Postfix submission via Dovecot SASL

`/etc/postfix/master.cf` (submission service):

```ini
submission inet n - y - - smtpd
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_sasl_type=dovecot
  -o smtpd_sasl_path=private/auth
  -o smtpd_sasl_security_options=noanonymous
  -o smtpd_sasl_authenticated_header=yes
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
```

Dovecot SASL socket (`/etc/dovecot/conf.d/10-master.conf` excerpt):

```ini
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode  = 0660
    user  = postfix
    group = postfix
  }
}
```

## 4) Souvera Mail setup

```bash
sudo -u www-data php occ souvera_mail:bootstrap \
  --nc-base-url https://nextcloud.example.com \
  --imap-host  mail.example.com --imap-port 143 --imap-ssl starttls \
  --smtp-host  mail.example.com --smtp-port 587 --smtp-ssl starttls \
  --sieve --sieve-host mail.example.com --sieve-port 4190 --sieve-ssl starttls \
  --domain     example.com \
  --json
```

This will register Souvera Mail as `client_id = souvera_mail` with
`token_type = jwt` in H2CK/oidc, write the Dovecot/Postfix profile,
and verify with `occ souvera_mail:status`.

Full command reference:

| Command                                        | Purpose                                                                  |
|------------------------------------------------|--------------------------------------------------------------------------|
| `occ souvera_mail:setup`                       | Update domain profile without re-running OIDC client registration        |
| `occ souvera_mail:status [--json]`             | Diagnostic dump; exits non-zero on any blocker                           |
| `occ souvera_mail:oidc:register-client`        | (Re-)register Souvera Mail in H2CK/oidc                                  |
| `occ souvera_mail:reset`                       | Tear down all Souvera Mail state                                         |

## 5) Group restriction (Nextcloud side)

Souvera Mail 0.13.0+ is auto-restricted to the Nextcloud group
**`souvera-users`** by the
`OCA\SouveraMail\Migration\EnforceGroupRestriction` repair-step. Members
of other groups never see the app and cannot reach the mailbox UI.

```bash
sudo -u www-data php occ group:adduser souvera-users <uid>
```

The mail server itself does not need to enforce this — the JWT can only
be issued by H2CK for a logged-in NC user who already opened the app
(group check at navigation + controller layer).

## 6) Verify capabilities

Check IMAP capabilities include OAuth SASL:

```bash
openssl s_client -connect mail.example.com:143 -starttls imap -quiet
# then type: a1 CAPABILITY
```

Check SMTP AUTH list includes OAuth SASL:

```bash
openssl s_client -connect mail.example.com:587 -starttls smtp -quiet
# then type: EHLO test
```

Look for `AUTH ... OAUTHBEARER ... XOAUTH2`.

## 7) Common failures

| Symptom                                                 | Likely cause                                                                                       |
|---------------------------------------------------------|----------------------------------------------------------------------------------------------------|
| `AUTHENTICATIONFAILED` + `aud` mismatch                 | Dovecot `audience` does not match H2CK client id; re-run `occ souvera_mail:oidc:register-client`   |
| `AUTHENTICATIONFAILED` + `iss` mismatch                 | Wrong Nextcloud base URL in Dovecot `issuers`                                                      |
| `Dovecot: failed to fetch JWKS`                         | Dovecot host cannot reach `<NC>/index.php/apps/oidc/jwks` (firewall, TLS trust, DNS)               |
| Mail server cannot reach the JWKS endpoint              | Add the NC CA chain to Dovecot's trust store; verify with `curl -v <jwks-url>`                     |
| `Mail` nav entry missing for an existing user           | user not in `souvera-users`: `occ group:adduser souvera-users <uid>`                               |
| Token has no `email` claim                              | H2CK is not exposing `email` to the `souvera_mail` client — re-register with the right scopes      |
| Sieve auth fails but IMAP works                         | ManageSieve listener TLS mode does not match `--sieve-ssl`                                         |

## 8) References

- H2CK/oidc — <https://github.com/H2CK/oidc>
- Dovecot OAuth2 — <https://doc.dovecot.org/2.4/core/config/auth/databases/oauth2.html>
- Postfix SMTPD SASL — <https://www.postfix.org/SASL_README.html>
