# Changelog

All notable changes to Souvera Mail will be documented in this file.

Format: [Semantic Versioning](https://semver.org/) — MAJOR.MINOR.PATCH

## [1.2.1] — 2026-08

Repository moved to the Host-On organization. This release removes the legacy
SnappyMail engine (the v2 client is the only frontend) and repoints the
self-update sources.

## [1.2.0] — 2026-08

### Added

- Focus-reader layout: centered reading card over a dimmed list, direction-aware
  page-turn animation, arrow-key and on-screen navigation.
- Infinite scroll in the mail list (replaces pagination).
- List-only layout with fullscreen message view.
- Draft dialog on close (keep / discard / cancel); one draft per compose session.
- Per-user persistence of expanded/collapsed subfolders.
- Sieve: body conditions as real `body` tests, regex support, full hierarchy
  paths for move-to-folder targets, merge-safe activation, `occ
  souvera_mail:sieve:debug <uid>` diagnostics.
- Mail content: inner padding, line-break preservation for plain-HTML mails,
  loading indicator until the message frame has rendered.

### Fixed

- External images and auto-refresh settings now persist.
- Settings rows render the persisted values immediately (no flash of defaults).
- Signature icon stays visible in every theme.
- Self-updater heals incomplete app trees; migration wizard reports real HTTP
  errors for non-JSON responses.
