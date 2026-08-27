# Changelog

## [1.2.46] — 2026-08

### Added

- Abwesenheits-Auto-Antwort mit **echten Zeilenumbrüchen**: Der
  Nachrichtentext wird jetzt als RFC 5228 §8.1 `text:`-String ins
  Sieve-Skript geschrieben (dot-stuffed, CRLF) statt als
  Whitespace-kollabierter Einzeiler.
- **Dynamischer Betreff**: Die Antwort nutzt `RE: <Betreff des Absenders>`
  (RFC 5229 `variables`, `header :matches "subject"`), mit Fallback auf
  den festen Abwesenheits-Betreff, wenn die Mail keinen Subject-Header hat.

## [1.2.41] — 2026-08

### Fixed

- ROBUSTER ABWESENHEITS-ABRUF: Der kombinierte Status wird jetzt zusätzlich
  über die seit jeher vorhandene Route `GET /vacation` ausgeliefert
  (`state`-Feld). Die Einstellungs-Karte fällt bei einem 404 auf
  `/vacation/state` automatisch auf diese Route zurück — der Status
  funktioniert damit auch auf Instanzen, deren Routen-Cache die neueren
  Vacation-Routen noch nicht registriert hat.

## [1.2.40] — 2026-08

### Fixed

- URLAUBS-ROOT-CAUSE: Die Routen `vacation/state`, `vacation/sync` und
  `vacation/form` waren in `appinfo/routes.php` verschachtelt deklariert
  (Kommentar direkt vor einem inneren `[`) und wurden deshalb von Nextcloud
  nie registriert — der Status-Abruf lief die ganze Zeit in einen 404.
  Routen-Definition repariert; die Einstellungs-Karte funktioniert damit
  vollständig (Status, Sync, Diagnose).

## [1.2.38] — 2026-08

### Fixed

- Abwesenheits-Status: IDBConnection wird nicht mehr über den Konstruktor
  aufgelöst (verhindert Instanziierungs-Fehler des Service) und die
  Fehlermeldung in der Oberfläche enthält jetzt HTTP-Status + Antworttext.

## [1.2.37] — 2026-08

### Fixed

- Abwesenheits-Status ist jetzt ausfallsicher: Der Status-Endpoint kann nicht
  mehr scheitern — Fehler werden geloggt und erscheinen als sichtbarer
  Diagnose-Block ("stateError") in der Einstellungs-Karte, statt still in den
  "Keine Abwesenheit"-Standard zu fallen.

## [1.2.36] — 2026-08

### Added

- Diagnose für die Abwesenheitsnotiz: Die Einstellungs-Karte zeigt aufklappbar,
  was der Server tatsächlich an Abwesenheitsdaten sieht (NC-Version, APIs,
  Tabellen, Rohdaten) und meldet Fehler beim Status-Laden sichtbar.

## [1.2.35] — 2026-08

### Fixed

- Abwesenheitsnotiz erkennt jetzt auch den NÄCHSTEN geplanten
  Abwesenheitszeitraum ("Richte deinen nächsten Abwesenheitszeitraum ein"):
  Der Responder wird mit dem passenden Datumsfenster hinterlegt und
  antwortet automatisch, sobald der Zeitraum beginnt. Zuvor wurde nur der
  aktuell laufende Zeitraum berücksichtigt — geplante Abwesenheiten galten
  als "keine Abwesenheit".

## [1.2.34] — 2026-08

### Fixed

- Abwesenheitsnotiz liest die NC-Abwesenheit jetzt über ALLE Versionen:
  NC 31+ (IAbsenceManager), NC 28-30 (Availability-Koordinator) und als
  versionsunabhängiger Fallback direkt aus der Datenbank (oc_dav_absence).
  Auf NC 33+ existiert die alte API nicht mehr — deshalb wurde die gepflegte
  Abwesenheit zuvor nie erkannt.

## [1.2.33] — 2026-08

### Fixed

- Abwesenheitserkennung korrigiert: Nextcloud liefert die Verfügbarkeitsstufe
  in Kleinbuchstaben ("absent") — der Vergleich auf "ABSENT" schlug daher
  immer fehl und die persönliche Verfügbarkeit wurde nie erkannt. Jetzt
  case-insensitive; Statusmeldung präzisiert.

## [1.2.32] — 2026-08

### Fixed

- Abwesenheitsnotiz erkennt jetzt auch die klassische "persönliche
  Verfügbarkeit": Liegt ein aktiver "Abwesend"-Zeitraum im Zeitplan vor,
  wird er als Auto-Antwort übernommen (mit dem hinterlegten Text bzw. einer
  Standardformulierung) — nicht nur das neue Out-of-Office-Feature. Status
  in den Einstellungen zeigt den Verfügbarkeits-Fallback ebenfalls an.

## [1.2.31] — 2026-08

### Changed

- Abwesenheitsnotiz auf EIN Konzept zurückgeführt: "Aus Nextcloud übernehmen"
  ist die einzige Oberfläche (der zusätzliche Mail-Editor aus 1.2.30 wurde
  entfernt). Bei deaktiviertem Feature zeigt die Oberfläche den konkreten
  Admin-Hinweis (`occ config:app:set --value=no dav hide_absence_settings`)
  und der Status zeigt an, ob die Sieve-Auto-Antwort wirklich aktiv ist.
- Server: Schreiber-Tracking entfernt — NC ist die alleinige Quelle für den
  Responder (keine NC-Abwesenheit -> Antwort aus).

## [1.2.30] — 2026-08

### Fixed

- Abwesenheitsnotiz grundlegend repariert: Eigener, immer verfügbarer Editor
  in den Einstellungen (aktiv, Betreff, Text, von/bis) schreibt direkt das
  Sieve-Skript — unabhängig von der Nextcloud-Verfügbarkeitsfunktion, die auf
  Instanzen standardmäßig deaktiviert ist und zuvor alle Antworten
  stummschaltete. Der Stunden-Job schaltet mail-eigene Notizen nicht mehr ab
  (Schreiber-Tracking mail/nc), der NC-Sync bleibt als Option erhalten.

### Ops

- Stalwart: Vacation-Antworten enden nach dem Standard-Limit
  (`SieveUserInterpreter.maxOutMessages`, Default 3). Für unbegrenzte
  Antworten den Wert auf 0 setzen, z. B.:
  `stalwart-cli -u https://<host> set sieve.interpreter.user.maxOutMessages 0`
  (falls die Instanz 0 ablehnt: einen hohen Wert wie 10000 verwenden).

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
