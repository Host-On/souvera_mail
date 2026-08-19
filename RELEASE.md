# Release Process

## Versioning

Souvera Mail follows [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`

- **MAJOR** — Breaking changes
- **MINOR** — New features
- **PATCH** — Bug fixes, security patches

## Installation

Download the latest release from [GitHub Releases](https://github.com/Host-On/souvera_mail/releases).

## Self-update

The app ships a built-in self-updater. When configured (`souvera.devops_token` in
`config.php`), it checks for new GitHub releases in the background and applies
them automatically — stable channel once per day in the maintenance window,
dev channel every five minutes.

## Manual upgrade

Replace the app directory with the new release and run:

```bash
occ upgrade
occ souvera_mail:status
```
