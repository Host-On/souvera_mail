# Souvera Mail

**Nextcloud-native webmail with Nextcloud as the OIDC Provider.**

Souvera Mail is a fully CLI-configurable webmail app for Nextcloud 33+. It uses
[H2CK/oidc](https://github.com/H2CK/oidc) — Nextcloud's OpenID Connect Identity
Provider — to issue access tokens that authenticate the user against IMAP, SMTP
submission and ManageSieve via `OAUTHBEARER` / `XOAUTH2`. No external IdP is
required, no browser-redirect flow, no second login form.

Built for automated deployments (Ansible, Helm, OCI containers, Nextcloud-AIO
add-ons). **Everything is configurable through `occ` commands — there is no
browser-based setup wizard.** A read-only status panel exists for diagnostics.

---

## Architecture

Nextcloud itself acts as the OIDC Provider for the entire mail stack. Souvera Mail
and the mail server (Stalwart 0.16+, or Dovecot 2.4+ with Postfix 3.7+) are both
relying parties of the same provider. The mail server validates the issued JWTs
offline against `<NC>/index.php/apps/oidc/jwks` and lets the user in via
`OAUTHBEARER` / `XOAUTH2`.

The mail client talks to the mail server directly through JMAP (mail, Sieve
scripts, blobs) — no per-user passwords are stored anywhere.

---

## Features

- **Webmail v2** — fast Vue-3 client: split, vertical, list-only and focus-reader
  layouts, infinite scroll, per-identity signatures (incl. aliases and external
  accounts), drafts with autosave, resizable panels, dark/light content toggle.
- **Shared mailboxes** — accounts shared with the user appear as collapsible
  groups in the sidebar, synchronized via souvera_central.
- **External accounts** — optional IMAP/SMTP accounts (web.de, GMX, Gmail, …)
  appear in the navigation; mail is read live and sent through the external SMTP
  server. Disabled by default, enabled by the operator.
- **Sieve filters** — visual filter editor; all enabled filters are merged into a
  single active script (Stalwart runs one active script per account). Includes an
  out-of-office/vacation responder.
- **Migration assistant** — imports mail from an old provider via
  [provider.tools](https://provider.tools), with folder selection and progress.
- **Contacts / Files / Calendar** — NC Contacts search in compose, attach from NC
  Files, save attachments back to Files, calendar invitations.
- **Security** — group-restricted access, combined Nextcloud/DAV app passwords,
  session/Stalwart token revocation on logout, CSP-hardened mail rendering
  (sandboxed iframe, remote-content blocking).
- **Push notifications** — FCM push for new mail (optional).
- **Self-update** — built-in background updater from GitHub releases
  (stable channel: daily in the maintenance window, dev channel: every 5 minutes).

## Requirements

- Nextcloud 33–35, PHP 8.3+ with `mbstring`, `openssl`, `zlib`
- [H2CK/oidc](https://github.com/H2CK/oidc) 1.17+
  (`occ app:install oidc`)
- Mail server with OAUTHBEARER / XOAUTH2 support (Stalwart 0.16+ recommended)
- [souvera_central](https://github.com/Host-On/souvera_central) — cluster glue
  (account resolution, provider.tools token, shared mailboxes)
- [souvera_shield](https://github.com/Host-On/souvera_shield) — optional spam shield

## Installation

```bash
# place the app in the apps/ directory of your Nextcloud
git clone https://github.com/Host-On/souvera_mail.git apps/souvera_mail

cd /var/www/nextcloud
occ app:enable souvera_mail
occ souvera_mail:bootstrap                 # one-shot install (idempotent)
occ souvera_mail:setup                     # set the IMAP/SMTP/Sieve domain profile
occ souvera_mail:oidc:register-client      # register Souvera Mail as OIDC client
occ souvera_mail:status                    # diagnostic status (text or --json)
```

All write commands accept `--json` and `--dry-run`.

## Configuration

Configuration lives in the app config:

```bash
occ config:app:set souvera_mail min_refresh_interval --value 5   # auto-refresh floor (seconds)
occ config:app:set souvera_central stalwart_api_url --value https://mail.example.com/api
```

The self-updater is configured in `config.php`:

```php
'souvera.devops_token' => '…',
'souvera.maintenance_window_start' => '03:00',
```

## Development

```bash
npm install
npm run build          # builds js/souvera_mail-v2.js + js/souvera_mail-migration-wizard.js
```

PHP code lives in `lib/`, the Vue-3 client in `src-v2/`, the migration wizard in
`src/`. Every PHP controller is covered by `php -l` in CI; the app ships
vendor-less (no `composer install` needed on the target).

## License

[AGPL-3.0-or-later](LICENSE)

---
Maintained by the Souvera team. Bug reports and pull requests are welcome.
