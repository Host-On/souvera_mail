# Changelog

## [1.2.29] — 2026-08

### Fixed

- Abwesenheitsnotizen feuern jetzt zuverlässig: Der Vacation-Block steht im
  Sieve-Skript vor den Nutzerregeln (fileinto/stop-Regeln übersprangen den
  am Ende stehenden Responder zuvor) und Zeilenumbrüche in Betreff/Text
  werden kollabiert — mehrzeilige Texte machten das Skript zuvor ungültig.

## [1.2.28] — 2026-08

### Fixed

- Anhänge funktionieren wieder: Der Blob-Upload im Web-Composer (Cloud-
  Dateien und Geräte-Dateien) lief über einen JMAP-Blob/upload-Methodenaufruf,
  den Stalwart nicht akzeptiert. Jetzt läuft der Upload über die bewährte
  path-style Upload-URL (RFC 8620 §6.1) — dieselbe Strecke wie Android und
  die Sieve-Verwaltung. Auch die Kalendereinladungs-Antwort nutzt diesen Weg.

## [1.2.27] — 2026-08

### Added

- Termineinladungen: Kalender-Anhänge werden erkannt; neue API
  `GET /api/v2/calendar-invite/parse` und `POST /api/v2/calendar-invite/respond`
  (zusage/vielleicht/ablehnen). Die Antwort legt das Ereignis per UID im
  CalDAV-Kalender des Nutzers an und sendet eine iTIP-Antwort (METHOD:REPLY)
  an den Organisator. Web-Oberfläche zeigt ein Einladungs-Banner mit
  Annehmen/Vielleicht/Ablehnen.

## [1.2.26] — 2026-08

### Added

- Neuer Push-Modus `nc`: Mail-Eingangsmeldungen laufen über die
  Nextcloud-Notifications-API (notify_push-Proxy, end-to-end-verschluesselt)
  statt direkt über FCM/APNs. Umschaltung per System-Config
  `souvera_mail.push_mode` (`direct` = bisheriges Verhalten, Default;
  `nc` = Benachrichtigungspfad). Deep-Link ueber object_id = JMAP-Email-ID.

## [1.2.25] — 2026-08

### Added

- `souvera_mail:push:test` routet jetzt nach Plattform (FCM/APNs) und meldet
  die Anzahl je Backend — damit lässt sich die iOS-Zustellung direkt testen.

## [1.2.24] — 2026-08

### Added

- Push für iOS: Neuer APNs-Sender (ApnsClient, HTTP/2 via curl, JWT-ES256)
  mit Konfiguration über `souvera_mail.apns_config_json`. Webhook und
  Mail-Poller routen Geräte jetzt nach Plattform: Android -> FCM, iOS -> APNs.
- Device-Endpoint akzeptiert zusätzlich das Feld `apnsToken` für iOS-Clients.

## [1.2.23] — 2026-08

### Fixed

- Zeilenumbrüche bei Plaintext-Nachrichten: Mails, deren HTML-Teil keine
  Umbrüche enthält (kein <br>/Block-Tag), werden wieder als Plaintext mit
  erhaltenen Absätzen angezeigt.

## [1.2.22] — 2026-08

### Fixed

- App-Passwort-Anhäufung behoben: Der Login-Flow widerruft jetzt vor dem
  Anlegen alle bestehenden Einträge mit derselben Beschreibung (z. B.
  "Souvera Android") — pro Geräteklasse bleibt genau ein Eintrag übrig,
  statt bei jeder Neu-Anmeldung weitere Passwörter zu sammeln.

## [1.2.21] — 2026-08

### Added

- Rechtsklick-Menü respektiert die Mehrfachauswahl: Bei mehreren markierten
  Mails wirken alle Aktionen (Gelesen/Ungelesen, Spam, Verschieben, Löschen)
  auf die gesamte Auswahl; ein Zähler im Menü zeigt die Anzahl an. Rechtsklick
  auf eine nicht markierte Zeile wirkt weiterhin nur auf diese eine Mail.

## [1.2.20] — 2026-08

### Fixed

- Ordner-Auswahl beim Verschieben als eigenes, zuverlässig gerendertes
  Modal (statt NcDialog) mit Ordnerliste, Icon und Abbruch-Button; leere
  Liste zeigt einen Hinweis statt eines leeren Dialogs.
- Rechtsklick-Menü optisch überarbeitet: Linien-Icons je Eintrag, saubere
  Abstände, Trennlinie vor "Löschen", weichere Schatten und Hover-Übergänge.

## [1.2.19] — 2026-08

### Changed

- Rechtsklick-Menü aufgeräumt: schlichtere Optik ohne Betreff-Kopf und
  ohne Scrollbar — vier kompakte Einträge (Gelesen/Ungelesen, Spam,
  Verschieben, Löschen). "Verschieben" öffnet einen Ordner-Auswahl-Dialog
  statt einer langen Inline-Liste.

## [1.2.18] — 2026-08

### Fixed

- Kontextmenü vollständig auf imperatives DOM umgestellt: Die Liste baut
  das Menü direkt an `document.body` (keine Kind-Komponente, kein Teleport,
  keine Refs, kein Event-Bus mehr) — damit entfällt die zuletzt beobachtete
  still schweigende Kette (nicht gemountete Komponente verwarf das Öffnen).

## [1.2.17] — 2026-08

### Fixed

- Kontextmenü öffnet jetzt über den Fenster-Event-Bus statt über eine
  $refs-Verbindung (der Ref war zur Laufzeit nicht auflösbar). Die
  Mail-Zuordnung vergleicht IDs als Strings und zeigt bei Fehlschlag die
  tatsächlichen Listen-IDs zur Diagnose an.

## [1.2.16] — 2026-08

### Fixed

- Rechtsklick-Diagnose: Jeder Ausgang des Handlers zeigt jetzt eine
  sichtbare, eindeutige Meldung ([a]-[e]); Zeilen werden über das
  data-email-id-Attribut statt über eine CSS-Klasse gefunden, und der
  Document-Fallback lauscht zusätzlich in der Bubble-Phase.

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
