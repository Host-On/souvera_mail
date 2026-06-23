# Release Process

## Versioning

Souvera Mail follows [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`

- **MAJOR** — Breaking changes
- **MINOR** — New features
- **PATCH** — Bug fixes, security patches

## Installation

Download the latest release from [GitHub Releases](https://github.com/PhiGi87/souvera_mail/releases) or install via the [Nextcloud App Store](https://apps.nextcloud.com/apps/smail).

## Upgrade

Nextcloud handles upgrades automatically when a new version is published to the App Store. For manual upgrades, replace the app directory and run:

```bash
occ upgrade
occ smail:status
```
