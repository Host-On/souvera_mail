# Changelog

## [1.2.15] — 2026-08

### Fixed

- Rechtsklick-Menü: zusätzlich NATIVE Event-Listener (Container +
  Document-Fallback mit Capture), unabhängig von Vues Template-Bindung;
  jeder Fehlpfad zeigt jetzt eine sichtbare Diagnosemeldung.

## [1.2.14] — 2026-08

### Fixed

- Rechtsklick: Event-Delegation auf dem Listen-Container (statt Listener je
  Zeile) — das Kontextmenü öffnet zuverlässig; bei Fehlern erscheint eine
  sichtbare Meldung statt stillem Abbruch.

## [1.2.13] — 2026-08

### Fixed

- Rechtsklick-Menü: Namenskollision zwischen Daten-Eigenschaft `open` und
  Methode `open()` in der Menü-Komponente behoben (Methode heißt jetzt
  `show`) — der Rechtsklick öffnet das Kontextmenü nun zuverlässig.

## [1.2.12] — 2026-08

### Fixed

- Rechtsklick-Kontextmenü vollständig entkoppelt: Das Menü lebt jetzt in
  einer eigenen Komponente mit internem Zustand — das Öffnen rendert die
  Mail-Liste nicht mehr neu und kann sie nicht mehr ausblenden. Zusätzlich
  werden Folge-Klicks direkt nach dem Rechtsklick (Trackpad/ctrl+click)
  kurz unterdrückt.

## [1.2.11] — 2026-08

### Fixed

- Rechtsklick-Kontextmenü: Der `contextmenu`-Listener sitzt jetzt auf einem
  eigenen Wrapper-Element pro Zeile statt als Fallthrough auf der
  Listen-Komponente — das Menü öffnet zuverlässig und beeinflusst die
  Listen-Darstellung nicht mehr.

## [1.2.10] — 2026-08

### Added

- Rechtsklick-Kontextmenü in der Mail-Liste: gelesen/ungelesen markieren,
  als Spam markieren (mit Absender-Blockierung), in Ordner verschieben und
  löschen — direkt an der Zeile, mit Anpassung an den Bildschirmrand.

## [1.2.9] — 2026-08

### Added

- Spam-Aktion mit mehreren Postfächern: Ein Dialog fragt, in welchem
  Postfach (eigene oder geteilte Identität) der Absender blockiert werden
  soll; die Nachricht wird weiterhin in den Spam-Ordner des aktuellen
  Postfachs verschoben. Bei nur einer Identität entfällt der Dialog.

## [1.2.8] — 2026-08

### Added

- App-Passwörter zeigen jetzt die letzte Nutzung an ("Zuletzt benutzt"):
  neuester Zeitpunkt aus Stalwart (IMAP/SMTP/Sieve) und — bei kombinierten
  Passwörtern — Nextcloud (DAV), relativ formatiert mit Datums-Fallback.

## [1.2.7] — 2026-08

### Added

- Button "Spam": verschiebt Mails in den Spam-Ordner (JMAP Junk) und setzt
  den Absender zugleich über souvera_shield auf die PMG-Blacklist aller
  Identitäten — einzeln (Detailansicht) und für Mehrfachauswahl (Toolbar).

## [1.2.6] — 2026-08

### Fixed

- Postfach-Anzeige aktualisiert sich jetzt zuverlässig selbst: Der
  Auto-Refresh lädt nicht mehr nur die Liste des aktuellen Ordners neu,
  sondern refresht auch Sidebar-Badges und unread-Zähler — auch wenn sich
  andere Postfächer ändern (neue Mail in anderem Ordner, am Handy gelesene
  Mails).
- Ungelesen-Zähler im Browser-Titel (`(n) Souvera Mail`) wird jetzt
  kontinuierlich aktualisiert und beim Öffnen der App sofort gesetzt.
- "Auto-Refresh: Off" in den Einstellungen wird wieder respektiert.
- Beim Zurückkehren in den Tab werden Counts und Titel sofort aufgefrischt.

## [1.2.5] — 2026-08

### Fixed

- Mail-Push-Vorschau: Stalwart liefert bodyValues nur, wenn "bodyValues" in
  den Email/get-properties steht — Webhook und Poller fordern die
  Text-Vorschau jetzt korrekt an (fetchTextBodyValues + maxBodyValueBytes)
  und extrahieren sie robust aus bodyValues (live verifiziert).

## [1.2.4] — 2026-08

### Changed

- Mail-Push enthaelt jetzt auch die Text-Vorschau (preview) — Webhook und
  MailPushPoller reichern den Payload per JMAP mit Betreff, Absender und
  Vorschau an (graceful Fallback, wenn OIDC/JMAP gerade nicht erreichbar).

## [1.2.3] — 2026-08

### Changed

- Mail-Push enthaelt jetzt Deep-Link-Daten (emailId aus Stalwarts
  documentId via base32, mailboxPath=INBOX) — die App oeffnet beim
  Antippen der Notification direkt die betreffende Mail.
- MailPushPoller reichert den Push zusaetzlich mit Betreff + Absender
  der neuesten Inbox-Mail an (generischer Text bleibt als Fallback).

## [1.2.2] — 2026-08

### Added

- Out-of-office replies synced with the Nextcloud availability feature:
  adopt the absence configured at /settings/user/availability as the Sieve
  auto-responder (short text → subject, long text → body, replacement line,
  date window), edit it in Mail settings via the official dav OCS API
  (two-way sync), hourly background job turns the responder off when the
  absence ends.

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
