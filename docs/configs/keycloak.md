# Appendix: External IdP (Keycloak / Authentik / Authelia) Alongside H2CK/oidc

> **This is an advanced / legacy scenario.** The supported architecture
> for Souvera Mail is **Nextcloud + H2CK/oidc as the only IdP** for the
> mail stack — see [stalwart-oauthbearer.md](stalwart-oauthbearer.md)
> and [dovecot-postfix-oauthbearer.md](dovecot-postfix-oauthbearer.md).
> Only read this page if you must integrate an existing external IdP
> in front of Nextcloud (corporate SSO, federated identity).

In this layout:

```
User -> External IdP (Keycloak/Authentik/Authelia)
                |  (SAML / OIDC, e.g. via Nextcloud's user_oidc app)
                v
            Nextcloud
                |  (H2CK/oidc inside NC — OIDC OP for the mail stack)
                v
        Souvera Mail / Stalwart / Dovecot
```

The mail stack only sees H2CK/oidc tokens. The external IdP is invisible
to Stalwart/Dovecot. Souvera Mail itself needs no Keycloak configuration.

## Why this is rarely needed

H2CK/oidc already turns Nextcloud into a fully-featured OIDC OP. The
mail stack does not benefit from being chained to a second IdP — every
additional hop is one more JWKS to validate, one more rotation
schedule, one more failure mode. Use this setup only when:

- Your organisation already runs an external IdP and you cannot move
  user identities into Nextcloud-native accounts.
- You need federation with non-Nextcloud services (Slack, AWS, …) that
  cannot use H2CK/oidc directly.

If you do not have one of those constraints, follow
[stalwart-oauthbearer.md](stalwart-oauthbearer.md) and stop reading.

## Identity flow

1. User authenticates against Keycloak/Authentik/Authelia.
2. Nextcloud's `user_oidc` (or SAML) app accepts the assertion and
   creates / matches a Nextcloud account.
3. The user opens Souvera Mail. Souvera Mail dispatches H2CK/oidc's
   `TokenGenerationRequestEvent` — H2CK/oidc issues an **NC-signed** JWT
   for the `souvera_mail` client. The external IdP is **not consulted**
   at this step.
4. The H2CK/oidc JWT is presented to Stalwart/Dovecot. Validation goes
   against `<NC>/index.php/apps/oidc/jwks` — same as the recommended
   architecture.

So the only thing the external IdP changes is **how the user signs
into Nextcloud in the first place**. Once H2CK has stamped the NC
session, the rest of the mail stack works identically to the H2CK-only
deployment.

## Configuration sketch

### A) External IdP -> Nextcloud (`user_oidc`)

1. In Keycloak/Authentik/Authelia: register Nextcloud as an OIDC client.
   - `redirect_uri`: `https://nextcloud.example.com/apps/user_oidc/code`
   - `email` claim must be present.
2. In Nextcloud: enable `user_oidc`, register the external IdP, and map
   `email` to the NC user `email`.
3. Verify login: NC `→` Sign in `→` IdP redirect `→` NC profile shows
   the federated identity.

### B) Nextcloud / H2CK/oidc -> mail stack

No changes vs. the recommended H2CK-only path. Run
`occ souvera_mail:bootstrap …` exactly as documented in
[stalwart-oauthbearer.md](stalwart-oauthbearer.md). Stalwart/Dovecot
validate against `<NC>/index.php/apps/oidc/jwks` and only see
H2CK-issued tokens.

### C) Group restriction still applies

Souvera Mail 0.13.0+ binds itself to the NC group `souvera-users`. The
external IdP must therefore push group membership into Nextcloud (most
deployments use LDAP groups or a Keycloak claim mapper into a NC user
attribute, then map that attribute onto a NC group via `user_oidc`'s
group sync). Without the group, the user still gets an NC session but
the Souvera Mail nav entry stays hidden.

```bash
# Manual fallback if your IdP cannot push groups yet:
sudo -u www-data php occ group:adduser souvera-users <uid>
```

## What you do **not** need to do

- **Do not** add a `mail-service` client to your external IdP — Souvera
  Mail does not authenticate against it.
- **Do not** configure Stalwart/Dovecot OIDC directories against the
  external IdP. They must point at H2CK/oidc's JWKS.
- **Do not** configure token exchange between the external IdP and
  Souvera Mail. H2CK/oidc already issues a mail-scoped token in-process.

## When the mail server **also** needs to validate external IdP tokens

This is the case if you have non-Souvera mail clients (legacy IMAP
clients with their own OIDC flow, third-party mail apps) that
authenticate directly against the external IdP without going through
Nextcloud first. That is **out of scope** for this document — Souvera
Mail and the Nextcloud-driven flows in this app are entirely H2CK-bound.

For app-password style legacy clients, use Souvera Mail's built-in **App
Passwords** UI at `/index.php/apps/souvera_mail/settings`, which talks
to Stalwart's `x:AppPassword` JMAP endpoint and hands the user a
short-lived IMAP/SMTP credential they can paste into their mail app.
That path does not involve the external IdP either.

## References

- H2CK/oidc — <https://github.com/H2CK/oidc>
- Nextcloud `user_oidc` — <https://github.com/nextcloud/user_oidc>
- Stalwart OIDC directories — <https://stalw.art/docs/auth/backend/oidc/>
