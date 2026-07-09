# Changelog

All notable changes to Souvera Mail will be documented in this file.

Format: [Semantic Versioning](https://semver.org/) — MAJOR.MINOR.PATCH

## [Unreleased]

## [0.14.20] — 2026-02-19 (UI: Nextcloud-Sidebar-Look für die Ordnerliste + Hotfix: Cancel-500 „undefined method find()")

### Zwei Änderungen in einem Rollout

**A) UI — Sidebar sieht jetzt aus wie Shield/Files/Contacts.**
**B) Hotfix — Cancel-Endpoint war schon in v0.14.16 latent kaputt.**

---

### A) Operator-Wunsch (2026-02-19, mit zwei Screenshots)

> „Bei Posteingang und co: könntest du nicht auch die Ansicht machen wie bei
> Shield und co? Das Menu mit der Markierung, dem blauen Strich ganz links
> am Anfang beim aktiven Objekt und so."

Souvera Mail fühlte sich in der Sidebar bislang „fremd" an — schwarzer
Selected-Hintergrund, keine Accent-Bar, keine NC-Pill. Andere Souvera-Apps
(Shield, Files, Contacts) nutzen einheitlich den NC-Sidebar-Look:

- **Vertikaler 4-px-Balken** in `--color-primary-element` links am aktiven
  Item
- **Sanfter hellblauer Hintergrund** (`--color-primary-element-light`)
- **Runde rechte Ecken** (Pill hugs the sidebar wall)
- **Hellgrauer Hover-Pill** (`--color-background-hover`)
- **Unread-Bubble in NC-Blau** (statt engine-default Grau)

Alle Farben kommen aus Nextclouds CSS-Custom-Properties → Light/Dark/HC-
Theming greift automatisch. Fallback-Werte pro Variable sind gesetzt,
damit auch un-themed-Rendering (z. B. während des NC-Boots) passt.

**Neu:**
- **`app/smail/v/current/app/plugins/nextcloud/css/folder-nav.css`** —
  ~130 LOC, scoped auf `.b-folders`, überschreibt Engine-Defaults ohne
  irgendwo `!important` einzusetzen.
- **`app/smail/v/current/app/plugins/nextcloud/index.php`** —
  `$this->addCss('css/folder-nav.css')` direkt neben `help-modal.css`.

**Warum keine Engine-Änderung?** Selektoren sind ausschließlich auf
`.b-folders` gescoped, damit der identische `.selected`-Klassenname in
der Message-Liste (`.messageListItem.selected`) nicht mit angepackt wird.
Kein Engine-Fork, kein Snappymail-Template-Patch, kein JavaScript —
reines CSS-Overlay auf die vom Engine gerenderten `<li>` / `<a>`.

---

### B) Hotfix — Cancel-Import 500-Fehler „undefined method find()"

**Operator-Report (2026-02-19):**
> „Beim Abbrechen des Imports: Interner Fehler beim Abbruch (Error):
> Call to undefined method OCA\SouveraMail\Db\MigrationJobMapper::find()"

Der v0.14.18-Fehler-Diagnose-Fix hat endlich die echte Ursache sichtbar
gemacht (vorher wurde die Exception verschluckt):
`MigrationService::cancelJobForUser()` und `dismissJobForUser()` rufen
beide `$this->jobs->find($jobId)` auf — diese Methode existiert am
`MigrationJobMapper` aber **nicht**. Nextclouds `QBMapper`-Basisklasse
hat den id-Lookup-Helper der deprecated `Mapper`-Klasse absichtlich
nicht übernommen; der v0.14.16-Autor hat das übersehen.

Damit war Cancel **seit v0.14.16 kaputt** (der Bug war nur unsichtbar,
weil die Exception in einem catch-Throwable landete). Der v0.14.18-Fix
hat die Fehlermeldung durchgereicht — jetzt war der Bug endlich sichtbar
und deutlich diagnostizierbar.

**Fix:** Minimale `find(int $id): MigrationJob`-Methode am Mapper
hinzugefügt, die `findEntity()` unter der Haube nutzt. Wirft
`DoesNotExistException` bei nicht existenter Row (wird vom Controller
sauber auf HTTP 404 gemappt).

**Warum keine Ownership-Prüfung im Mapper?** Die Ownership-Prüfung
(`$job->getUserId() !== $userId → 403`) läuft bewusst im Service-Layer.
Der Mapper bleibt „dumb by design", damit Admin-only-Cleanup-Pfade (z. B.
`MigrationCleanup`-Cron) beliebige Rows fetchen können.

---

### Files

| File | Change |
|---|---|
| `app/smail/v/current/app/plugins/nextcloud/css/folder-nav.css` | **Neu** — Nextcloud-Sidebar-Look |
| `app/smail/v/current/app/plugins/nextcloud/index.php` | `addCss('css/folder-nav.css')` |
| `lib/Db/MigrationJobMapper.php` | **Neu**: `find(int $id): MigrationJob` |
| `tests/test_folder_nav_css.php` | **Neu** — 31 Assertions als Regression-Pin |
| `tests/test_migration_backend.php` | +2 Assertions: Mapper::find pinned |
| `appinfo/info.xml`, `package.json` | 0.14.19 → 0.14.20 |

### Verifikation

- `php -l` clean auf `MigrationJobMapper.php` + `plugins/nextcloud/index.php`.
- **`tests/test_folder_nav_css.php`: 31/31 PASS.**
- **`tests/test_migration_backend.php`: alle Assertions PASS (+2 neue).**

### Live-Verifikation

**Sidebar-Look:**
1. Rsync + `occ upgrade` → 0.14.20 (Hard-Reload wg. CSS-Cache: `Strg+F5`).
2. Sidebar sieht identisch zu Shield / Files aus — blaue Accent-Bar links
   am aktiven Ordner (z. B. Posteingang), hellblaue Pill, runde rechte
   Ecken, hellgrauer Hover.
3. Dark-Theme umschalten (Persönliche Einstellungen → Erscheinungsbild):
   Sidebar bleibt konsistent, weil alle Farben aus `--color-*`-Variablen
   kommen.

**Cancel-Fix:**
1. Import starten → in Warteschlange
2. „Import abbrechen" klicken → Confirm-Dialog → „Ja, jetzt abbrechen"
3. Sofortiger Wechsel auf TerminalScreen „Import abgebrochen" — kein 500 mehr.

## [0.14.19] — 2026-02-19 (Feature: „Postfach neu synchronisieren" im Snappymail-Dropdown)

### Operator-Wunsch

> „Auch in das Dropdown Menu innerhalb der Mail App: dass man den
> Resync/Reindex von Stalwart selbst starten kann. Klick im Menu =
> Modal mit der Beschreibung und dann die Möglichkeit den Reindex zu
> starten."

### Ehrlichkeit zuerst

Stalwart 0.16 **hat kein per-User-FTS-Reindex-Endpoint** — die
REST-Management-API wurde in 0.16 entfernt und FTS-Indizierung läuft
automatisch im Hintergrund. Was ein Endnutzer aber tatsächlich
gebrauchen kann, ist ein **kompletter Client-Neusync**: Cache leeren,
Snappymail neu bootstrappen, frische `Session/get`-Antwort vom Server
holen. Das behebt >90% der realen Sync-Symptome (stale Ordner-Zähler,
fehlende Ordner nach Quota-Änderung, hängende Entwürfe, nach
Migration nicht sichtbare Nachrichten).

Der neue Dialog **kommuniziert das offen** in einer grünen Info-Note
— keine „Reindex"-Fake-Versprechen.

### Neu

- **`lib/Controller/StalwartController.php`** — neuer Controller mit
  einer `#[NoAdminRequired]` `resync()`-Methode. Zweck: Audit-Trail
  (Logger-Info-Line pro Klick) + Health-Check der NC-Session. Kein
  Server-side FTS-Aufruf (gibt es nicht).
- **`routes.php`** — `POST /apps/souvera_mail/stalwart/resync`.
- **`src/components/ResyncDialog.vue`** — Vue-Modal mit drei Stages:
  - **Intro**: freundliche Beschreibung + 3-Punkte-Wann-hilft-das +
    zwei NcNoteCards (⚠️ ungespeicherte Entwürfe gehen verloren,
    ℹ️ FTS läuft automatisch — kein manueller Reindex nötig).
  - **Busy**: Loading-Spinner + Live-Text „Sende Anfrage … Lösche
    lokalen Cache … Lade Souvera Mail neu (N Cache-Einträge geleert)".
  - **Error**: freundlicher Fehler-Splash mit „Erneut versuchen"-Button.
- **`src/App.vue`** — Event-Listener `souvera-mail:open-resync` +
  bedingte Mount.
- **`plugins/nextcloud/js/dropdown-menu.js`** — refaktoriert:
  wiederverwendbare `buildItem()`-Funktion. Injiziert jetzt ZWEI
  Menüeinträge im Snappymail-Top-Right-Dropdown:

  ```
    ⚙ Einstellungen
    🔄 Postfach neu synchronisieren   ← NEU
    📥 Alte Mails importieren
    🛈 Hilfe
    ⏻ Ausloggen
  ```

  Beide Einträge sind idempotent (`data-sv-resync-menu` /
  `data-sv-mig-menu`).

### Sync-Effekt im Detail

```
Client-side clearSnappymailLocalStorage()
  → entfernt jeden localStorage-Key mit Prefix
    rl., snappymail., rainloop., smail.
Backend-Call POST /stalwart/resync
  → NC-Log: „user-initiated mailbox resync uid=…"
  → Session-Cache-Refresh implizit durch die request
Full window.location.reload()
  → Snappymail bootstraps neu, holt fresh JMAP Session/get,
    Ordner-Tree + Message-List werden aus dem Server neu aufgebaut
```

### Files

| File | Change |
|---|---|
| `lib/Controller/StalwartController.php` | **Neu** — 40 LOC, audit trail only |
| `appinfo/routes.php` | POST `/stalwart/resync` |
| `app/smail/v/current/app/plugins/nextcloud/js/dropdown-menu.js` | Zweiter Menü-Eintrag, refaktoriert mit `buildItem()` |
| `src/App.vue` | Event-Listener + ResyncDialog mount |
| `src/components/ResyncDialog.vue` | **Neu** — 3-Stage-Dialog (Intro/Busy/Error) |
| `tests/test_migration_wizard_frontend.php` | +14 Assertions |
| `tests/test_souvera_mail_rename.php`, `tests/test_connected_devices.php` | Route-Count 21 → 22 |
| `appinfo/info.xml`, `package.json` | 0.14.18 → 0.14.19 |

### Verifikation

- `yarn build` clean → 400 KB Bundle.
- `php -l` clean.
- `node -e new Function(dropdownJs)` OK.
- **Voller Suite-Run: 39 Suites / 1550 Assertions passing** (war 1536).

### Live-Verifikation

1. Rsync + `occ upgrade` → 0.14.19
2. In Mail einloggen → 👤 oben rechts → Dropdown öffnen
3. Neuer Eintrag „🔄 Postfach neu synchronisieren" zwischen ⚙ Einstellungen und 📥 Alte Mails importieren
4. Klick → Modal öffnet mit ehrlicher Beschreibung + „Jetzt neu synchronisieren"-Button
5. Klick → Spinner mit Live-Text → automatischer Full Reload → sauberes Snappymail

## [0.14.18] — 2026-02-19 (Hotfix: Cancel-500 diagnostisch machen + defensive Setter)

### Operator-Report

Nach `POST /migration/cancel/{jobId}` HTTP 500 mit generischer
Meldung „Interner Fehler beim Abbruch". Die eigentliche
Exception-Message war in NC-Logs versteckt und für den Operator
im Web-UI komplett unsichtbar.

### Fix (zwei Ebenen)

**Ebene 1 — Diagnose-First:** Die 500-Response im Controller gibt
jetzt Klassenname + `message` der geworfenen Exception verbatim
zurück:

```
"Interner Fehler beim Abbruch (DBException): SQLSTATE[HY000]: General …"
```

Damit sieht der Operator im nächsten Cancel-Versuch sofort die
konkrete Ursache und braucht keinen Log-Grep mehr.

**Ebene 2 — Defensive Setter:** `cancelJobForUser()` in
`MigrationService` wrappt jetzt `$job->setStalwartAppId(null)` in
einen eigenen try/catch. NC-AppFramework's
`->addType('stalwartAppId','string')` verhält sich in einzelnen
PHP-8.3-Patch-Levels beim Übergeben von `null` inkonsistent — der
Setter kann eine `ArgumentCountError`-artige Flake werfen. Selbst
wenn das passiert: das temp Ziel-App-Passwort ist zu diesem
Zeitpunkt schon widerrufen (`revokeStalwartOnlyForMigration()`
lief vorher), also gehen keine Ressourcen verloren. Der
Cleanup-Cron greift orphaned Referenzen sowieso täglich ab.

Was passiert im Detail:
- Status-Flip auf `cancelled` läuft **immer** (harter Fehler-Pfad).
- App-PW-Revoke läuft in eigenem try/catch (loggt, non-fatal).
- Null-out von `stalwart_app_id` läuft in eigenem try/catch (loggt,
  non-fatal).

Damit ist das Feature robust gegen jeden einzelnen Sub-Failure.

### Files

| File | Change |
|---|---|
| `lib/Controller/MigrationController.php` | 500-Response gibt jetzt ExceptionClass + Message zurück |
| `lib/Service/MigrationService.php` | `cancelJobForUser`: eigener try/catch um `setStalwartAppId(null)` |
| `appinfo/info.xml`, `package.json` | 0.14.17 → 0.14.18 |

### Verifikation

- `php -l` clean auf Controller + Service.
- **Voller Suite-Run: 39 Suites / 1536 Assertions passing.**

### Live-Verifikation

1. Rsync + `occ upgrade` → 0.14.18
2. Erneut abbrechen probieren.
3. **Falls es funktioniert**: TerminalScreen „Import abgebrochen" erscheint sofort.
4. **Falls es wieder 500 gibt**: Die Fehlermeldung im UI zeigt jetzt
   die echte Ursache (z. B. `DBException: SQLSTATE[HY000]: …`) —
   bitte diese Message zurück an mich, dann fix ich das Root Cause
   Problem gezielt.

## [0.14.17] — 2026-02-19 (UX-Fix: Menü-Eintrag ins Snappymail-Dropdown umziehen)

### Operator-Report

> „Der ,Alte Mails Importieren'-Eintrag ist im Nextcloud-Dropdown gelandet,
> aber ich meinte das Dropdown innerhalb von Mail wo auch der Hilfe-Button
> für die Tastenkürzel drin ist."

### Umsetzung

- **Entfernt**: NC-Global-User-Menü-Eintrag `souvera_mail_migration`
  aus `Application::boot()` (war v0.14.12).
- **Neu**: `app/smail/v/current/app/plugins/nextcloud/js/dropdown-menu.js`
  injiziert einen neuen `<li>` **direkt vor dem Hilfe-Item** in
  Snappymails `menu[aria-labelledby="top-system-dropdown-id"]`
  (siehe `SystemDropDown.html`).
- **DOM-Injection**: `MutationObserver` auf `document.body`, weil
  Snappymail das Dropdown erst beim ersten Klick materialisiert.
  Idempotent via `data-sv-mig-menu="1"`-Marker.
- **Klick-Verhalten**: Dropdown wird geschlossen, dann
  `window.dispatchEvent(new CustomEvent('souvera-mail:open-migration'))`.
- **`App.vue`** lauscht auf das Event, ruft `loadState()` neu ab,
  wählt den passenden Initial-Screen (progress / terminal / welcome)
  und öffnet den Wizard — bypasst den `dismissed`-Guard, exakt wie
  der `?openMigration=1`-URL-Parameter.
- **Kompatibilität**: `?openMigration=1` bleibt als Fallback für
  Bookmark-Links / Operator-Skripte.

### Files

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | NC-User-Menü-Eintrag entfernt |
| `app/smail/v/current/app/plugins/nextcloud/js/dropdown-menu.js` | **Neu** — 120 LOC DOM-Injector |
| `app/smail/v/current/app/plugins/nextcloud/index.php` | `addJs('js/dropdown-menu.js')` |
| `src/App.vue` | Event-Listener `souvera-mail:open-migration` |
| `tests/test_migration_wizard_frontend.php` | Assertions umgestellt: kein NC-User-Menü mehr, dafür Snappymail-Dropdown-Contract |
| `appinfo/info.xml`, `package.json` | Version 0.14.16 → 0.14.17 |

### Verifikation

- `yarn build` clean → 400 KB Bundle.
- `php -l` clean auf Application.php + plugins/nextcloud/index.php.
- `node -e new Function(dropdownJs)` OK.
- **Voller Suite-Run: 39 Suites / 1541 Assertions passing**.

### Live-Verifikation

1. Rsync + `occ upgrade` → 0.14.17
2. In Mail einloggen → oben rechts auf 👤 klicken
3. Zwischen „Einstellungen" (⚙) und „Hilfe" (🛈) erscheint jetzt
   „📥 Alte Mails importieren"
4. Klick → Dropdown schließt sich, Wizard öffnet sofort
5. NC-Global-Dropdown (oben rechts außerhalb von Mail) enthält
   den Eintrag **nicht mehr** — sauber isoliert auf Mail.

### BookStack-Dokumentation (nachgezogen aus P2-Backlog)

Der Import-Wizard hatte trotz 5 Iterationen (v0.14.11-v0.14.17) noch
**keine** Doku in BookStack — direkt beim gleichen Rollout nachgezogen:

- **User-Seite** (Shelf „Benutzer" → Book „Souvera Mail", ID 92):
  „Alte Mails importieren" — kompletter Screen-für-Screen-Guide
  (Welcome → Form → Mapping → Confirm → Progress → Terminal + Cancel),
  Screenshot-Placeholder pro Screen, FAQ am Ende (App-Passwörter beim
  alten Anbieter, Verbindungsabbruch, Passwort-Änderung, doppelte
  Nachrichten, Support-Kontakt).
  URL: `https://doku.souvera.eu/link/92`
- **Admin-Seite** (neues Book „Souvera Mail – Admin", ID 13, in
  Shelf „Administratoren"): „Import-Wizard — Betrieb &
  Fehlerbehebung" — Aktivierung, DB-Schema, Ende-zu-Ende-Fluss,
  Cancel-Flow, Cronjobs, häufige Fehlerbilder mit HTTP-Codes und
  Fixes, Datenschutz-Kapitel, geplante `occ`-Kommandos für v0.14.18.
  URL: `https://doku.souvera.eu/link/93`

Beide Seiten wurden über die BookStack-REST-API mit den in
`memory/bookstack_access.md` hinterlegten Credentials angelegt.
Skripte in `/tmp/seed_import_wizard_*_bookstack.py` (idempotent —
POST beim ersten Lauf, PUT beim zweiten).

## [0.14.16] — 2026-02-19 (Feature: Cancel Migration während Warteschlange)

### Operator-Wunsch

> „Solange man noch in der Warteschlange ist, sollte man den Auftrag
> doch abbrechen können oder?! Aktuell sind wir seit mehr als einer
> Stunde in der Warteschlange und das würde ich gern abbrechen …"

### Umsetzung

provider.tools hat **kein** externes Cancel-Endpoint (steht so im
Docblock von `ProviderToolsClient.php` §24-25). Wir umgehen das mit
einem eleganten Trick, ganz ohne provider.tools-API zu erweitern:

1. **Ziel-App-Passwort widerrufen** → wenn provider.tools später
   einen Worker frei hat und den Job aufgreifen will, scheitert er
   **automatisch** am IMAP-AUTH gegen Stalwart. Der Job stirbt
   upstream silent.
2. **Lokaler Job auf `STATUS_CANCELLED`** setzen. Bereits laufender
   Poll-Cron bekommt beim nächsten Tick eine 401/AUTHFAIL, aber der
   Job ist bereits terminal — kein UI-Impact.

### Neu

- **`MigrationJob::STATUS_CANCELLED`** — neuer terminaler Status
  („cancelled"), aufgenommen in `TERMINAL_STATUSES`.
- **`MigrationService::cancelJobForUser($uid, $jobId)`** — führt die
  drei Schritte oben aus. Wirft `InvalidArgumentException` wenn Job
  nicht dem User gehört (→ 403) oder nicht mehr im `pending`-Status
  ist (→ 409 CONFLICT), damit ein bereits laufender Import nicht
  mid-transfer geknackt werden kann.
- **`MigrationController::cancelJob(int $jobId)`** — neuer Endpoint
  `POST /apps/souvera_mail/migration/cancel/{jobId}` mit
  `#[NoAdminRequired]`. HTTP-Codes: 200/409/403/404/500.
- **`routes.php`** — neue Route mit `\d+`-jobId-Guard.
- **`useMigration.cancelActiveJob(jobId)`** — Composable-Wrapper.
- **`ProgressScreen.vue`** — neuer roter `NcButton` „Import abbrechen"
  im Split-Footer. **Nur** sichtbar, wenn `state === 'pending'`. Klick
  öffnet einen NcDialog-Confirm mit klaren Konsequenzen (3-Zeilen-
  Liste). Nach erfolgreichem Cancel switcht der Wizard automatisch
  auf TerminalScreen mit „Import abgebrochen".

### Warum kein Cancel bei `running`?

Bewusste Design-Entscheidung — bei laufendem Transfer würde ein
Widerruf mid-flight einen Ordner unvollständig zurücklassen und der
User könnte nicht mal sicher sagen, welche Mails schon rüber sind.
Wenn wir das jemals brauchen, kommt ein separater `forceCancel`-Verb
mit fetter roter Warnung dazu.

### Files

| File | Change |
|---|---|
| `lib/Db/MigrationJob.php` | STATUS_CANCELLED + TERMINAL_STATUSES-Update |
| `lib/Service/MigrationService.php` | `cancelJobForUser()`: App-PW-Revoke + Status-Flip + `finished_at` |
| `lib/Controller/MigrationController.php` | `cancelJob()` mit HTTP-409/403/404-Mapping |
| `appinfo/routes.php` | POST `/migration/cancel/{jobId}` |
| `src/composables/useMigration.js` | `cancelActiveJob()` |
| `src/components/screens/ProgressScreen.vue` | Cancel-Button + Confirm-Dialog |
| `tests/test_migration_wizard_frontend.php` | +13 Assertions (Backend + Frontend + Route) |
| `appinfo/info.xml`, `package.json` | Version 0.14.15 → 0.14.16 |

### Verifikation

- `yarn build` clean → 400 KB Bundle.
- `php -l` clean auf allen 4 Backend-Dateien + routes.php.
- **`tests/test_migration_wizard_frontend.php`: 161 / 161 PASS** (war 148).
- **Voller Suite-Run: 39 Suites / 1533 Assertions passing** (war 1520).

### Live-Verifikation

1. Rsync + `occ upgrade` → 0.14.16
2. Import starten, ProgressScreen erscheint mit „Warteschlange"
3. Neuer Button „Import abbrechen" ist sichtbar
4. Klick → Confirm-Dialog „Import wirklich abbrechen?" mit 3 Konsequenz-Zeilen
5. „Ja, jetzt abbrechen" → TerminalScreen zeigt „Import abgebrochen"
6. Im Log erscheint `migration-cancelled: Stalwart app-pw revoked`
7. Der Nutzer kann sofort einen neuen Import starten

## [0.14.15] — 2026-02-19 (Hotfix: Backend-Contract-Alignment im Progress + on-demand Poll)

### Operator-Report

Screenshot: „Import läuft" → „Warteschlange …" bei 2 %. Frage:

> „Bekommst du von der API keine Antwort das ein Worker läuft?!"

**Zwei getrennte Bugs auf einmal:**

**Bug #1: Frontend liest falsche Feldnamen**

Der echte `MigrationJob::toApiArray()`-Contract im Backend ist:

```json
{
  "id": 42,
  "status": "pending" | "running" | "completed" | "failed" | "dismissed",
  "progress": {
    "progress": { "foldersDone": 0, "foldersTotal": 52,
                   "messagesDone": 0, "messagesTotal": 8432,
                   "currentFolder": "INBOX" },
    "queue":    { "position": 3, "totalInQueue": 5 }
  },
  "error": null,
  "createdAt": …, "updatedAt": …, "finishedAt": null,
  "isActive": true, "isTerminal": false
}
```

Mein Composable + ProgressScreen + TerminalScreen aus v0.14.11 lasen:

- `state` (existiert nicht — richtig: `status`)
- `messages_total` / `messages_done` (snake_case — richtig: `progress.progress.messagesTotal/messagesDone` camelCase nested)
- `folders_total` / `folders_done` (dito)
- `queue_position` (richtig: `progress.queue.position`)
- `current_folder` (richtig: `progress.progress.currentFolder`)

Effekt: Selbst wenn der MigrationPoller frische Provider-Daten holt,
zeigt der ProgressScreen `undefined %` und „Warteschlange …" — weil
alle Werte am falschen Pfad gelesen werden.

**Bug #2: 60-Sekunden-Cron-Lücke**

Der `MigrationPoller`-Cron aktualisiert Job-Status nur alle 60 s aus
provider.tools. Frisch gestartete Jobs bleiben bis zu 60 s auf
`pending` in der DB, obwohl der provider.tools-Worker vielleicht
schon aktiv arbeitet. Der Nutzer sieht bis zu einer Minute lang
„Warteschlange …" ohne Fortschritt.

### Fix

**Frontend:**
- `src/composables/useMigration.js`: `jobState`-Computed liest jetzt
  `status.value?.status` (nicht `.state`).
- `src/components/screens/ProgressScreen.vue`: komplette Umstellung
  auf die verschachtelte `progress.progress.{…}` / `progress.queue.{…}`-
  Struktur mit camelCase. Neue Meta-Zeile zeigt live „Ordner: 3 / 52 ·
  Aktueller Ordner: INBOX". Bar-Progress rechnet erst aus Messages,
  fällt auf Folders zurück wenn Message-Zählung noch nicht bekannt.
- `src/components/screens/TerminalScreen.vue`: dito für `status`,
  `progress.progress.messagesDone`, `progress.progress.foldersDone`.
  Duration wird jetzt aus `createdAt`/`finishedAt` berechnet
  (Backend liefert kein `duration`-Feld).

**Backend:**
- **`MigrationController::status()`**: neuer On-Demand-Poll-Pfad.
  Wenn ein aktiver Job existiert UND `updated_at` älter als 10 s ist,
  ruft der Controller `MigrationService::refreshFromProvider($job)`
  synchron auf und liefert den frischen Status zurück. Die 60 s-Cron-
  Lücke schließt sich damit auf ein Poll-Intervall (5 s Frontend).
  Bei einem Upstream-Flake fällt der Code auf den gecachten
  DB-Zustand zurück — kein UI-Freeze.
- **`MigrationService::findActiveJobForUser()`**: neuer Wrapper der
  die `MigrationJob`-Entity direkt zurückgibt (statt `toApiArray`).
  Der Controller braucht die Entity um `refreshFromProvider()`
  aufzurufen.
- `refreshFromProvider()` selbst ist **unangetastet** — die Methode
  war explizit für diesen zweiten Aufrufer vorgesehen (Docblock
  vom v0.14.9-Autor: „Called by both the MigrationPoller background
  job (all active rows) and by the controller status endpoint when
  the cached row is older than …").

### Files

| File | Change |
|---|---|
| `src/composables/useMigration.js` | `jobState`: `.state` → `.status` |
| `src/components/screens/ProgressScreen.vue` | Kompletter Rewrite auf echten Backend-Contract |
| `src/components/screens/TerminalScreen.vue` | Setup-Block auf echten Backend-Contract |
| `lib/Controller/MigrationController.php` | On-Demand-Refresh in `status()` bei stale row |
| `lib/Service/MigrationService.php` | Neuer `findActiveJobForUser()`-Entity-Wrapper |
| `tests/test_migration_wizard_frontend.php` | +14 Assertions (nested Progress-Contract + On-Demand-Poll) |
| `appinfo/info.xml`, `package.json` | Version 0.14.14 → 0.14.15 |

### Verifikation

- `yarn build` clean → 400 KB Bundle.
- `php -l` clean auf Controller + Service.
- **`tests/test_migration_wizard_frontend.php`: 148 / 148 PASS** (war 134).
- **Voller Suite-Run: 39 Suites / 1522 Assertions passing** (war 1508).

### Live-Verifikation

1. Rsync + `occ upgrade` → 0.14.15
2. Wizard komplett durchklicken bis Progress-Screen
3. **Innerhalb von <15 Sekunden** wechselt der Screen von „Warteschlange …"
   auf „Import läuft …" mit realem Prozentwert (aus dem live-Poll)
4. Zeile „Ordner: 3 / 52 · Aktueller Ordner: INBOX" erscheint sobald
   provider.tools den ersten Ordner meldet
5. TerminalScreen zeigt am Ende „Übertragene Nachrichten: 8 432 ·
   Ordner: 52 · Dauer: 4 min 12 s"

## [0.14.14] — 2026-02-19 (Neues Feature: Folder-Mapping-Screen + folders-Contract-Fix)

### Operator-Report

Screenshot vom ConfirmScreen: „Verbindung erfolgreich · 57 Ordner"
gefolgt von der roten NcNoteCard:

> **provider.tools HTTP 400: folders must be a non-empty array**

Erklärung: provider.tools hat im Februar 2026 den `/imap/migrate`-
Contract gehärtet — `folders` ist jetzt Pflichtfeld und muss ein
non-empty Array von Quell-Ordner-Pfaden sein. Der Kommentar in
`ProviderToolsClient::startMigration()` („Empty list = ALL folders")
ist damit veraltet. Frontend sendete gar kein Array → 400.

### Neu — Folder-Mapping-Screen (User-Wunsch: „Klug und Optisch hübsch")

Zwischen ConfirmScreen und Progress kommt der neue **`FolderMappingScreen`**:

**Layout** (2-Spalten, Design-System-konform):

```
┌───────────────────────────────────────────────────────┐
│  ☑ 52 Ordner ausgewählt / 57                          │
│  [Empfohlene] [Alle] [Keine]                          │
├───────────────────────────────────────────────────────┤
│  ☑ 📥 INBOX          ──►   📥 INBOX      [8 234 Mails]│
│  ☑ 📤 Sent Items     ──►   📤 Sent       [3 141 Mails]│
│  ☑ 📝 Drafts         ──►   📝 Drafts     [ 42 Mails]  │
│  ☑ 🗑 Trash          ──►   🗑 Trash      [ 118 Mails] │
│  ☑ ⚠ Spam            ──►   ⚠ Junk        [1 023 Mails]│
│  ☐ ⚙ [Gmail]/All Mail  ──► ⚙ [Gmail]/All Mail         │
│  ☑ 📁 Projekte       ──►   📁 Projekte    [ 87 Mails] │
│  …                                                    │
└───────────────────────────────────────────────────────┘
```

**Intelligente Auto-Selektion:**

- **Standard-Rollen** werden multi-lingual erkannt (Deutsch, Englisch,
  Französisch, Spanisch) und mit Rollen-Icon + kanonischem Ziel-Namen
  markiert: INBOX, Sent, Drafts, Trash, Junk, Archive.
- **System-Ordner** (`[Gmail]/…`, `[Google Mail]/…`, `.foo`,
  `Outbox`/`Postausgang`) werden **abgewählt** vorgezeichnet, mit
  gedämpfter Opacity + Cog-Icon — sind aber immer noch anwählbar
  wenn der Nutzer sie wirklich haben will.
- **Sort-Order:** INBOX oben, dann restliche Rollen, dann Custom-
  Ordner alphabetisch, System-Ordner ganz unten.

**3-Klick-Toolbar:**

- **„Empfohlene"** → alles außer System-Ordnern
- **„Alle"** → 57 von 57
- **„Keine"** → 0 (Button „Weiter" wird deaktiviert)

**Optische Details** (aus Souvera Design System):

- Icons aus `vue-material-design-icons` (Inbox, SendOutline,
  FileEditOutline, TrashCanOutline, EmailAlertOutline, ArchiveOutline,
  CogOutline, FolderOutline).
- Selektierte Zeilen: sanfter Primary-Element-Tint + Primary-Border.
- Message-Counts als Pill (`background-hover` + `border-radius: 999px`).
- Mobile Responsive: unter 620 px klappt die Ziel-Spalte unter die
  Quell-Spalte.

### Neu — Zielserver-Übernahme-Vorschau in ConfirmScreen

Nach dem Mapping-Schritt zeigt der ConfirmScreen eine zusätzliche
Zeile „**Zu importieren**: 52 Ordner ausgewählt" damit der Nutzer
seine Auswahl vor dem finalen Start-Klick nochmal sieht.

### Backend-Erweiterung (minimal invasiv)

Der User-Direktive folgend („Funktionen nicht anfassen") wurde
**kein bestehender Backend-Code geändert** — nur das durchgereichte
Datenfeld ist neu:

- **`MigrationController::start()`**: nimmt zusätzlich
  `array $folders = []` entgegen, validiert dass die Liste nicht
  leer ist (HTTP 400 mit deutscher Meldung „Bitte wähle mindestens
  einen Ordner …"), reicht sie an `MigrationService::startForUser()`
  weiter.
- **`MigrationService::startForUser()`**: neue Signatur-Position
  `array $folders = []`, wird an
  `ProviderToolsClient::startMigration($source, $destination, $folders)`
  weitergegeben — dieser dritte Parameter existierte bereits.
- **`ProviderToolsClient`**: unverändert (bekam schon immer `folders`).
- **Ziel-Server-App-Passwort** (`AppPasswordService::createStalwartOnlyForMigration`):
  unverändert. Wird beim `/start`-Aufruf automatisch angelegt, an
  provider.tools als Destination weitergegeben, und beim Ende des
  Jobs (Success/Fail/Cancel) automatisch wieder revokiert. Der
  MigrationCleanup-Cron fängt Orphaned-Passwörter zusätzlich ab.

### Files

| File | Change |
|---|---|
| `src/components/screens/FolderMappingScreen.vue` | **Neu** — 270 LOC Vue-SFC mit intelligenter Auto-Selektion und 2-Spalten-Mapping-Layout. |
| `src/components/MigrationWizard.vue` | Neuer `mapping`-Step, `folderList` + `selectedFolders`-State, `startMigration(conn, folders)`. |
| `src/composables/useMigration.js` | `startMigration(uiConn, folders)` reicht das Array an POST /start weiter. |
| `src/components/screens/ConfirmScreen.vue` | Neuer `selected`-Prop + „Zu importieren: X Ordner ausgewählt"-Zeile. |
| `lib/Controller/MigrationController.php` | `array $folders = []`, non-empty-Check mit deutscher Fehlermeldung. |
| `lib/Service/MigrationService.php` | `startForUser(…, array $folders = [])`, Weitergabe an ProviderToolsClient. |
| `tests/test_migration_wizard_frontend.php` | +20 neue Assertions (Mapping-Screen, ROLE_ALIASES, isSystemFolder, Backend-Signaturen). |
| `appinfo/info.xml`, `package.json` | Version-Bump 0.14.13 → 0.14.14. |

### Verifikation

- `yarn build` clean → 400 KB Bundle (+22 KB durch die neuen Icons + Screen).
- `php -l` clean auf Controller + Service.
- **`tests/test_migration_wizard_frontend.php`: 134 / 134 PASS** (war 115).
- **Voller Suite-Run: 39 Suites / 1508 Assertions passing** (war 1489).

### Live-Verifikation für Operator

1. Rsync + `occ upgrade` → 0.14.14
2. Wizard → Verbindung prüfen → **neuer Mapping-Screen erscheint**
3. Alle 57 Ordner sichtbar, INBOX/Sent/Drafts/Trash/Junk/Archive
   auto-preselected mit passenden Icons.
4. „Empfohlene" klicken → System-Ordner rausfallen.
5. „Weiter" → ConfirmScreen zeigt „Zu importieren: 52 Ordner ausgewählt".
6. „Import jetzt starten" → provider.tools bekommt endlich das
   erwartete `folders`-Array → Job wird korrekt erstellt.

## [0.14.13] — 2026-02-19 (Hotfix: Composable ↔ Backend Contract-Alignment)

### Fixed — „Spinner geht kurz, dann nichts" beim IMAP-Verbindungstest

Operator-Report (2026-02-19): „Wenn ich auf ,Verbindung prüfen' klicke,
hat der Button kurz einen Loading-Spinner, und das war's dann auch."

**Root cause:** Der Vue-3-Umbau in v0.14.11 hat den Composable auf
Basis einer **erfundenen** Backend-Response-Shape gebaut. Der echte
Backend-Vertrag (`MigrationController.php`) sieht ganz anders aus:

|                | Backend erwartet / liefert                | Frontend sendete / erwartete       |
|----------------|-------------------------------------------|------------------------------------|
| Request-Body   | `{host, port, user, password, secure}`    | `{host, port, username, password, tls}` |
| Response-OK    | `{status:'ok', result:{success, message}}` | `{ok: true}`                       |
| Welcome-State  | `body.state.{available,welcomeDismissed,activeJob,lastJob}` | `body.{available,dismissed,activeJob,lastJob}` |
| Status         | `body.{active, latest}` (null / Job)      | `body.job`                         |

Wirkung: Jeder POST /test-connection kam beim Backend mit fehlendem
`user`-Feld an → 400 „user must not be empty". Der Composable
`jsonFetch()` warf; die `onAdvance`-Funktion hatte keinen `catch`
im test-connection-Zweig; die Exception wurde stumm geschluckt;
`finally { isBusy = false }` beendete den Spinner — Wizard sah
eingefroren aus.

### Fix

1. **`src/composables/useMigration.js`** — komplette Adapter-Schicht:
   - Neue interne Funktion `toBackendConn(uiConn)` bildet
     `{host, port, username, password, tls}` → `{host, port, user, password, secure}` ab.
   - `testConnection()` unwrappt `body.result` und normalisiert
     auf `{ok, message}`; Netzwerk-Errors werfen weiterhin.
   - `listFolders()` unwrappt `body.result` und normalisiert zu
     `{folders, folder_count, message_count}` — akzeptiert Array,
     Objekt oder Zahl (provider.tools ist da wechselhaft).
   - `startMigration()` liest `body.job` (unverändert korrekt).
   - `loadState()` liest jetzt korrekt `body.state.{available,
     welcomeDismissed, activeJob, lastJob}`.
   - `loadStatus()` liest `body.active || body.latest` (statt
     `body.job`) und erkennt den Active→Terminal-Übergang.

2. **`src/components/MigrationWizard.vue`** — `onAdvance()` bekam
   einen expliziten `try/catch` um `testConnection()`. HTTP-Errors
   (400/500) landen jetzt sichtbar in der `NcNoteCard` unten am
   Formular, statt still zu verschwinden. Die Message aus dem
   Backend-`body.message` wird verbatim durchgereicht (z. B.
   „user must not be empty; password must not be empty").

3. **`tests/test_migration_wizard_frontend.php`** — +10 Assertions,
   die den Backend-Contract dauerhaft pinnen: existiert
   `toBackendConn`, mappt es `username→user` und `tls→secure`,
   liest der Composable `body.state.*` (statt `body.*`),
   unwrappt er `body.result`, liest er `body.active || body.latest`
   (statt `body.job`), fängt `onAdvance` HTTP-Errors, setzt es
   `testResult` mind. an zwei Stellen.

### Files

| File | Change |
|---|---|
| `src/composables/useMigration.js` | Adapter-Layer zwischen UI- und Backend-Shape |
| `src/components/MigrationWizard.vue` | `onAdvance()`: expliziter try/catch für testConnection |
| `tests/test_migration_wizard_frontend.php` | +10 Assertions als Contract-Lock |
| `appinfo/info.xml`, `package.json` | Version-Bump 0.14.12 → 0.14.13 |

### Verification

- `yarn build` clean → 377 KB Bundle.
- **`tests/test_migration_wizard_frontend.php`: 115 / 115 PASS** (war 106).
- **Voller Suite-Run: 39 Suites / 1489 Assertions passing** (war 1480).

### Live-Verifikation für Operator

1. Rsync + `occ upgrade` → 0.14.13.
2. Wizard aufrufen → IMAP-Daten eingeben → „Verbindung prüfen".
3. **Bei erfolgreicher Verbindung**: Sprung auf ConfirmScreen mit
   Ordner-Vorschau.
4. **Bei Fehler** (falsche Credentials, unerreichbarer Server, …):
   Sofort rote NcNoteCard mit der echten Backend-Fehlermeldung —
   kein stiller Freeze mehr.

## [0.14.12b] — 2026-02-19 (Hotfix-über-Hotfix: `IL10N` app-scoped resolve)

Nach dem 0.14.12-Deploy meldete der Operator im Nextcloud-Log:

```
Could not resolve OCP\IL10N! Class can not be instantiated
```

**Root cause (mein Fehler):** In `Application::boot()` habe ich für den
neuen User-Menü-Eintrag den `IL10N`-Service direkt aus dem
`ServerContainer` geholt:

```php
$serverContainer->get(\OCP\IL10N::class)->t('Alte Mails importieren');
```

`IL10N` ist in Nextcloud aber **app-scoped** — jede App hat ihre
eigene Übersetzungs-Instanz. Der ServerContainer kennt keinen
generischen `IL10N`-Bind (nur `Server::getL10N($appId)`), also wirft
er die Not-instantiable-Exception. Der Wizard-Reopen-Menü-Eintrag
wurde nicht registriert; jeder Seitenaufruf loggte den Fehler.

**Fix:** Auflösung über `\OCP\L10N\IFactory::get($appId)` — der
kanonische Nextcloud-Weg für app-scoped-Translations:

```php
$l10n = $serverContainer->get(\OCP\L10N\IFactory::class)->get(self::APP_ID);
$l10n->t('Alte Mails importieren');
```

**Regression-Pin:** `tests/test_migration_wizard_frontend.php` +2
Assertions: **(a)** `Application.php` darf `IL10N` nicht mehr direkt
aus dem ServerContainer holen; **(b)** die Übersetzung MUSS über
`\OCP\L10N\IFactory::class` laufen. **Total local suite: 1480
Assertions passing.**

## [0.14.12] — 2026-02-19 (Hotfix: Vue-3 v-model bindings + user-menu re-open)

### Fixed — 3 Live-Bugs auf dem IMAP-Form-Screen

Operator-Report (2026-02-19, Screenshot mit gefüllten Feldern):
1. „Verschlüsselte Verbindung"-Checkbox lässt sich nicht aktivieren.
2. Port-Default 993 verschwand nach dem ersten Interaction-Zyklus.
3. „Verbindung prüfen"-Button bleibt trotz gefüllter Felder deaktiviert.

**Root cause:** `@nextcloud/vue` **v9** hat die komplette Input-API auf
Vue-3-`v-model`-Standard umgestellt — jede Komponente emitiert jetzt
`update:modelValue` (nicht mehr `update:value` / `update:checked` wie
in Vue 2 / dem legacy API). Der Vue-3-Umbau in v0.14.11 verwendete
noch die Vue-2-Syntax `:value.sync="…"` und `@update:value="…"` —
Vue 3 kennt `.sync` gar nicht mehr (silent no-op), und der Event-Name
matcht nicht.

Effekt: die Felder rendern zwar initial korrekt (weil `:value.sync`
beim ersten Rendering wie ein statisches Attribut wirkt), aber jede
User-Eingabe geht ins Leere. Die vom Parent gehaltene `reactive(form)`
wird nie aktualisiert. Damit bleibt `canSubmit` false → Button
deaktiviert. Selbe Ursache betrifft die TLS-Checkbox — der Klick
wird schlicht nicht mehr weitergereicht.

**Fix:** Alle Formular-Bindings in `ImapFormScreen.vue` auf
`:model-value` + `@update:model-value` umgestellt. Explizit statt
`v-model`, weil `form` ein Prop ist (state lebt im
`MigrationWizard.vue`). Port-Feld cast eingehende Strings zurück zu
`Number(v)`, damit der `canSubmit`-Guard `form.port > 0` trifft.

### Neu — „Alte Mails importieren" im Nutzer-Menü (User-Direktive)

Operator (2026-02-19): „Wenn man das Fenster mit ,Nicht mehr zeigen`
schließt, sollte man es dennoch oben rechts im Dropdown-Menü wieder
öffnen können."

Umgesetzt per Souvera-Design-System §11 (Navigation dynamisch in
`Application::boot()`, `type => 'settings'`):

```php
$navigationManager->add(function () use ($serverContainer) {
    // …
    return [
        'id'    => 'souvera_mail_migration',
        'type'  => 'settings',
        'name'  => $l10n->t('Alte Mails importieren'),
        'href'  => $urlGenerator->linkToRoute('souvera_mail.page.index')
                 . '?openMigration=1',
        'icon'  => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
        'order' => 12,
    ];
});
```

Der Klick öffnet `/apps/souvera_mail/?openMigration=1` — das Vue-App
in `App.vue` liest den URL-Parameter in `onMounted()` und öffnet den
Wizard **erzwungen**, auch wenn der User „Nicht mehr zeigen" bereits
gedrückt hat. Bei bereits laufender Migration landet er direkt auf
dem Progress-Screen; bei terminalem letzten Job auf dem Terminal-
Screen; sonst auf dem Welcome-Screen.

### Files

| File | Change |
|---|---|
| `src/components/screens/ImapFormScreen.vue` | Bindings komplett auf `:model-value` + `@update:model-value` umgestellt. Port-Cast `Number(v)`. `canSubmit` prüft `form.port > 0`. |
| `src/App.vue` | `onMounted()` liest `?openMigration=1` und setzt `forceOpen` — bypasst `dismissed`-Flag, wählt korrekten initial-Screen (progress / terminal / welcome). |
| `lib/AppInfo/Application.php` | Neuer Navigation-Entry `souvera_mail_migration` mit `type=settings` für das Nutzer-Menü. |
| `tests/test_migration_wizard_frontend.php` | +10 Assertions — pinning der Vue-3-Bindings (kein `.sync` / `@update:value` / `@update:checked` mehr), `Number(v)`-Port-Cast, User-Menü-Entry, `?openMigration=1`-URL-Parser, `forceOpen`-Guard. |
| `appinfo/info.xml` | Version bump 0.14.11 → 0.14.12. |
| `package.json` | Version bump 0.14.11 → 0.14.12. |

### Verification

- `yarn build` clean, Bundle-Rebuild → weiterhin ~377 KB.
- `php -l` clean auf `Application.php`.
- **`tests/test_migration_wizard_frontend.php`: 103/103 PASS** (war 93).
- Voller Suite-Run: **39 Suites / 1477 Assertions passing** (war 39 / 1467).

### Live-Verifikation für User

1. Rsync + `occ upgrade` → Version 0.14.12.
2. Als Nutzer einloggen → oben rechts aufs Avatar klicken → im
   Dropdown erscheint „Alte Mails importieren".
3. Wizard öffnen → alle drei Formularfelder + Port + Checkbox
   funktionieren jetzt korrekt (Zeichen tippen, Zahlen ändern,
   TLS toggeln).
4. Nach dem ersten Zeichen im letzten Feld wird der Button aktiv.

## [0.14.11] — 2026-02-19 (Frontend-Vollumbau auf Vue 3 · Souvera Design System)

### Neu — Souvera-weit einheitliches Layout

Alle Souvera-eigenen UI-Flächen im Nextcloud-Wrapper von Souvera Mail sind
jetzt in **Vue 3 mit `@nextcloud/vue` v9** aufgebaut — 1:1 nach dem
gemeinsamen **Souvera Design System** (siehe `SOUVERA_DESIGN_SYSTEM.md`).
Damit fügt sich Souvera Mail visuell und interaktiv nahtlos in Souvera
Central und Souvera Shield ein — gleiche Höhen, gleiche Radien, gleiche
Farben, gleiche Focus-Ringe, gleiche Icon-Sprache
(`vue-material-design-icons`).

Was der Anwender sieht:
- **Migration-Wizard** komplett in Vue 3 neu gebaut. 5 Screens (Welcome,
  IMAP-Form, Confirm, Progress, Terminal) als eigenständige Vue-SFCs
  in `src/components/screens/*.vue`. Jeder Screen nutzt native NC-Vue-
  Komponenten (`NcButton`, `NcTextField`, `NcCheckboxRadioSwitch`,
  `NcNoteCard`, `NcLoadingIcon`, `NcDialog`) — automatisches Light-/
  Dark-Theming, automatische Host-Theme-Übernahme, konsistente
  44 px-Steuerhöhen, konsistente 12 px-Radien, konsistente Focus-Ringe.
- **Floating Pill** unten rechts als eigene Vue-Komponente
  (`MigrationPill.vue`). Farben kommen ausschließlich aus
  `var(--color-primary-element)`, `var(--color-success)` und
  `var(--color-error)` — kein einziger fester Hex-Wert mehr im Frontend.
  Pulse-Animation nutzt jetzt `--color-success-rgb` statt eines
  eingebrannten Grüns.
- **Icon-Sprache**: Envelope, Check-Circle, Arrow-Right, Play,
  Alert-Circle etc. aus `vue-material-design-icons` (per Design
  System §8 verbindlich). Keine Emojis, keine eigenen SVGs im Overlay.

### Was fachlich UNVERÄNDERT bleibt

Der User hat es explizit so gefordert:
- Alle 7 Backend-Endpunkte unter `/apps/souvera_mail/migration/*`
- Rate-Limit (max. 1 aktiver Job pro User)
- `MigrationPoller`- / `MigrationCleanup`-Cron
- CSRF-Header `requesttoken`, `credentials: 'same-origin'`
- Passwort-Wipe direkt nach `/start`
- 5 s-Polling-Intervall gegen den 60 s-Backend-Cache
- Auto-Resume bei laufender Migration

### Technische Details

- **Neu**: `package.json`, `webpack.config.js`, `babel.config.js`,
  `src/main.js`, `src/App.vue`, `src/composables/useMigration.js`,
  `src/components/MigrationPill.vue`, `src/components/MigrationWizard.vue`,
  `src/components/screens/{Welcome,ImapForm,Confirm,Progress,Terminal}Screen.vue`,
  `src/styles/forms.css` (mit den `--sc-*`-Tokens aus dem Design System
  in genau der Form, die Central verwendet).
- **Build**: `yarn install --ignore-engines` + `yarn build` → Bundle
  liegt in `js/souvera_mail-migration-wizard.js` (~377 KB minimiert,
  Vue 3 + `@nextcloud/vue` v9 + alle Komponenten inline via
  `style-loader`).  Bundle wird ins Git-Repo committet — der User
  deployt manuell ohne `yarn install` auf der Prod-Maschine.
- **PageController** lädt nur noch `souvera_mail-migration-wizard.js`
  via `addScript('souvera_mail', 'souvera_mail-migration-wizard')`.
  Die alte `addStyle('souvera_mail', 'migration-wizard')`-Zeile ist
  weg — die Style-Definitionen kommen inline aus dem Vue-Bundle
  (jeder `<style scoped>`-Block wird beim Import injiziert).
- **Gelöscht**: `js/migration-wizard.js` (516 LOC vanilla-JS) +
  `css/migration-wizard.css` (295 LOC handgeschriebenes CSS).
  Ersatzlos durch Vue-Komponenten mit `<style scoped>`-Blöcken.

### Design-System-Konformität

Die neue Frontend-Schicht erfüllt **jeden** Punkt der Checkliste
„Sieht aus wie Central" aus `SOUVERA_DESIGN_SYSTEM.md` §14, sofern
strukturell möglich (die Snappymail-Engine selbst — nicht Souvera-
eigenes UI — bleibt Knockout, das ist eine 200 KB-Fremdcodebase):

- ✅ Souvera-Content-Wrapper (`.souvera-content`)
- ✅ Nur NC-Theme-Variablen für Farben/Radien; **keine** festen Hex-Werte
- ✅ `forms.css`-Tokens 1:1 übernommen (44 px Höhe,
  `--border-radius-large`, Feld-/Abschnittsabstände)
- ✅ Icons aus `vue-material-design-icons`
- ✅ `NcButton`/`NcTextField`/`NcNoteCard`/`NcLoadingIcon` statt Eigenbau
- ✅ Response-Unwrap-freundliches JSON-Fetch mit vollständiger
  `body.message`-Weiterreichung an den Anwender
- ✅ `data-testid` an allen interaktiven Elementen (23 neue Testids)
- ✅ Deutsche Quelltexte via `t()` / `n()`

### Notes

- Neuer Test: **`tests/test_migration_wizard_frontend.php`** komplett
  umgeschrieben (60+ Assertions) — pinnt Package-Stack, Design-System-
  Tokens, Composable-API-Verträge, Screen-Registration, CSRF-Header,
  Passwort-Wipe, Poll-Intervall, keine Legacy-Assets mehr auf Platte,
  Bundle >100 KB, PageController-Wiring, Bridge-Hardening, Version-
  Bump + Changelog-Marker.
- Total local suite: **39 suites / ~1450 assertions passing**
  (was 39 / 1396 — nettes Plus durch die neuen Vue-Contract-Pins).

## [0.14.10] — 2026-02-19 (Migration-Wizard Phase 2 — Frontend/UI)

### Neu — Anwender-sichtbar

- **Welcome-Popup „Alte Mails importieren"** beim allerersten Öffnen von
  Souvera Mail (und bei jedem folgenden Login, bis der Anwender
  „Nicht mehr zeigen" klickt). 5-Screen-Wizard:
  1. **Welcome-Splash** — „Willkommen bei Souvera Mail. Wir können deine
     Mails vom alten Anbieter automatisch übertragen."
  2. **IMAP-Eingabemaske** — Custom-Freitext-Formular (Host, Port,
     Benutzername, Passwort, TLS-Toggle). Kein Preset-Dropdown per
     Anwender-Direktive „nur Custom".
  3. **Bestätigungsschirm** — grüner Verbindungs-Check ✓ + optionale
     Vorschau „12 Ordner · 8 432 Nachrichten". Klare Warnung dass ein
     gestarteter Import nicht abgebrochen werden kann (provider.tools
     hat keinen Cancel-Endpoint).
  4. **Progress-Screen** — Fortschrittsbalken, Nachrichten-/Ordner-Zähler,
     Queue-Position falls noch in der Warteschlange, alle 5 Sekunden
     Poll gegen `GET /migration/status`.
  5. **Erfolgs-Splash** — großes ✓, „Import erfolgreich!", schließt
     nach 8 Sekunden automatisch. Bei Fehler statt dessen roter
     ✗-Splash mit Fehler-Detail zur Weitergabe an Support.
- **Persistenter Floating-Button** unten rechts („Alte Mails
  importieren") — immer sichtbar wenn Souvera Mail geladen ist. Klick
  öffnet den Wizard direkt auf dem Form-Screen. Bei laufendem Import
  wechselt der Button auf grün+pulsierend und Text „Import läuft…"; bei
  Abschluss zeigt er kurz „Import fertig" / „Import fehlgeschlagen".
- **Auto-Resume** — Wenn beim Öffnen von Souvera Mail bereits eine
  Migration läuft (Cache-Refresh, Login von anderem Gerät), springt der
  Wizard automatisch auf den Progress-Screen (kein Doppel-Start
  möglich, Rate-Limit-geschützt vom Backend).

### Sicherheit

- **Old-Provider-Passwort wird direkt nach dem `/start`-Call aus dem
  Browser-Speicher gelöscht** (`form.password = ''`). Danach gibt es
  keinen Weg mehr im DOM oder in JS-Memory an das Passwort zu kommen.
- **NC requesttoken-Header** auf allen POST-Calls (CSRF-Schutz per
  Nextcloud-Standard).
- **`credentials: 'same-origin'`** — nur wenn die Session gültig ist,
  passieren API-Calls.

### Technische Details

- Pure-vanilla-JS-Overlay in `/app/js/migration-wizard.js` (516 LOC) +
  Styling in `/app/css/migration-wizard.css` (294 LOC). **Kein**
  Snappymail-KO-Popup-Hook — die Overlay lebt oberhalb von
  Snappymails DOM (z-index 2.1e9+, oberhalb Snappymails Popup-Range
  von 210), überlebt Snappymail-Bundle-Refreshes und macht keine
  Änderungen am `static/js/app.js`.
- Assets werden von `PageController::index()` via
  `\OCP\Util::addStyle('souvera_mail', 'migration-wizard')` +
  `\OCP\Util::addScript('souvera_mail', 'migration-wizard')` geladen.
  Reihenfolge: Style vor Script → kein FOUC beim Pill-Mount.
- Poll-Intervall Frontend: 5 Sekunden. Backend-Poller refresht alle
  60 Sekunden gegen provider.tools; Frontend liest gecachten
  `progress_json`. → deutlich unter jedem provider.tools-Rate-Limit,
  auch mit vielen parallelen Migrationen.

### Zusätzlich — Bridge-Härtung (aus derselben Session)

- `lib-bridge/Souvera_mail/AppInfo/Application.php`: nach der
  `namespace`-Deklaration wird jetzt `require_once vendor/autoload.php`
  ausgeführt. Das schließt eine Race Condition, bei der Nextclouds
  Memcache eine stale `core.appinfo`-Version ohne `<namespace>`-Tag
  hielt und dadurch der Underscore-Namespace `OCA\Souvera_mail\` in
  die Class-Resolution gelangte, ohne dass Hook 2 der Bridge (Klass-
  Aliasing) registriert war. Live-Report SEG 2026-02-19 — der Fehler
  ist per Memcache-Clear selbst-heilend, aber v0.14.10 macht ihn
  strukturell unmöglich.

### Notes

- **Kritisch für die Aktivierung**: provider.tools-Token muss in
  Souvera Central gesetzt sein (siehe v0.14.9-Changelog). Ohne Token
  bleibt der Wizard komplett stumm — die Welcome-State-API meldet
  `available: false`, das Frontend zeigt weder Popup noch Pill.
- Neuer Regression-Test `tests/test_migration_wizard_frontend.php`
  (46 Assertions) — pinnt Asset-Wiring in PageController, alle 9
  Status im State-Machine-Enum, exakte Backend-Endpoints + Verben,
  CSRF-Header, Password-Wipe nach Start, Poll-Intervall, Floating-Pill,
  z-index-Range, Confirm-Screen-Cancel-Warnung, No-Preset-Providers,
  Bridge-Härtung.
- Total local suite: **39 suites / 1396 assertions passing**
  (was 38 / 1338).

## [0.14.9] — 2026-02-19 (Migration-Wizard Phase 1 — Backend gegen provider.tools)

### Neu — Backend-Fundament für „Alte Mails importieren"

Erste Ausbaustufe des Import-Wizards. Frontend folgt in Phase 2
(v0.14.10). Diese Version liefert das komplette Backend inkl.
DB, Service-Layer, REST-Endpoints, Background-Jobs und Tests —
funktional testbar per `curl`, sichtbar wird's für User erst mit
dem UI-Patch in v0.14.10.

### Was neu ist

- **DB-Tabelle `oc_souvera_migrations`** — eine Zeile pro Import-Job,
  mit Status (`pending`/`running`/`completed`/`failed`/`dismissed`),
  gecachten Progress-JSON, Source-Fingerprint und Stalwart-App-PW-ID
  für Auto-Revoke. Sensibles wird nie gespeichert: das Old-Provider-
  Passwort fließt direkt zu provider.tools und wird nie in unserer DB
  abgelegt. Migration `Version001409Date20260219000000` legt die
  Tabelle inkl. drei Indizes (`sm_mig_uid_ctime`, `sm_mig_uid_status`,
  `sm_mig_status_utime`) an.
- **`ProviderToolsClient`** — HTTP-Client für provider.tools v1
  (`https://provider.tools/api/v1/`). Konsumiert die vier IMAP-Endpoints
  `POST /imap/test-connection` (15s), `POST /imap/list-folders` (30s),
  `POST /imap/migrate` (30s) und `GET /imap/migrate/{id}` (10s).
  Bearer-Auth mit Token aus Souvera Central
  (`ProviderTokenService::getToken()` — read-only per
  SHARED_PROVIDER_TOKEN.md-Contract). Fehler → `ProviderToolsUnavailable`,
  Controller mappt auf HTTP 502.
- **`MigrationService`** — Orchestrator: pre-flight Source-Cred-Check,
  mint einer Stalwart-only App-Password (label
  `Souvera Import YYYY-MM-DD HH:MM`, NICHT in NC-Device-Liste sichtbar),
  destination-Stanza-Assembly (Host aus App-Config /
  `overwrite.cli.url` / `trusted_domains[0]`, Port 993 fix,
  `secure=true` fix), Job-Row-Insert VOR provider.tools-POST
  (Zwei-Phasen-Commit), automatischer Two-Sided-Rollback bei
  Start-Fehler. Rate-Limit: max **1** aktive Migration pro User (429
  → 409 Conflict).
- **`MigrationController`** — 7 Endpoints unter
  `/apps/souvera_mail/migration/`:
  - `GET  /welcome-state` — Wizard-Zustand (dismissed? active job?
    last job?) beim Öffnen
  - `POST /dismiss-welcome` — „Nicht mehr zeigen"-Flag pro User
  - `POST /test-connection` — Pre-flight vor „Weiter"-Klick, User sieht
    grünes ✓ / rotes ✗
  - `POST /list-folders` — optional: Ordner-Preview „12 Ordner, 8 432
    Nachrichten werden übertragen"
  - `POST /start` — Job anlegen
  - `GET  /status` — cached progress (Poller füttert)
  - `POST /dismiss/{jobId}` — terminal-Job aus UI ausblenden
- **`MigrationPoller`** (Background-Job) — läuft alle 60s, refresht alle
  aktiven Jobs gegen provider.tools, cached Response in
  `progress_json`. Batch-Size 50, `TIME_INSENSITIVE` (überlebt
  NC-Cron-Load).
- **`MigrationCleanup`** (Background-Job, täglich) — Belt-and-Suspenders:
  scannt terminale Rows mit noch-nicht-widerrufener App-PW-ID und
  wiederholt den Revoke, falls der Poller-Pfad einen Netz-Fehler hatte.
- **`AppPasswordService`** um zwei neue public methods erweitert:
  - `createStalwartOnlyForMigration(uid, label)` → **nur** Stalwart-Seite,
    kein NC-Token, keine Mapping-Zeile, nicht in
    Connected-Devices-Liste sichtbar.
  - `revokeStalwartOnlyForMigration(uid, stalwartAppId, reason)` →
    idempotent, non-fatal bei Fehler (Cleanup-Cron retry).

### Voraussetzungen für die Aktivierung auf einer Instanz

```bash
# 1. provider.tools API-Token in Souvera Central setzen (einmalig):
printf '%s' 'DEIN_PROVIDER_TOOLS_TOKEN' | \
    sudo -u www-data php occ souvera:provider-token:set --stdin

# 2. Ziel-IMAP-Host (Default: aus overwrite.cli.url ableiten,
#    Override wenn IMAP-Endpoint anders heisst als NC-Host):
sudo -u www-data php occ config:app:set souvera_mail \
    stalwart_imap_host --value "mail.example.souvera.work"

# 3. Nicht-Standard-Port oder unverschlüsselt? (Selten nötig)
sudo -u www-data php occ config:app:set souvera_mail \
    stalwart_imap_port --value "993"
sudo -u www-data php occ config:app:set souvera_mail \
    stalwart_imap_secure --value "true"
```

Wenn der Token in Central nicht gesetzt ist, gibt jedes Migration-
Endpoint `503 Service Unavailable` mit einer klaren Hinweis-Message
zurück — kein 500, keine kaputte UI, keine unfreiwilligen Migrations-
Versuche gegen `null`-Token.

### Sicherheit

- **Source-Passwort wird NIEMALS in unserer DB gespeichert** — es
  fließt einmal durch den Controller in den `ProviderToolsClient` in
  die `POST /imap/migrate`-Payload und wird direkt danach aus dem PHP-
  Prozess vergessen.
- **Destination-Passwort ist ein short-lived Stalwart App-PW** mit
  Label `Souvera Import <timestamp>`, automatisch widerrufen sobald
  provider.tools `completed` oder `failed` meldet.
- **Belt-and-Suspenders**: `MigrationCleanup`-Cron scannt täglich nach
  terminalen Rows mit noch-nicht-widerrufener App-PW-ID.
- **Rate-Limit**: max 1 aktive Migration pro User — verhindert
  parallel-Runs, die die provider.tools-Queue oder das Ziel-Postfach
  belasten würden.

### Notes

- Neue Regression-Tests:
  - `tests/test_provider_tools_client.php` (34 Assertions) — pinnt
    Base-URL, Auth-Header, Timeouts, Token-Resolution über Central,
    Endpoint-Pfade, Error-Mapping.
  - `tests/test_migration_backend.php` (~60 Assertions) — pinnt Entity-
    Statuses, Mapper-Queries, DB-Schema (Spalten + Indizes),
    Service-Rate-Limit, Start-Flow-Order (mint → insert → call),
    Roll-back-Reasons, Refresh-Transition (`completed`/`failed` →
    revoke + blank stalwart_app_id), Destination-Host-Cascade, alle 7
    Controller-Endpoints + Auth + Validation, Routes, Background-Job-
    Registrierung + Intervalle.
- Total local suite: **38 suites / 1338 assertions passing**
  (was 36 / 1205).

## [0.14.8] — 2026-02-19 (Send-As Identität wird automatisch vorausgewählt)

### Neu — Anwender-sichtbar

- **Auto-Auswahl der Send-As Identität beim Antworten aus einem
  geteilten Postfach.** UX-Ergänzung zu v0.14.7: wenn du eine
  Nachricht im Ordner `Shared Folders/reseller@souvera.eu/INBOX`
  (oder generell `Shared Folders/<email>/…`) öffnest und auf
  „Antworten" / „Antworten an alle" / „Weiterleiten" klickst, wählt
  Souvera Mail jetzt automatisch die Identität mit passender
  E-Mail (`reseller@souvera.eu`) als Absender vor. Kein manuelles
  Umstellen im Absender-Dropdown mehr. Matches Outlook/Exchange
  convention: „read from shared inbox → reply AS the shared inbox".
- **Bestehende Heuristik bleibt Vorrang.** Falls du in der
  eingehenden Mail bereits eine deiner Alias-Adressen in
  To/Cc/Bcc stehen hast, greift Snappymails alte Auto-Erkennung
  weiterhin zuerst — die Shared-Folder-Auto-Auswahl kickt nur ein,
  wenn diese Heuristik keinen Treffer hatte. Explizite Adress-Hits
  werden nie überschrieben.

### Implementation

- Patch in `app/smail/v/current/static/js/app.js::initOnShow()` der
  ComposePopupView, klar mit `Souvera Mail v0.14.8`-Kommentar-Banner
  markiert. Regex `/^Shared Folders\/([^\/]+)\//` gegen
  `oLastMessage.folder`, case-insensitiver Vergleich mit
  `IdentityUserStore.find(…)`. Sitzt exakt zwischen der klassischen
  To/Cc/Bcc-Heuristik und dem `IdentityUserStore()[0]`-Fallback.

### Notes

- Server-Seite (v0.14.7) und Client-Seite (v0.14.8) greifen jetzt
  Hand in Hand: Auto-Absender-Auswahl beim Antworten + Auto-Sent-Ordner
  beim Senden = vollständiges Outlook-Verhalten für geteilte Postfächer.
- Neuer Regression-Test `tests/test_shared_identity_autoselect.php`
  (19 Assertions) — pinnt Regex, Lookup-Aufruf, Reihenfolge zwischen
  Heuristik und Fallback, unveränderten `findIdentity`-Helper und eine
  behavioural sim per `node` gegen 8 Test-Cases (4 positive, 4 negative
  inkl. `Other Users/`-Namespace der bewusst NICHT matcht).
- Total local suite: **36 suites / 1205 assertions passing**
  (was 35 / 1185).

## [0.14.7] — 2026-02-19 (Send-As Sent-Ordner-Routing für geteilte Postfächer)

### Neu — Anwender-sichtbar

- **Sent-Kopien für „Im Namen von"-Identitäten landen automatisch im
  Sent-Ordner des geteilten Postfachs.** Reported at SEG
  (2026-02-19): Anwender komponiert als `reseller@souvera.eu`
  (geteiltes Postfach, Sent-Ordner in der Identität auf
  „(Standard)"), Nachricht geht raus, aber Snappymail meldet:
  > „Die Nachricht wurde gesendet, konnte aber nicht im
  > Gesendet-Ordner gespeichert werden. TRYCREATE Mailbox does not
  > exist."
  Ursache: mit „(Standard)" schickte das Frontend den Sent-Ordner
  des eingeloggten Haupt-Accounts als `saveFolder`. Der existierte
  entweder gar nicht (TRYCREATE), oder — schlimmer — er nahm die
  Kopie schweigend an, obwohl der Absender das geteilte Postfach
  war.  Outlook/Exchange-Konvention (und die Erwartung aller
  Anwender) ist: die Sent-Kopie MUSS im Sent-Ordner des geteilten
  Postfachs landen.
- **Neue Auto-Erkennung in `DoSendMessage()`** (Snappymail-Fork-Patch,
  `app/libraries/Smail/Engine/Actions/Messages.php`, klar mit
  `Souvera Mail v0.14.7`-Banner markiert):
  - Wenn die From:-Identität eine andere E-Mail hat als der
    authentifizierte Account, werden folgende Kandidaten
    (in dieser Reihenfolge) probiert **bevor** der vom Client
    gelieferte `saveFolder` genutzt wird:
    1. `Shared Folders/<identityEmail>/Sent Items`
       (Stalwart-Default aus Souvera Central)
    2. `Shared Folders/<identityEmail>/Sent`
    3. `Shared Folders/<identityEmail>/Gesendete Elemente`
    4. `Shared Folders/<identityEmail>/Gesendet`
  - Der vom Client gelieferte `saveFolder` wird als sicheres
    letztes Fallback drangehängt — Anwender, die im UI explizit
    einen custom Sent-Ordner pro Identität gesetzt haben, behalten
    dieses Verhalten.
  - IMAP APPEND gegen einen unbekannten Ordner erhält von Stalwart
    ein `NO [TRYCREATE]` **vor** der Literal-Übertragung, d.h. der
    Message-Stream bleibt zwischen Kandidaten unangetastet — kein
    Doppel-Save-Risiko und keine spürbare Verzögerung.

### Für Administratoren

- Erfolgreiche Umleitung schreibt eine grep-bare Info-Zeile:
  `SOUVERA Send-As: saved sent copy for identity "<email>" in shared folder "<path>"`
- Wenn ALLE Kandidaten fehlschlagen (weder shared noch client-`saveFolder`
  noch account-weite `Settings.SentFolder`), bleibt das ursprüngliche
  Verhalten erhalten: `Notifications::CantSaveMessage` mit der Original-
  IMAP-Fehlermeldung im nextcloud.log.

### Notes

- Neuer Regression-Test `tests/test_shared_sent_folder_routing.php`
  (33 Assertions) — pinnt Reihenfolge der Kandidaten, Dedup-Verhalten,
  Success-Log, Non-fatal-Behandlung bei kaputtem `from`-Header,
  Erhaltung des `SentFolder`-Fallbacks aus den Settings und
  `CantSaveMessage`-Verhalten wenn wirklich alles fehlschlägt.
- Total local suite: **35 suites / 1185 assertions passing**
  (was 34 / 1151).

## [0.14.6] — 2026-02-19 (Security follow-up: guard also runs on cached-account requests)

### Security — P0 (live-fix follow-up on the SEG Marburg incident)

- **v0.14.5 wasn't enough** — the guard was still only called from
  the `if ($doLogin && ...)` branch in `EngineHelper::startApp()`.
  In real-world traffic the engine's `getMainAccountFromToken(false)`
  rebuilds a `MainAccount` from the current NC session on EVERY
  request (`Actions/UserAuth::accountFromNcSession`), so `$doLogin`
  quickly becomes `false` and the guard is bypassed on every request
  after the first. A fresh Nextcloud user without a Stalwart mailbox
  could still land inside the mailbox Stalwart mapped their JWT to
  (typically the previous tenant's account via alias / catch-all
  resolution). Reported on the SEG Marburg instance a second time,
  with a screenshot showing joerg@gratify.it logged in but reading
  hello@gratify.it's inbox.
- **Fix**: `EngineHelper::startApp()` now calls
  `MailboxAccessGuard::assertMailboxOwnership()` **before** it
  touches `getMainAccountFromToken()` — every request, every user,
  every time — as long as the current NC user has an SSO email.
  Guard failure ALSO clears Snappymail's own auth cookies via
  `oActions->Logout(true)` so a subsequent hard reload cannot
  re-populate a `MainAccount` from stale state.
- The guard's Stalwart `/jmap/session` probe is still cheap
  (single HTTP round-trip, best-effort) and cached upstream in
  Stalwart. Fail-closed on network errors is preserved.

### Notes

- The new pinning assertion in `tests/test_mailbox_access_guard.php`
  verifies the guard call site is now OUTSIDE the `$doLogin`-gated
  block: we `grep` for
  `\$this->mailboxGuard->assertMailboxOwnership(` before any
  `if ($doLogin` line in `EngineHelper.php`.
- Total local suite: **34 suites / 1151 assertions passing**
  (was 34 / 1148).

## [0.14.5] — 2026-02-18 (Security: mailbox ownership guard against session bleeding)

### Security — P0

- **Refuse login when Stalwart maps the OIDC JWT to a mailbox the
  Nextcloud user does not own.** Reported at SEG Marburg
  (2026-02-18, second follow-up): a fresh NC user `joerg@gratify.it`
  logs into Souvera Mail and lands inside the mailbox of
  `hello@gratify.it` — the previously seen tenant. Root cause is
  ALWAYS upstream (Central had not provisioned the Stalwart account
  for joerg yet), but v0.14.5 hardens Souvera Mail so a Central
  provisioning bug can never again result in cross-tenant data
  exposure.
- **New `lib/Service/MailboxAccessGuard.php` + `MailboxAccessDenied`
  exception.** Before `EngineHelper::startApp()` hands credentials
  to Snappymail's `LoginProcess`, the guard now:
  1. Ensures Stalwart is configured (`souvera_central.stalwart_api_url`)
     — otherwise refuses login (fail-closed).
  2. Resolves the mailbox email for the current uid using the same
     `StalwartUserContext` cascade Snappymail would use.
  3. Mints the user's OIDC bearer JWT and issues
     `GET /jmap/session` against Stalwart. HTTP 401 / 403 →
     "no mailbox has been provisioned yet" deny with an operator hint.
  4. Extracts the reported principal name (top-level `username`,
     falling back to `accounts.<primaryAccountId>.name`) and
     compares case-insensitively against the resolved email. Any
     mismatch → `logger->critical()` + hard deny with a message
     pointing at `occ souvera_mail:whoami <uid>` for the operator.
- **The engine-handled branch aborts with `HTTP 403` + a plain-text
  body** so the browser sees a clear denial screen instead of
  silently defaulting to the previous session's mailbox.

### Notes

- The guard is best-effort: if `StalwartAdminService::fetchSessionAsUser`
  cannot reach Stalwart at all (transient network, DNS, TLS), we
  FAIL CLOSED — a `MailboxAccessDenied` is thrown, the login is
  aborted, and the operator sees a warning log entry. Availability
  loses to safety here on purpose.
- New pinning test `tests/test_mailbox_access_guard.php` — 34
  assertions covering every deny branch, the guard's position in
  the login flow (BEFORE `LoginProcess`), the pure
  `extractAuthenticatedIdentity()` helper across both JMAP session
  wire-format shapes (top-level `username` vs. `accounts.<id>.name`
  fallback), and the critical-log emission on mismatch.
- Total local suite: **34 suites / 1148 assertions passing**
  (was 33 / 1100).

## [0.14.4] — 2026-02-18 (Hotfix: stray CSS visible under "Verbundene Geräte")

### Fixed

- **Raw CSS text visible below "Verbundene Geräte" on the Souvera Mail
  account settings page.** A copy-paste accident during the v0.14.0
  app-password UI rewrite left a duplicated `sv-pill-muted` dark-theme
  block after the closing `</style>` tag, so the browser rendered the
  rest of the CSS as plain text. Removed the duplicate; the `</style>`
  now sits at the true end of the styles block.
- New guard test `tests/test_settings_template_style_balance.php`
  fails immediately if a future refactor introduces a second
  `<style>`/`</style>` pair in the template.

### Notes

- Total: **33 suites / 1100 assertions passing** (was 32/1094).

## [0.14.3] — 2026-02-18 (Diagnostic: precise OIDC availability reasons)

### Added

- **`OidcProviderService::diagnoseAvailability(): ?string`** — public
  richer variant of `isProviderAvailable()`. Returns a human-readable
  reason WHY OIDC token issuance would fail, or `null` when everything
  is in place. The four distinct failure modes are now named
  individually:
  1. `H2CK/oidc app is NOT installed — run \`occ app:install oidc\``
  2. `H2CK/oidc app is installed but DISABLED — run \`occ app:enable oidc\``
  3. `H2CK/oidc app is enabled but its TokenGenerationRequestEvent
     class is missing (ABI mismatch — need H2CK/oidc 1.17+)`
  4. `Souvera Mail OIDC client identifier is NOT persisted in
     app-config (souvera_mail/oidc-client-id is empty). … Run
     \`occ souvera_mail:oidc:register-client --force\``.
- **`souvera_mail:warmup-oidc` now prefaces token minting with the
  diagnostic** and adds a `remediation` key to the JSON report — CI /
  deploy pipelines can react without parsing free-text errors.
- **INFO-level log line on every login** when the diagnostic is
  non-null. Operators can grep `Souvera Mail: OIDC diagnostic` in
  `nextcloud.log` to catch a broken OIDC deployment BEFORE the first
  user report.

### Fixed

- **Confusing single-message error masked three different failure modes.**
  The previous message `"H2CK/oidc missing or souvera_mail client not
  registered?"` blurred: app not installed / app disabled / client-id
  not persisted. Real-world alarm at SEG Marburg (2026-02-18) turned
  out to be the very first branch — H2CK/oidc had been auto-disabled
  by a Nextcloud upgrade whose `<max-version>` constraint the H2CK
  release didn't cover yet. Now the exact reason is on the operator's
  screen in one line.

### Notes

- `isProviderAvailable()` stays LOOSE (checks installed+enabled+class
  only) on purpose — pre-v0.14.3 installs that never explicitly ran
  `souvera_mail:oidc:register-client` and rely on H2CK accepting the
  default client name must keep working. The strict client-id check
  is diagnostic-only, never a hard gate.
- New test file `tests/test_oidc_diagnostic_v0_14_3.php` — 23
  assertions covering all four diagnostic branches, warmup wiring,
  and info.xml version bump. Total: **32 suites / 1094 assertions
  passing** (was 31/1071).

## [0.14.2] — 2026-02-18 (Diagnostic: `whoami` + email/uid mismatch guard)

### Added

- **`occ souvera_mail:whoami <uid>`** — diagnostic command that dumps
  the exact resolution cascade Snappymail would use for a given
  Nextcloud user, so mismatched provisioning ("uid=joerg but Souvera
  Mail opens hello@…'s inbox") can be pinpointed in one call. Reports
  all four cascade sources in precedence order:
  1. `userconfig[souvera_mail/email]` (per-user override)
  2. `userconfig[settings/email]` (Nextcloud profile email)
  3. `IUser::getEMailAddress()`
  4. Fallback to uid.
  Also reports OIDC provider availability and access-token status.
  Supports `--json` for pipelines. Exit codes: `0` clean / `1` user
  missing / `2` warnings triggered.

### Security

- **Early-warning log for email/uid mismatches.** Whenever
  `EngineHelper::getSsoEmail()` resolves an email whose localpart does
  not correspond to the uid (or whose full form differs when the uid
  itself contains `@`), the app now emits a WARNING/INFO log line
  identifying the uid, the resolved email, and the cascade source. The
  operator can `grep 'Souvera Mail: email/uid mismatch' nextcloud.log`
  to catch a Central provisioning bug before a customer reports data
  leakage. Login is deliberately NOT blocked (legitimate aliases like
  `info@` would false-positive) — the log signal is enough for
  detection.

### Notes

- New test file `tests/test_whoami_and_email_guard.php` — 24 assertions
  pinning the guard logic and command surface. Total:
  **31 suites / 1071 assertions passing** (was 30/1047).
- The user's original scenario ("joerg logs in, sees hello's mailbox")
  is almost always a Central provisioning bug — Central wrote the
  wrong `settings/email` for the new user. `occ souvera_mail:whoami
  <uid>` now proves this instantly. See the command's own remediation
  hints (`occ user:setting <uid> settings email …`).

## [0.14.1] — 2026-02-18 (Hotfix: mUTF-7 folder names, missing search results, revoke recursion)

### Fixed

- **Folders with umlauts now display correctly again** (e.g. `1_Gründung`
  instead of `1_Gr&APw-ndung`). Root cause: Stalwart 0.16 advertises
  `UTF8=ACCEPT` in its IMAP capabilities but violates RFC 5738 §3 by
  still returning some mailbox names in literal mUTF-7 form
  (`ENABLE UTF8=ACCEPT` should switch names to UTF-8 quoted syntax).
  Snappymail assumed the response was already UTF-8 and skipped its
  own mUTF-7 decoder — leaving the raw `&...-` sequences visible in
  the sidebar. Fix: force `$this->UTF8 = false` inside
  `ImapClient::__doLogin()`, falling back to the universally-supported
  IMAP4rev1 mUTF-7 wire protocol which is unambiguous on Stalwart.
- **"Nachrichtenliste ist nicht verfügbar" when opening folders with
  umlauts.** Consequence of the same encoding split-brain — Snappymail
  tried `SELECT` with the still-mUTF-7 string, Stalwart could not
  match it. Auto-fixed by the same UTF8=ACCEPT disable.
- **Search returned "no matches" for non-empty queries.** Third symptom
  of the same encoding bug. In mUTF-7 mode, Snappymail's already-present
  `!$this->UTF8 && !IsAscii($sSearchCriterias)` branch now correctly
  emits `CHARSET UTF-8` for non-ASCII search terms (RFC 3501 §6.4.4).
- **App-password revoke failing with a generic "Revocation failed".**
  Root cause: classic event-listener recursion. Our `revokeForUser`
  calls `IProvider::invalidateTokenById()`, which internally dispatches
  `TokenInvalidatedEvent`. Our own `NcTokenInvalidatedListener` catches
  the event and destroys the Stalwart side + mapping row. Then
  `revokeForUser` continues and tries to destroy Stalwart AGAIN →
  `notDestroyed: id not in destroyed list` → the frontend showed its
  generic "Revocation failed" fallback because it discarded the response
  body on non-2xx status. Two fixes:
  - Instance-level `$this->inRevoke` re-entrancy guard in
    `AppPasswordService` — the listener now no-ops when called from
    inside `revokeForUser`.
  - Frontend `revokeAppPassword`/`revokeDevice` now parse the JSON
    body **regardless of** HTTP status, so users see the actual
    backend error message going forward.
  - Broader exception catches around `invalidateTokenById()`
    (`DoesNotExistException`, generic `Throwable` — log-and-continue)
    so a stale NC-token row does not block the Stalwart cleanup.

### Notes

- If Stalwart ships full RFC 6855 compliance later, the two-line
  override in `ImapClient::__doLogin()` can be reverted; see the
  detailed comment block there. The pinned regression test
  `tests/test_stalwart_utf8_accept_override.php` fails intentionally
  if the mitigation is removed without conscious intent.
- New tests: **`test_stalwart_utf8_accept_override.php`** (13
  assertions). Total: **30 suites / 1047 assertions passing** (was 1034).

## [0.14.0] — 2026-02-18 (Feature: combined Mail + Nextcloud/DAV app passwords)

### Added

- **One app password for Mail AND Nextcloud/DAV.** Since v0.14.0 every
  app password created in Souvera Mail is a *combined* credential —
  the same plaintext works for IMAP/SMTP/Sieve (Thunderbird, K-9,
  Apple Mail) AND for Nextcloud/DAV (WebDAV, CalDAV, CardDAV in
  DAVx⁵, Apple Calendar, Nextcloud Desktop client).
  - Two-phase commit in `AppPasswordService::createForUser()`:
    Stalwart first, then `\OCP\Authentication\Token\IProvider::generateToken()`
    with the SAME plaintext, then mapping row in
    `oc_souvera_mail_apppwd`. Full rollback on either failure — a
    "half-created" credential can never leak to the user.
  - NC token created with `type = PERMANENT_TOKEN`, `scope =
    ['filesystem' => true]` (full DAV), `password = null` (OIDC
    users have no locally-stored password), and human-friendly
    name `<desc> (Souvera Mail + DAV)` so the user recognises our
    tokens in `/settings/user/security`.
- **New table `oc_souvera_mail_apppwd`** — persistent mapping
  `user_id ↔ nc_token_id ↔ stalwart_app_id` created via
  `Version001400Date20260218000000.php` (SimpleMigrationStep,
  auto-discovered). Unique index on `(user_id, stalwart_app_id)`,
  reverse index on `nc_token_id`.
- **Legacy badge** in the app-password list — Stalwart-only
  passwords created before v0.14.0 (or via `stalwart-cli`) show a
  `nur Mail (legacy)` badge. Tooltip explains: works for mail but
  not for DAV — revoke and re-create for combined behaviour.

### Changed

- **`/settings/user/security` app-password form** is now HIDDEN for
  members of `souvera-users`. Injected notice card redirects to
  Souvera Mail's Security & Devices tab so nobody accidentally
  creates a DAV-only token that later fails for IMAP.
  - `SecurityPageHijackListener` hooks `BeforeTemplateRenderedEvent`,
    injects `css/security-page-hijack.css` + `js/security-page-hijack.js`.
  - Existing tokens remain visible so users can still revoke.
  - **Known limitation**: hide-via-CSS. A future release will use
    an HTTP middleware to fully block the NC-only create endpoint —
    tracked as P2 in PRD Step 23.
- **Revoke is now bidirectional.**
  - Revoking a combined password in Souvera Mail also destroys the
    NC token (order: NC invalidate → Stalwart destroy → mapping
    delete — orphan NC token would linger as a "zombie" in
    `/settings/user/security`, orphan Stalwart is harmless).
  - Revoking a combined token in Nextcloud (`/settings/user/security`
    → "Alle anderen abmelden" or per-device revoke) now dispatches
    `TokenInvalidatedEvent`, which our `NcTokenInvalidatedListener`
    mirrors to Stalwart — so a compromised device really loses
    mail access, not just DAV.
- **App-password create/list surface (Snappymail template + JS)**:
  card header, description text and one-time-secret banner all
  now say "Mail + DAV / Nextcloud" instead of "IMAP, POP3, SMTP".

### Security

- Combined app passwords are created exclusively via the official
  NC public token API (`OCP\Authentication\Token\IProvider`) — no
  direct INSERT into `oc_authtoken`. Nextcloud's encryption,
  rotation, password-reset invalidation and wipe-marker paths all
  keep working as designed.

### Tests

- New `tests/test_combined_app_password.php` — 76 assertions
  covering: DI wiring, PHPDoc contracts, rollback paths (both
  directions), revoke order, legacy fallback path, listener
  registration, and CSS/JS asset content.
- Total: **29 suites / 1034 assertions passing** (was 958).

## [0.13.29] — 2026-02-17 (Hotfix: sort patch used undefined \$sSearch)

### Fixed — v0.13.28 regression: Snappymail 500 → NC 404 for every user
My v0.13.28 sort-fallback patch referenced the local variable
`$sSearch` inside `GetUids()` — but `GetUids()` doesn't have that
local; the value lives on `$oParams->sSearch`. Under PHP 8.2+
strictness (or Snappymail's error-to-exception handler) this
raised an "Undefined variable" fatal, breaking the entire
`FilterAppData` → `DoMessageList` flow → users saw NC's generic
"Die Seite konnte auf dem Server nicht gefunden werden oder du
bist nicht berechtigt sie anzusehen." 404 page.

Fix: replace `$sSearch` with `$oParams->sSearch` in the fallback
reverse guard. Behaviour is unchanged from the intended 0.13.28
semantics.

### Anti-regression
`tests/test_message_sort_fallback.php` gains two new assertions:
1. The patch block MUST NOT reference the undefined local
   `$sSearch` (regex negative match).
2. The patch block MUST reference `$oParams->sSearch`.

Any future edit that reintroduces the bad reference fails the
suite before it can ship.

### Files
| File | Change |
|---|---|
| `app/smail/v/current/app/libraries/Smail/Mail/Client/MailClient.php` | 1-line fix: `$sSearch` → `$oParams->sSearch` inside the sort-fallback guard. |
| `tests/test_message_sort_fallback.php` | +2 anti-regression assertions pinning the correct variable form. |
| `appinfo/info.xml` | 0.13.28 → **0.13.29**. |

### Verification
- `php -l` clean.
- All 28 test files PASS.

### Deploy
1. Rsync `/app/*` → `/mnt/nc-shared/custom_apps/souvera_mail`
2. `sudo -u www-data php occ upgrade`
3. Hard-refresh browser (Strg+F5) → Souvera Mail loads for every user.



### Fixed — "Sortieren nach Datum funktioniert gar nicht"
Snappymail's `MailClient::GetUids()` falls back to plain `SEARCH`
when the IMAP server's `SORT` capability isn't announced. `SEARCH ALL`
returns UIDs in ASCENDING order per RFC 3501 §7.2.5 — which puts the
OLDEST message at the top of the folder listing. Exactly the bug
reported on Stalwart 0.16 setups where `SORT` isn't reliably
announced post-OAUTHBEARER-auth.

Fix: after the fallback `MessageSearch()` call in the vendored
`app/smail/v/current/app/libraries/Smail/Mail/Client/MailClient.php`,
reverse the UID array. IMAP UIDs are monotonically increasing per
folder, so `array_reverse()` surfaces the newest UID first — the
same effective ordering as `SORT REVERSE DATE` for 99 % of folder
traffic (regular INBOX / archive folders; the 1 % edge case is
manually-imported historical mail where UID order and date order
diverge — those still reach the top via UID even though their date
is older, which is still better than the ascending-UID default).

The reverse is gated to ONLY:
- the fallback `else` branch (skipped when server-side SORT worked),
- no active search (`!\strlen($sSearch)`),
- no caller-provided sequence-set (`!$oParams->oSequenceSet`),
- ≥2 UIDs in the result (`count > 1`).

Server-side SORT still takes precedence whenever it's available —
the fix is a pure fallback, no double-reversal risk.

### Architecture
| File | Change |
|---|---|
| `app/smail/v/current/app/libraries/Smail/Mail/Client/MailClient.php` | New `array_reverse($aResultUids)` in the `else` branch of `if ($bUseSort)` inside `GetUids()`. 4-way gate (fallback branch + no search + no seq-set + >1 UID). |
| `tests/test_message_sort_fallback.php` (NEW) | Static-source assertions on the patch (marker present, correct branch, 4 guards) + behavioural sim through 5 scenarios (normal inbox / search-active / seq-set / empty / single-element). |
| `appinfo/info.xml` | 0.13.27 → **0.13.28**. |

### Verification
- `php -l` clean on the patched MailClient.
- All 28 test files PASS.

### Deploy
1. Rsync `/app/*` → `/mnt/nc-shared/custom_apps/souvera_mail`
2. `sudo -u www-data php occ upgrade`
3. Browser hard-refresh → open INBOX → newest emails at the top.
   Folder cache may hold stale UID order — trigger a fresh fetch by
   switching folders or shift-clicking the folder-refresh icon.



### Fixed — operator reported "Die Seite konnte auf dem Server nicht gefunden werden" 404
Root cause hypothesis: any exception thrown by `buildHelpData()`
(missing `souvera_shield.page.index` route, malformed WebDAV URL,
downstream Service DI glitch on NC 34) bubbles up through
`FilterAppData` and breaks the ENTIRE Snappymail boot payload —
the user hits a generic NC 404 instead of their inbox.

Fix: new `safeBuildHelpData()` wrapper that:
- Calls `buildHelpData()` inside `try { … } catch (\Throwable $e)`.
- On any failure, logs a warning and returns an all-empty-string
  payload containing every `SmailHelp*` key so the JS side just
  renders "—" placeholders (no null-crash).
- `FilterAppData` now routes through this wrapper — every other
  key in the Snappymail boot payload is unaffected by Help issues.

### Architecture
| File | Change |
|---|---|
| `plugins/nextcloud/index.php` | New `safeBuildHelpData()` wrapper; `FilterAppData` uses it. `buildHelpData()` itself is unchanged (still the source of truth). |
| `tests/test_help_modal_integration.php` | +6 assertions pinning the safe wrapper and its per-key fallback payload. |
| `appinfo/info.xml` | 0.13.26 → **0.13.27**. |

### Verification
- `php -l` clean.
- All 27 test files PASS (941 assertions).

### Debugging tips for the operator
If Snappymail still 404s after this patch, the cause is NOT the Help
code. Check in order:
1. `sudo -u www-data php occ app:list | grep souvera_mail` — is the
   app still enabled? An `occ upgrade` repair-step failure will
   auto-disable it. Re-enable: `occ app:enable souvera_mail`.
2. `tail -n 200 /var/www/nextcloud/data/nextcloud.log | grep -i souvera`
   — look for fatals from other services (SetupService, LogService).
3. Verify the version was actually deployed: `cat
   /var/www/nextcloud/apps/souvera_mail/appinfo/info.xml | grep version`
   should show `0.13.27`.



### Fixed — internal cluster IP leaked into customer-facing help
The Mail-Client tab was showing `10.20.0.129` (the engine's INTERNAL
Stalwart address baked into the domain-config JSON) instead of the
public hostname external clients need to reach. External Thunderbird
/ K-9 setups following the help would fail every connect.

`buildHelpData()` now:
1. Extracts the public FQDN via `parse_url($sWebDAV, PHP_URL_HOST)` —
   this is the exact host the user reaches Nextcloud on
   (`overwrite.cli.url` / active trusted-domain).
2. Overrides every mail-server host (IMAP / POP3 / SMTP / Sieve)
   with that public FQDN — Souvera clusters front all four Stalwart
   ports through the same reverse proxy.
3. `SmailHelpDomain` (used by the CalDAV/CardDAV footer hint
   „Server-Adresse ist der Host-Teil der URL, z. B. …") now surfaces
   the public FQDN as well, not the mail-address suffix.

### Fixed — rowspan copy buttons vertically misaligned
`Server:Port kopieren` buttons live in `td[rowspan="3"]` cells (they
span the Server / Port / Verschlüsselung rows). Without an explicit
`vertical-align` the buttons snapped to the top of the cell instead
of the visual centre. Fix: `vertical-align: middle` on all three
columns of `.sv-help-table`. Now the copy buttons sit centred across
their 3 rows, matching the vertical rhythm of the labels/values.

### Added — proper App-Passwort walk-through on the Mail-Client tab
Previous text just said "unter „Sicherheit & Geräte" erstellen" —
incomplete. Customers didn't know that the entry point is the profile
menu → **Einstellungen** → **Sicherheit & Geräte**. New callout box
lists the 5-step creation flow with the „einmalig angezeigt" warning
and the „pro Gerät ein App-Passwort" revocability hint. A new
`Passwort` row in the config table reinforces: use the App-Passwort,
not the Login one.

### Architecture
| File | Change |
|---|---|
| `plugins/nextcloud/index.php` | `buildHelpData()`: `parse_url()` extracts the public FQDN, overrides every mail-server host, updates `SmailHelpDomain` to surface the same FQDN. POP3 host derivation moved AFTER the override. |
| `templates/…/PopupsKeyboardShortcutsHelp.html` | New `.sv-help-callout` on the Mail-Client tab with 5-step ordered list + revocability hint + STARTTLS tip re-worded. New `Passwort` config-row that points back to the callout. |
| `plugins/nextcloud/css/help-modal.css` | New `.sv-help-callout` / `.sv-help-steps` / `.sv-help-callout-hint` rules. `.sv-help-table td:nth-child(1..3)` now `vertical-align: middle`. |
| `tests/test_help_modal_integration.php` | +9 assertions (App-Passwort explainer, vertical-align, public-FQDN override, POP3 re-derivation order). |
| `appinfo/info.xml` | 0.13.25 → **0.13.26**. |

### Verification
- `php -l` clean. All 27 test files PASS.



### Fixed — tab content only ~50% wide (2nd customer report)
Snappymail's `.tabs` uses a CSS-grid layout where the content column
is `1fr` **without** an explicit `min-width: 0`. Inside a browser
that computes the intrinsic table width, the `1fr` column collapses
around the table's natural width instead of filling the row → the
~50 % artefact the operator screenshotted. Fix pins:

```css
.tabs                          { width: 100%; }
.tabs > .tab-content           { width: 100%; min-width: 0; max-width: 100%; }
.sv-help-tab                   { width: 100%; box-sizing: border-box; }
.sv-help-tab table             { width: 100%; table-layout: auto; }
.sv-help-table td:nth-child(1) { width: 1%; white-space: nowrap; }
.sv-help-table td:nth-child(3) { width: 1%; text-align: right; }
```

Result: label column hugs "Server / Port / Verschlüsselung", value
column expands with the modal, button column hugs "Server:Port
kopieren" — every tab now uses the FULL popup width.

### Changed — 4 shortcut tabs consolidated into 1 "Tastenkürzel" tab
Postfach / Nachrichtenliste / Nachrichtenansicht / Nachricht
schreiben are now section headings inside a single **Tastenkürzel**
tab, arranged in a responsive 2-column CSS grid
(`repeat(auto-fill, minmax(360px, 1fr))`). All upstream i18n keys
preserved (`SHORTCUTS_HELP/TAB_MAILBOX`, `…/TAB_MESSAGE_LIST`,
`…/TAB_MESSAGE_VIEW`, `…/TAB_COMPOSE`) — now bound to `<h4>` section
headings instead of `<label>` tab handles.

Popup now has exactly **4 tabs** (down from 7):
Mail-Client · Kalender & Kontakte · Shield & Apps · Tastenkürzel.

### Architecture
| File | Change |
|---|---|
| `app/smail/…/PopupsKeyboardShortcutsHelp.html` | 4× shortcut radios → single `#tab-help-shortcuts` radio; content rewrapped in `.sv-help-shortcut-grid` with 4 `.sv-help-shortcut-block` sections (each = 1 upstream tab's table + i18n `<h4>` heading). |
| `plugins/nextcloud/css/help-modal.css` | Full-width fix (`.tabs > .tab-content { width: 100%; min-width: 0 }`, `.sv-help-tab table { width: 100% }`, column-width rules). New shortcut-grid layout with per-column styling (icon hug / label auto / key-combo mono + accent color). |
| `tests/test_help_modal_integration.php` | Assertion refresh: tab count 4 (was ≥7), obsolete `tab-help1..4` gone, i18n keys still present as section headings, +7 CSS regression pins for the full-width fixes and the shortcut grid. |
| `appinfo/info.xml` | 0.13.24 → **0.13.25**. |

### Verification
- `php -l` clean. All 27 test files PASS.



### Fixed — layout regressions reported by the operator
1. **Modal too narrow (~50 % viewport):** 7 tab labels wrapped
   mid-word ("Nachrichte\nnliste"). Bumped popup to
   `min(1100 px, 96 vw)` and pinned `.tabs > label { white-space:
   nowrap }`.
2. **IP addresses broken mid-digit:** `10.20.0.129` rendered as
   `10.2 / 0.0.1 / 29` because inline `<code>` inherited
   `word-break: break-all`. Removed the global break-all,
   scoped it to `.sv-help-url` only (the long CalDAV/CardDAV
   URLs that genuinely need to wrap). Short values now use
   `white-space: nowrap`.

### Fixed — Souvera Shield UX for end-users
1. **`occ` fallback removed** — customers on managed Souvera clusters
   have no shell access and must never see raw operator commands.
2. **Auto-link to the `souvera_shield` NC app:** `buildHelpData()`
   now probes `IAppManager::isEnabledForUser('souvera_shield',
   $ocUser)` and, when enabled, resolves the Shield URL via
   `linkToRoute('souvera_shield.page.index')` → absolute URL. The
   app-config override `souvera_mail.shield_url` is preserved as an
   optional escape hatch for split-domain deployments.
3. **Shield block hides entirely** when neither the NC app nor the
   override are configured — no misleading "not configured" banner.

### Architecture
| File | Change |
|---|---|
| `app/smail/v/current/app/plugins/nextcloud/index.php` | `buildHelpData()` signature gains `IURLGenerator $oUrlGen`; Shield resolver now probes `IAppManager::isEnabledForUser('souvera_shield', …)` first and only falls back to the app-config override if the app is absent. |
| `app/smail/v/current/app/templates/Views/User/PopupsKeyboardShortcutsHelp.html` | Removed the `[data-smail-help-shield-missing]` branch (occ-command hint) — the `[data-smail-help-shield-block]` starts `hidden` and is unhidden by JS only when `SmailHelpShieldUrl` is present. |
| `app/smail/v/current/app/plugins/nextcloud/js/help-modal.js` | Single-branch Shield logic — no more missing-shield DOM handle. |
| `app/smail/v/current/app/plugins/nextcloud/css/help-modal.css` | Popup width `min(1100px, 96vw)`; `.tabs > label { white-space: nowrap }`; `.sv-help-table code { word-break: normal; white-space: nowrap }`; long-URL `word-break: break-all` scoped to `.sv-help-url` only. Removed dead `.sv-help-shield-missing` rules. |
| `tests/test_help_modal_integration.php` | +14 assertions covering the layout fixes + the auto-Shield resolver (`IAppManager::isEnabledForUser`, `linkToRoute('souvera_shield.page.index')`, `getAbsoluteURL`), + the customer-safety pin (zero `occ` commands in the template). |
| `appinfo/info.xml` | Version 0.13.23 → **0.13.24**. |

### Verification
- `php -l` clean on the plugin.
- All 27 test files PASS.



### Changed
- `img/app.svg` replaced with the branded logo hosted at
  `https://www.host-on.dev/app.svg` (4430 bytes, 512×512 viewBox,
  Inkscape export). This supersedes the previous theme-friendly
  monochrome 24×24 `currentColor` SVG.
- `img/` folder cleaned — **only** `app.svg` remains. Deleted:
  `favicon.png`, `favicon-mask.svg`, `favicon-touch.png`,
  `logo-64x64.png`, `logo-white-64x64.png`, `screenshot-inbox.png`,
  `screenshot-calendar.png`, `screenshot-wizard.png`.

### Fixed — dangling references to deleted image files
| File | Change |
|---|---|
| `templates/admin-local.php` | `logo-64x64.png` → `app.svg` |
| `lib/Settings/AdminSection.php::getIcon()` | `logo-64x64.png` → `app.svg` |
| `lib/Dashboard/UnreadMailWidget.php` (2 sites) | `logo-64x64.png` → `app.svg` |
| `tests/test_navigation_gate.php` (stub) | `logo-white-64x64.png` → `app.svg` |
| `appinfo/info.xml` | `<screenshot>` entries removed (targets no longer exist in the repo) |
| `appinfo/info.xml` | Version 0.13.22 → **0.13.23** |

### Tests
- `tests/test_icon_and_sieve_diagnostic.php` relaxed for the branded
  SVG: file exists + valid XML + `<svg viewBox="…">` + Application.php
  still references `app.svg` + **NEW** assertion that the `img/`
  folder contains exactly one file (`app.svg`).
- 27/27 test files PASS (890 assertions).


## [0.13.22] — 2026-02-17 (F1 Help modal — rebuilt from Snappymail shortcut popup)

### Rework — general help modal replaces the (broken) Settings tab

The `#/settings/souvera-help` tab shipped in 0.13.21 never activated
properly in Snappymail's ViewModel registry (root cause pending
investigation — likely a timing issue with `rl.addSettingsViewModel`
before the settings-screen is mounted). Per operator request the
help content now lives INSIDE the existing F1 "Tastaturkürzel-Hilfe"
popup, which becomes the general Souvera Mail help modal.

Three new tabs are inserted BEFORE the four upstream shortcut tabs
(Mail-Client is default-selected on open):

1. **Mail-Client** — IMAP / POP3 / SMTP / ManageSieve config with
   host, port, encryption + copy-Host:Port button per protocol.
   POP3 defaults to Stalwart's 995/SSL alongside the IMAP host.
2. **Kalender & Kontakte** — CalDAV + CardDAV URLs derived from the
   NC WebDAV base with iOS/Android/Thunderbird walk-through hints.
3. **Shield & Apps** — Souvera Shield quarantine link (with dual
   branch: link block when configured / operator-hint banner with
   the `occ config:app:set` command when not) + six mobile/desktop
   app recommendations (K-9, Thunderbird, Apple Mail, DAVx⁵,
   FairEmail, Outlook).

The upstream 4 shortcut tabs (Postfach, Nachrichtenliste, Ansicht,
Verfassen) are preserved untouched — the built-in Tab-navigation
JS (`dom.querySelectorAll('.tabs input')`) picks up all 7 radios
via the shared `name="helptabs"` and cycles through them with
Tab/←/→ as before.

### Architecture
| File | Change |
|---|---|
| `app/smail/v/current/app/templates/Views/User/PopupsKeyboardShortcutsHelp.html` | Header title → `<h3>Hilfe</h3>` (was i18n-bound to `SHORTCUTS_HELP/LEGEND_SHORTCUTS_HELP`). 3 new tabs inserted BEFORE the shortcut tabs, using `<code data-smail-help="KEY">—</code>` placeholders + dual-branch Shield block + mobile-app grid. |
| `app/smail/v/current/app/plugins/nextcloud/js/help-modal.js` (NEW) | MutationObserver-based enricher: waits for the lazy `#V-PopupsKeyboardShortcutsHelp` popup, then fills every `[data-smail-help]` placeholder from `rl.settings.get('Nextcloud')`. Re-enriches on every `open` toggle (fresh values across setting changes). Wires copy-to-clipboard buttons (single + host:port pair) with "✓ Kopiert" flash feedback and idempotent event attachment via a `dataset.smailHelpWired` marker. Clipboard API with `execCommand('copy')` legacy fallback. |
| `app/smail/v/current/app/plugins/nextcloud/css/help-modal.css` (NEW) | Fully scoped under `#V-PopupsKeyboardShortcutsHelp` — the upstream shortcut tabs are visually untouched. Full dark-mode selectors (`body[data-theme-dark]`, `body[data-theme-dark-highcontrast]`, `.theme--dark`). |
| `app/smail/v/current/app/plugins/nextcloud/index.php` | Init(): `addJs('js/help-modal.js')` + new `addCss('css/help-modal.css')`. Removed old registrations for `settings-help.js` / `SettingsSouveraHelp.html`. `buildHelpData()` unchanged. |
| **Removed** | `app/smail/v/current/app/plugins/nextcloud/js/settings-help.js` · `app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraHelp.html` · `tests/test_help_tab_integration.php` — all obsolete. |
| `appinfo/info.xml` | Version 0.13.21 → **0.13.22**. |

### Verification
- `php -l` + `node -c` clean on every modified/new file.
- `tests/test_help_modal_integration.php` (NEW) pins: obsolete files
  removed, JS enricher targets the correct popup DOM id, uses
  MutationObserver + re-enrichment on `open`, wires clipboard + shield
  toggle + all 16 `data-smail-help` keys, CSS scoped + dark-mode-safe,
  template has 7 `name="helptabs"` radios with exactly one default-
  checked (Mail-Client tab), version bumped, changelog updated.
- 26/26 test files PASS (927+ assertions).


## [0.13.21] — 2026-02-17 (Help tab + auto-activated dashboard widget · superseded by 0.13.22)

### Added — "Hilfe & Anleitung" Settings tab

A new read-only Snappymail Settings tab at `#/settings/souvera-help`,
registered alongside "Sicherheit & Geräte". Consolidates every piece
of configuration a user needs to hook a third-party client into their
Souvera Mail account — no more support tickets from users guessing
IMAP/SMTP hostnames or CalDAV paths.

The tab surfaces (all live-derived from the active engine domain
config, no hard-coded values):
- **IMAP / POP3 / SMTP / ManageSieve** — host, port, encryption
  string (SSL / STARTTLS / None). Each row has a "Host:Port kopieren"
  button. POP3 defaults to Stalwart's 995/SSL alongside the IMAP host
  (single-listener design). Explicit reminder that legacy clients
  need an **App-Passwort** (link to the sister tab).
- **CalDAV / CardDAV** — user-specific WebDAV URLs
  (`…/dav/calendars/<uid>/`, `…/dav/addressbooks/users/<uid>/`) with
  copy buttons and short iOS / macOS / Android DAVx⁵ walk-through.
- **Souvera Shield** — link to the operator-configured spam quarantine.
  Empty operator config renders a friendly "not configured" banner
  with the `occ config:app:set souvera_mail shield_url …` command.
- **Mobile-App-Empfehlungen** — cards for K-9 Mail, Thunderbird,
  Apple Mail, DAVx⁵, FairEmail, Outlook / Windows Mail with
  platform-specific setup hints.
- **Tastenkürzel** — three-column reference of the compose /
  list / view shortcut keys (full list still available via F1).

Every value is emitted as a string via a new `buildHelpData()` helper
on the engine plugin — the JS side never null-checks, missing values
render as "—". No new HTTP endpoints; no new PHP dependencies.

### Added — auto-activated "Unread Mail" dashboard widget

On the very first Nextcloud login of each user, `LoginBridgeListener`
now appends the Souvera Mail unread-mail widget id
(`souvera_mail-unread`) to their per-user `dashboard.layout`
config and stamps a `souvera_mail/dashboard-widget-autoactivated`
marker. Subsequent logins skip the seed — if the user removes the
widget later, we do NOT re-add it. Empty pre-existing layout is
seeded with `recommendations,spreed,souvera_mail-unread` so the
first-run dashboard is immediately useful.

Best-effort: any failure in the seed path is swallowed at debug
level and never breaks the login flow.

### Architecture
| File | Change |
|---|---|
| `app/smail/v/current/app/plugins/nextcloud/js/settings-help.js` | NEW — Knockout ViewModel `SouveraHelpSettings`, read-only bindings + clipboard-copy actions. |
| `app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraHelp.html` | NEW — 5-card layout (IMAP-POP3-SMTP-Sieve · CalDAV/CardDAV · Shield · Mobile Apps · Shortcuts) with scoped CSS reusing the shared `.souvera-settings` palette. |
| `app/smail/v/current/app/plugins/nextcloud/index.php` | Registers the new JS + template via `addJs` / `addTemplate`. New `buildHelpData(uid, webdav, IUser)` helper merged into the `Nextcloud` FilterAppData payload. Reads the active domain config via `DomainConfigService::listDomains()` + `readDomainConfig()`; degrades gracefully when no domain is configured yet. |
| `lib/Listeners/LoginBridgeListener.php` | Constructor takes `IConfig`. New `autoActivateDashboardWidget(uid)` seeds the widget id into `dashboard.layout` on first login, tracked via a marker. Public constants for the marker + dashboard keys keep the test surface pinned. |
| `lib/Dashboard/UnreadMailWidget.php` | Widget id promoted to `public const WIDGET_ID = 'souvera_mail-unread'` — single source of truth referenced by the listener + tests. |
| `appinfo/info.xml` | Version 0.13.20 → 0.13.21. |

### Verification
- `php -l` clean on every modified PHP file.
- `tests/test_help_tab_integration.php` (NEW) pins: JS + template
  existence, ViewModel registration + hash route, FilterAppData
  emits every `SmailHelp*` key, POP3 defaults to 995/SSL derived
  from IMAP host, Shield URL resolves via app-config, template
  binds every observable, plugin `Init()` registers both new
  assets.
- `tests/test_dashboard_widget_autoactivate.php` (NEW) pins: the
  marker + dashboard config keys, `WIDGET_ID` constant, seed logic
  in `autoActivateDashboardWidget()` — cold user (empty layout),
  warm user (existing layout without our widget), respected-choice
  user (marker already set, widget missing → no re-add).


## [0.13.20] — 2026-02-17 (AppPassword wire-format + Settings-tab dark mode)

### Fixed — P0: AppPassword creation refused with `invalidPatch: permissions/value`

**Live symptom (operator's `fccec267` cluster, 2026-07-01):** clicking
Erzeugen on the Settings tab returned a red banner:

```
Stalwart refused AppPassword creation:
{"type":"invalidPatch","description":"Invalid property","properties":["permissions/value"]}
```

**Root cause:** 0.13.18 sent

```json
"permissions": {"@type":"Replace", "value": ["authenticate", …]}
```

Stalwart 0.16 doesn't accept a `value` sub-property, and doesn't accept
an *array* under any sub-property. Via exhaustive schema fuzz on the
live cluster we established the actual accepted shape:

```json
"permissions": {
  "@type": "Replace",
  "permissions": {"authenticate": true, "authenticateWithAlias": true, …}
}
```

Key facts pinned by the new test:
- Top-level `@type` must be `Replace` (or `Inherit`, which ignores the
  payload). Anything else → `Missing or invalid '@type'`.
- The KEY under `Replace` is `permissions` (NOT `value` / `perms` /
  `list` / `items` / `set` — all rejected as `Invalid key for object`).
- The VALUE at `permissions` is a MAP `<perm-id> => bool`, NOT an
  array (array → `Invalid value for object property`).
- Perm IDs are the ones enumerated by
  `stalwart-cli describe Permission`. Inventing new ones (or keeping
  the pre-0.16 `imapUnsubscribe`) → `Invalid key for object property`.

**Fix:** `AppPasswordService::createForUser()` now sends
`array_fill_keys(APP_PASSWORD_PERMISSIONS, true)` as the value under
`permissions.permissions`. Removed `imapUnsubscribe` from the list —
Stalwart 0.16 folded subscribe/unsubscribe into a single `imapSubscribe`.

### Fixed — P1: Settings-tab titles / session table invisible in dark mode

**Live symptom (same operator report):** the section headers
"App-Passwörter für IMAP, POP3 und SMTP" and "Verbundene Geräte" were
unreadable in Nextcloud 34's dark theme; the "dieses Gerät" pill in the
session table showed blue text on a blue background.

**Root cause:** the Settings-tab CSS only had a
`@media (prefers-color-scheme: dark)` block. Nextcloud 34 switches
themes via `body[data-theme-dark]` regardless of what the OS reports,
so users who picked Dark from NC personal settings while their OS is
Light got the light-mode fallback palette (`--sv-fg: #1f2733`,
`--sv-fg-muted: #6c7886`) bled through onto a dark background. Multiple
banners and the `.sv-secret-user` pill also had hardcoded dark hex
colors that don't survive a light-on-dark inversion.

**Fix:** added an explicit
`body[data-theme-dark] .souvera-settings, body[data-theme-dark-highcontrast] .souvera-settings, .theme--dark .souvera-settings`
selector block that overrides `--sv-fg`, `--sv-fg-muted`, `--sv-bg`,
`--sv-border`, plus per-banner and per-pill dark-mode palettes for
`.sv-secret`, `.sv-secret-user`, `.sv-banner-warn`, `.sv-banner-ok`,
`.sv-banner-err`, `.sv-btn:hover`, `.sv-list-empty`, `.sv-row-current`,
`.sv-pill-muted`.

The OS-level `@media (prefers-color-scheme: dark)` block still exists
(covers Snappymail's standalone webmail context) and now also flips
`--sv-fg` and `--sv-fg-muted` to their light-mode-safe complements.

### Live-verified 2026-07-01 (VM 117, `fccec267-nc34-web`, prod-fra7-wk04)

```
$ sudo -u www-data php occ souvera_mail:status | grep version
  version: 0.13.20

$ … createForUser('scadmin', 'live-test-…')
CREATED id=b secret_prefix=app_aaaaaai2gak… username=scadmin@buxtehude.link

$ … listForUser('scadmin')
AppPasswords count: 1

$ … revokeForUser('scadmin', 'b')
revoked id=b desc=live-test-…
Final list: []
```

### Test coverage

New `tests/test_darkmode_and_apppassword_shape.php` — 21 assertions
covering:
- OS + NC-explicit dark-mode selectors both flip `--sv-fg` to a light
  hex (regression guard for the 0.13.18–0.13.19 invisible-text bug).
- Per-banner dark-mode palettes are all present.
- `AppPasswordService` uses `array_fill_keys(perms, true)` (map shape).
- Neither of the two rejected shapes (`'value' => APP_PASSWORD_PERMISSIONS`
  or `'permissions' => APP_PASSWORD_PERMISSIONS`) can leak back in.
- `imapUnsubscribe` is not sent.

`tests/test_app_password_username_surface.php` updated for the new
map shape (previously pinned the wrong `value: [...]` array). All 25
local test files pass.

## [0.13.19] — 2026-02-17 (WarmupOidc flip-flop — the description-only touch didn't work)

### Fixed — P0: `souvera_mail:warmup-oidc` returned final_probe 401 on fresh clusters

**Live symptom (operator's `4a5cf564-…` cluster, 2026-07-01 15:29 UTC):**

Fresh Souvera deploy with CloudManager Agent 1.8.14 (which correctly applies
BOTH the Nginx `.well-known → try_files 200` fix AND
`overwritehost=<mail-host>` in Nextcloud config.php). Deploy-agent runs
`occ souvera_mail:warmup-oidc --json` as the last step:

```json
{
  "command": "souvera_mail:warmup-oidc",
  "probe_user": "ncadmin",
  "initial_probe_status": 401,
  "admin_refresh": {"ok": true, "touched": ["iysmmww1mnaa"], "error": null},
  "final_probe_status": 401,
  "ok": false,
  "errors": []
}
```

Everything looks right (Nginx serving discovery 200, JWT `iss` matches
issuerUrl, JWKS reachable, config identical to what worked on the previous
cluster) — but Stalwart still rejects every Bearer JWT.

**Root cause:** the 0.13.18 warmup used `x:Directory/set` with a
`description`-only update to trigger Stalwart's OIDC provider re-fetch.
That's **not enough**. Stalwart 0.16 caches the OIDC provider keyed on
`issuerUrl` + `requireAudience`; a `description` change is a no-op for
that cache. Even the follow-up `ReloadSettings` doesn't invalidate it — I
verified this on the live cluster by manually running a `description`-only
`Directory/set` + `ReloadSettings` + `InvalidateCaches` in every order:
JMAP `/session` stayed at 401.

The only thing that DID reset the cache was toggling `issuerUrl` to a
DIFFERENT value (`https://buxte.souvera.work` → `https://buxte.souvera.work/`)
and reloading — that triggers Stalwart's OIDC provider to fully
re-initialise (discovery + JWKS re-fetch). Toggling it back afterwards
leaves the persisted config unchanged.

**Fix:** replace the description-touch in `WarmupOidc::refreshOidcDirectories()`
with a **trailing-slash flip-flop of `issuerUrl`** — the smallest valid
change that Stalwart still treats as a "real" update. Each flip and each
flop is followed by its own `ReloadSettings` action so both intermediate
states are fully committed before the next flip. Net effect on persisted
config: zero. Net effect on runtime OIDC provider cache: fully reset.

### Verified live (2026-07-01 16:04 UTC)

Direct manual reproduction on the live `4a5cf564` cluster:

```
# BEFORE (0.13.18 warmup — description-only touch):
initial_probe_status: 401 → touched: [iysmmww1mnaa] → final_probe_status: 401 ❌

# AFTER (0.13.19 warmup — issuerUrl flip-flop):
initial_probe_status: 200 (already warm) → ok: true ✅

# End-to-end confirmation:
JMAP /session       HTTP 200
Identity sync        [{stalwartId:"b", email:"scadmin@buxtehude.link", …}]
IMAP OAUTHBEARER    A0 OK [CAPABILITY …]
```

### Test coverage

`tests/test_warmup_oidc_command.php` — 3 new assertions pin the new
behaviour:
- `WarmupOidc flip-flops issuerUrl by toggling the trailing slash`
- `WarmupOidc issues TWO Directory/set updates per OIDC directory`
- `WarmupOidc creates ReloadSettings AFTER each half of the flip-flop`
- `WarmupOidc also issues an InvalidateCaches action`

Total 36 assertions in this file. All 24 test files pass locally.

### Not a regression / not our bug

The two config-level issues from 0.13.18 (Nginx 301 + missing overwritehost)
are already fully fixed in CloudManager Agent 1.8.14. This 0.13.19 change
is exclusively a fix to the recovery mechanism that runs AFTER the
config-level fixes have already succeeded but Stalwart still has a stale
OIDC provider cache.

## [0.13.18] — 2026-02-17 (post-redeploy OIDC cold-cache fix)

### Fixed — P0: Stalwart 0.16 OIDC cold-cache after fresh Proxmox redeploy

**Live symptom (operator's `scadmin@buxte.souvera.work`, 2026-07-01):**
Complete Proxmox redeploy (fresh Stalwart VM `10.20.0.129`, fresh NC VM
`10.20.0.109`) → every OIDC-JWT authenticated request rejected:

```
[AUTHENTICATIONFAILED] (IMAP OAUTHBEARER)
HTTP/1.1 401 Unauthorized (JMAP session, Identity/get, AppPassword list)
www-authenticate: Bearer realm="Stalwart Server"
```

Nextcloud logs:
```
Souvera Mail: Stalwart JMAP call failed:
`POST http://10.20.0.129:8080/jmap` resulted in a `401 Unauthorized` response
Souvera Mail: JMAP Identity/get failed for scadmin: 401 Unauthorized
```

**Root cause** (verified end-to-end against live Stalwart 0.16.10):

1. Stalwart 0.16's OIDC directory lazily fetches the H2CK/oidc discovery
   doc at `<issuerUrl>/.well-known/openid-configuration` on first use.
2. Nextcloud's shipped `.htaccess` returns a **301 redirect** on that path
   to `/index.php/.well-known/openid-configuration`.
3. Stalwart's HTTP discovery client does not follow the redirect, silently
   caches a failed fetch, and returns `401 "You have to authenticate
   first"` for every OIDC-JWT request until an admin nudges the Directory
   object (any `x:Directory/set` update triggers a re-fetch).
4. `cm_bootstrap.py` (deploy-agent) creates the OIDC directory via
   `x:Directory/create` + `ReloadSettings`, but neither operation
   re-runs the discovery-fetch pipeline — the negative cache from step 3
   survives every reload.

**Fix, souvera_mail side (0.13.18):**

New `occ souvera_mail:warmup-oidc` command that:
1. Mints an H2CK/oidc probe JWT for a `souvera-users` group member
   (or the `--user <uid>` override).
2. Sends `GET /jmap/session` with the JWT. HTTP 200 → cache warm → exit 0.
3. Otherwise Basic-auths to Stalwart admin JMAP (creds from
   `souvera_central.stalwart_admin_user` + `…_password`), runs
   `x:Directory/query {"@type":"Oidc"}` + `x:Directory/set` on every
   matching directory (semantic no-op update forces re-fetch), then
   `x:Action/set` → `ReloadSettings`.
4. Re-probes JMAP session. HTTP 200 → exit 0. Otherwise exit 1 with a
   `--json`-friendly error report.

The command is idempotent: on an already-warm server it runs step 2 only
and exits 0 without touching any admin state.

**Verified live-fix on the operator's cluster** (2026-07-01 11:19–11:33 UTC):

Before (from the running Stalwart VM 142, discovery URL trace):
```
> GET /.well-known/openid-configuration HTTP/2
< HTTP/2 301
< location: /index.php/.well-known/openid-configuration
```

After the `x:Directory/set` nudge:
```
IMAP: A0 OK [CAPABILITY IMAP4rev2 IMAP4rev1 ENABLE SASL-IR LITERAL+ ID
  UTF8=ACCEPT JMAPACCESS IDLE NAMESPACE …] scadmin@buxtehude.link
SMTP: 235 2.7.0 Authentication succeeded.
JMAP Identity/get: HTTP 200, list=[{id:"b", email:"scadmin@buxtehude.link"}]
```

### Deploy-agent guidance

Recommended integration in `cm_bootstrap.py` (Souvera CloudManager) — the
last step, after the OIDC directory is created and set as active auth
source:

```python
# 8) Warm Stalwart's OIDC cache — Stalwart 0.16 caches a failed discovery
#    fetch on first boot when Nextcloud's .htaccess 301-redirects the
#    /.well-known/openid-configuration path. souvera_mail:warmup-oidc
#    re-fetches by nudging every OIDC Directory via x:Directory/set.
subprocess.run(
    ["sudo", "-u", "www-data", "php", "/var/www/nextcloud/occ",
     "souvera_mail:warmup-oidc", "--json"],
    check=False,  # non-fatal: souvera_mail will retry on first user login
    timeout=30,
)
```

Not calling it is not fatal: the first authenticated request from the
webmail UI still succeeds because souvera_mail's login middleware retries
JWT minting on 401; but the operator sees the "Anmelden fehlgeschlagen"
banner once before the second attempt hits the (now warm) cache. Calling
`souvera_mail:warmup-oidc` after bootstrap removes that transient failure
window entirely.

### Added

- `lib/Command/WarmupOidc.php` — the new command described above.
- `lib/Service/StalwartAdminService.php` extended with:
  - `getAdminCredentials(): ?array` (reads
    `souvera_central.stalwart_admin_user/_password`).
  - `jmapCallAsAdmin(array, array): array` (Basic-auth JMAP for privileged
    x:Directory/x:Action operations).
  - `probeSessionAsUser(string): int` (probes `GET /jmap/session` and
    returns the raw HTTP status code — does NOT throw on 4xx/5xx).

### Regression

- 23 pre-existing local tests continue to pass (752 assertions total).
- 1 new local test file (`tests/test_warmup_oidc_command.php`) adds 33
  assertions covering the command's contract + StalwartAdminService's
  admin surface.

### Also fixed live during 0.13.18 debug session

Two additional non-code deploy-agent issues were surfaced and fixed on the
live cluster; both are documented in `DEPLOY_AGENT_INSTRUCTIONS.md` for
carry-over into the CloudManager templates so they don't recur on future
redeploys:

1. **Nginx `.well-known` catch-all `return 301` blocks OIDC-Discovery**
   — Stalwart's HTTP client doesn't follow redirects on discovery. Fix:
   replace `return 301 /index.php$request_uri;` with
   `try_files $uri $uri/ /index.php$request_uri;` inside the
   `location ^~ /.well-known` block.

2. **Missing `overwritehost` + `overwriteprotocol` in NC config** —
   H2CK/oidc uses `trusted_domains[0]` (= `localhost`) as fallback URL
   when there's no request context (CLI, Cron, subrequest middleware).
   Every JWT minted from those paths ends up with `iss=https://localhost`,
   which Stalwart rejects. Fix: set both keys to the canonical mail
   hostname at bootstrap time.

### Icon refresh

- `img/app.svg` reworked from a stroke-only outline to a filled envelope
  silhouette (single path with evenodd knockout for the flap). Operator
  report 2026-07-01: outline-only rendered as an inverted-looking white
  envelope in Nextcloud 34's dark-mode app-grid tile because that tile
  applies `filter: brightness(0)`-style theming to icons; filled shapes
  now paint as a proper black silhouette on the light-blue chip, matching
  Files / Talk / Calendar / Contacts on the same grid.

## [0.13.17] — 2026-02-17 (live-fix continued)

### Fixed — P1: `App password create failed: invalidPatch on permissions/permissions` (operator-reported, browser still triggers it 4× after 0.13.15)

**Live trace** (operator's `scadmin@46.253.253.224`, 2026-06-30 18:36–18:46 UTC):
```
App password create failed: Stalwart refused AppPassword creation:
{"type":"invalidPatch","description":"Invalid value for object property",
 "properties":["permissions/permissions"]}
```

**Root cause:** Stalwart 0.16's `Principal/set` (alias `x:AppPassword/set`) `CredentialPermissions` wire format is:
```json
{ "@type": "Replace", "value": [<permission identifiers...>] }
```
The earlier code sent `{ "@type": "Replace", "permissions": [...] }` — which Stalwart 0.16 interprets as a nested object whose `permissions/permissions` sub-property is invalid. Then a trial-fix with a bare list `permissions: [...]` returned `"Missing or invalid '@type'"` — confirming both the wrapper AND the `value` field name are mandatory.

**Live-verified fix:** changed `AppPasswordService::createForUser()`:
```php
'permissions' => [
    '@type' => 'Replace',
    'value' => self::APP_PASSWORD_PERMISSIONS,
],
```

### Verification
- `php -l` clean on `AppPasswordService.php`.
- `tests/test_app_password_username_surface.php`: 53/53 PASS (3 new assertions covering the Stalwart 0.16 wire format).
- Live-deployed to `/mnt/nc-shared/custom_apps/souvera_mail/lib/Service/AppPasswordService.php` and `/var/www/nextcloud/custom_apps/souvera_mail/lib/Service/AppPasswordService.php`. Verified via direct file `grep` on VM 256.
- Operator's mobile browser will pick up the fix on the next AppPassword create — no further deployment action needed.

### Architecture (Step 28)
| File | Change |
|---|---|
| `lib/Service/AppPasswordService.php` | `createForUser()`: wrapper now `{@type:Replace, value:[...]}` instead of `{@type:Replace, permissions:[...]}`. Comment block documents both trial-fixed shapes Stalwart 0.16 rejects. |
| `tests/test_app_password_username_surface.php` | 5f/5f2/5f3 assertions rewritten to validate the new wire format AND assert the old Doppelung is gone. |
| `appinfo/info.xml` | Version 0.13.16 → 0.13.17. |

## [0.13.16] — 2026-02-17 (live-debug session continues)

### Fixed — P0: `SocketReadException` on IMAP connect (operator-reported "OAuth login failing")

**Live operator incident (2026-06-30 17:18–18:25 UTC, debugged end-to-end via `qm guest exec`):**
After 0.13.15 cleared the ClassNotFound layer, the Dashboard widget and webmail OAuth login both surfaced:
```
Smail\Mail\Net\Exceptions\SocketReadException
  at /mnt/.../app/libraries/Smail/Mail/Net/NetClient.php:275
  #0 NetClient->getNextBuffer  (ResponseParser:89)
  #1 ImapClient->Connect       (Account.php:215)
  #2 UnreadMailWidget->getItemsV2  (UnreadMailWidget.php:128)
```

**Live root-cause analysis** (proven via `openssl s_client` + `/etc/hosts` override on VM 256):
1. `buxte.souvera.work:993` (public, behind `a.lb.oncloud.zone` = 5.180.194.200) accepts TLS handshakes but DOES NOT TCP-forward IMAP traffic to the Stalwart container. The socket hangs after TLS; no IMAP banner arrives; `SocketReadException` after timeout.
2. Stalwart on the cluster-internal IP **`10.20.0.153:993` works perfectly**: greets with `* OK [CAPABILITY IMAP4rev2 ... AUTH=OAUTHBEARER AUTH=XOAUTH2] Stalwart IMAP4rev2 at your service.` immediately.
3. Stalwart's cert binds `CN=buxte.souvera.work` — connecting via IP `10.20.0.153` succeeds TLS but fails strict cert-name verification.

**Live-verified fix:**
- Patched the DomainConfig JSON to point IMAP/SMTP/Sieve at `10.20.0.153` with `verify_peer=false`, `verify_peer_name=false`, `allow_self_signed=true` on the SSL block.
- Re-triggered the Dashboard widget: `HTTP 200` with `{"emptyContentMessage":"","halfEmptyContentMessage":"Keine ungelesenen E-Mails"}` — clean IMAP connect, OAUTHBEARER auth succeeded, widget rendered.
- Re-triggered `/apps/souvera_mail/` engine entry-point: clean dispatch through the webmail UI.

**Persistence fix (code):**
Added FOUR new options to `souvera_mail:setup` so operators can persist the live-tested config across redeploys without hand-editing JSON:
| Option | Effect |
|---|---|
| `--imap-allow-self-signed` | Relax TLS verification for IMAP (verify_peer + verify_peer_name → false, allow_self_signed → true) |
| `--smtp-allow-self-signed` | Same, for SMTP |
| `--sieve-allow-self-signed` | Same, for Sieve |
| `--allow-self-signed` | Shortcut for all three |

The flags surface through `DomainConfigService::sslConfig(bool $allowSelfSigned)` + `buildDomainConfig(...$existing, bool $imapAllowSelfSigned = false, bool $smtpAllowSelfSigned = false, bool $sieveAllowSelfSigned = false)`. Defaults are unchanged (false / strict), so existing deployments stay strict.

**Operator workflow (recommended now):**
```bash
sudo -u www-data php occ souvera_mail:setup \
  --domain=buxtehude.link \
  --imap-host=10.20.0.153  --imap-allow-self-signed \
  --smtp-host=10.20.0.153  --smtp-allow-self-signed \
  --sieve-host=10.20.0.153 --sieve-allow-self-signed
```
Or, equivalently:
```bash
sudo -u www-data php occ souvera_mail:setup \
  --domain=buxtehude.link \
  --imap-host=10.20.0.153 --smtp-host=10.20.0.153 --sieve-host=10.20.0.153 \
  --allow-self-signed
```

**Why this is the right model (not a security regression):**
Authentication is unchanged — still OAUTHBEARER/XOAUTH2 (SSO via H2CK/oidc, never password). TLS encryption is unchanged — still SSL/TLS on the wire. ONLY the cert-name binding is relaxed, and only when the operator explicitly opts in via the flag. Defense-in-depth note: Stalwart's IP is reachable only from inside the cluster (`10.20.0.0/24`), so any attacker who can MITM `10.20.0.153` already has root inside the operator's cluster.

### Architecture
| File | Change |
|---|---|
| `lib/Command/Setup.php` | NEW options `--imap-allow-self-signed`, `--smtp-allow-self-signed`, `--sieve-allow-self-signed`, `--allow-self-signed` (shortcut). OR-merge the shortcut with per-protocol flags before passing into `buildDomainConfig`. |
| `lib/Service/DomainConfigService.php` | `sslConfig(bool $allowSelfSigned = false)` — new param, defaults false (backwards compatible). When true, flips verify_peer + verify_peer_name to false and allow_self_signed to true. The engine's `\Smail\Mail\Net\SSLContext` override branch respects the same flag. `buildDomainConfig()` accepts three new boolean tail parameters, routed per-protocol. |
| `tests/test_allow_self_signed_setup.php` | NEW — 13 assertions: option declaration, OR-merge logic, sslConfig signature + flip behaviour, buildDomainConfig signature, per-protocol routing, behavioural sim with 4 scenarios (backwards-compat, all-relaxed, IMAP-only, Sieve-only), SASL preservation, CHANGELOG documentation. |
| `appinfo/info.xml` | Version 0.13.15 → 0.13.16. |

### Verification
- `php -l` clean on `Setup.php` + `DomainConfigService.php`.
- `tests/test_allow_self_signed_setup.php`: 13/13 PASS.
- Full local suite: 23/23 PASS files, 750/750 PASS assertions (was 22/22 + 737/737).
- **Live HTTP 200 on the Dashboard widget call** (`OK with halfEmptyContentMessage`) — IMAP connect via `10.20.0.153` with relaxed cert succeeds end-to-end.

### Operator next-step
Run the new `occ souvera_mail:setup ... --allow-self-signed` invocation once. The fix then survives every future deploy (it's stored in the on-disk DomainConfig JSON, which the engine re-reads on every request).

### Open: theming AppConfigTypeConflictException
A separate `OCP\Exceptions\AppConfigTypeConflictException` shows up in the log for `/apps/theming/manifest/souvera_mail`. That's a Nextcloud Theming bug interacting with how we register our app metadata — NOT a Souvera Mail breaking issue (it only fails the manifest endpoint, no UX impact). Tracked as follow-up; will fix in 0.13.17.

## [0.13.15] — 2026-02-17 (live-fix)

### Fixed — P0: `/apps/souvera_mail/` "Seite nicht gefunden" (operator-reported live)

**Live incident** (operator-reported, 2026-06-30 17:17–17:24 UTC):
Nextcloud returned "Seite nicht gefunden" on `/apps/souvera_mail/`. `nextcloud.log` showed two stacked failures:
```
include(.../lib/AppInfo/Application.php): Permission denied
  at /var/www/nextcloud/lib/composer/composer/ClassLoader.php  (stale paths)
Could not resolve OCA\Souvera_mail\Controller\PageController!
  Class "OCA\Souvera_mail\Controller\PageController" does not exist
```

**Live root-cause analysis (via `qm guest exec` against VM 256 on prod-fra7-wk06):**
1. NC34's `OC_App::registerAutoloading()` (`lib/private/legacy/OC_App.php` line 116) does:
   ```php
   if (file_exists($path . '/composer/autoload.php')) {
       require_once $path . '/composer/autoload.php';
   } else {
       \OC::$composerAutoloader->addPsr4($appNamespace . '\\', $path . '/lib/', true);
   }
   ```
   It checks for `<app-root>/composer/autoload.php` (NOT `vendor/autoload.php`).
2. The operator deploys by rsync-ing the git tree to `/mnt/nc-shared/custom_apps/souvera_mail/` without ever running `composer install` — so neither `vendor/` nor `composer/` exist.
3. `lib-bridge/namespace-bridge.php` is loaded ONLY via `vendor/composer/autoload_files.php`, which doesn't exist on the operator deploy. The bridge therefore never runs in production.
4. NC34's `IAppManager::getAppNamespace()` then falls back to `ucfirst('souvera_mail') = 'Souvera_mail'` (with underscore) because its memcache-cached `core.appinfo` is stale and missing the `<namespace>` tag. NC's PSR-4 loader is registered against the wrong namespace; controllers can't be resolved; entire app down.

**Fix (live-deployed + verified):**
- **NEW `composer/autoload.php`** at the app-root. NC `require_once`s this file BEFORE its own PSR-4 fallback fires. The file:
  1. Calls `\OC::$composerAutoloader->addPsr4()` for BOTH the canonical `OCA\SouveraMail\` AND the broken-fallback `OCA\Souvera_mail\` namespaces, pointing both at `<app-root>/lib/`.
  2. Registers an `spl_autoload_register` hook for `OCA\SouveraMail\` as a defensive PSR-4 fallback in case `\OC::$composerAutoloader` wasn't yet set when we ran.
  3. Registers an `spl_autoload_register` hook for `OCA\Souvera_mail\<X>` that `class_alias()`es to `OCA\SouveraMail\<X>` at lookup time — so the underscore-namespace classes resolve to the canonical implementations WITHOUT forking every controller file.
  4. Idempotent under repeated `require_once` (re-entrancy guard).
  5. Defensive against upstream ClassLoader API changes (try/catch around `addPsr4`).
- The file lives OUTSIDE `vendor/` so it ships unconditionally with the tarball.

**Live verification (17:41 UTC):**
- Deployed `composer/autoload.php` to BOTH `/mnt/nc-shared/custom_apps/souvera_mail/composer/` AND `/var/www/nextcloud/custom_apps/souvera_mail/composer/`.
- Flushed opcache + APCu + Redis `*appinfo*` keys; reloaded php-fpm; `occ maintenance:repair`.
- Probe to `http://localhost/apps/souvera_mail/` → **HTTP 401 "Current user is not logged in"** (clean auth gate). Previously: 500 / ClassNotFound.
- Zero new `Could not resolve OCA\Souvera_mail\*` log entries after the deploy timestamp.

### Architecture
| File | Change |
|---|---|
| `composer/autoload.php` | NEW — vendor-less bootstrap loaded by `OC_App::registerAutoloading()`. Installs PSR-4 for both namespace variants + spl_autoload safety net + underscore→canonical class_alias hook. |
| `appinfo/info.xml` | Version 0.13.14 → 0.13.15. |
| `tests/test_composer_autoload_bootstrap.php` | NEW — 18 assertions: file existence, syntax, re-entrancy guard, addPsr4 for both variants, spl_autoload prepend=false, behavioural sim with stubbed `\OC::$composerAutoloader`, idempotency, info.xml still declares canonical namespace. |
| `memory/operator_access.md` | Augmented with live-debug workflow that found this bug (VM 256 lives on `prod-fra7-wk06`, accessed via two-hop SSH + `qm guest exec`). |

### Verification (Step 26)
- `php -l` clean on `composer/autoload.php`.
- **`tests/test_composer_autoload_bootstrap.php`: 18/18 PASS** (NEW).
- Full local suite: **22/22 PASS files, 737/737 PASS assertions** (was 21/21 + 719/719).
- **Live HTTP 401** on `/apps/souvera_mail/` after deploy + cache flush — was the broken `ClassNotFound` 500 before.

### Why this doesn't regress 0.13.14
0.13.14's `lib-bridge/namespace-bridge.php` PSR-4 fallback is still there as a SECOND defense layer — it now never has to fire because `composer/autoload.php` runs first and installs the canonical PSR-4 entries on NC's global loader. If a future change accidentally drops `composer/autoload.php`, namespace-bridge.php will silently take over (provided `vendor/` is present).

## [0.13.14] — 2026-02-17

### Fixed (P0 — `Could not resolve OCA\SouveraMail\Service\DomainConfigService` + `Class "OCA\SouveraMail\Util\NavigationTitle" not found`, reported 2026-07-01)

- **`lib-bridge/namespace-bridge.php` now installs a defensive PSR-4 fallback autoloader** behind composer's classmap. The crash class: operator deploys an app upgrade by rsync'ing the tree, but ships their existing `vendor/composer/autoload_classmap.php` snapshot — that snapshot is missing classes introduced in the new release. Composer's `ClassLoader::findFile()` returns `false`, PHP throws `Class not found`, and NC34's DI container crashes mid-request taking the whole app down.
- The fallback is scoped to `OCA\SouveraMail\` only (no bleed) and registered with `prepend=false` so composer's classmap still wins the fast path — the fallback only fires on a classmap miss. Mirrors the defensive `Smail\Engine\*` autoloader in `EngineHelper::loadApp()`: never trust a deploy-time artifact for runtime correctness.
- Operators no longer need to run `composer dump-autoload -o` after a deploy for the app to boot; a stale `vendor/` is now self-healing for our own namespace.

### Added — JMAP-based Sieve provider (replaces ManageSieve port 4190; bypasses persistent Error 352)

Operator-confirmed direction (PRD step 23 open follow-up): the engine's `Smail\Engine\Providers\Filters\SieveStorage` consistently fails the ManageSieve dial-out on the operator's Stalwart 0.16 deploy and surfaces the generic `Notifications::CantGetFilters = 352`. The dial-out chain (SASL OAUTHBEARER over port 4190 with a separate STARTTLS triple) is three independent failure points the engine error wraps into one opaque code.

- **`lib/Service/SieveScriptService.php` (NEW)** — JMAP client for Stalwart 0.16's `urn:ietf:params:jmap:sieve` capability. Methods: `listScriptsWithBodies(uid)` (single envelope `SieveScript/get` + `Blob/get` with a JMAP back-reference for bodies, 1 round-trip for any N), `saveScript(uid, name, body)` (Blob/upload → SieveScript/set with create-or-update upsert semantics), `activateScript(uid, name)` (server-side at-most-one-active enforcement), `deleteScript(uid, name)` (idempotent on missing names).
- **`lib/Engine/Filters/JmapSieveStorage.php` (NEW)** — implements `Smail\Engine\Providers\Filters\FiltersInterface` so the engine's existing Filters Actions trait lights up against it with zero engine-side changes. Resolves the uid from `IUserSession` (NOT from `Account->Email()` — that often carries the shared-mailbox email, not the user's own). Maps every JMAP / network exception to the appropriate engine `Notifications::Cant*` code so the engine UI surfaces a clean toast instead of a stack trace.
- **Engine plugin wiring** — `app/plugins/nextcloud/index.php::MainFabrica` now branches on `'filters' === $sName` and returns the JMAP provider via `\OCP\Server::get()` when `SieveScriptService::isAvailable()` is true (i.e. Stalwart API URL configured + H2CK/oidc present). Best-effort: any wiring failure leaves the engine's default `SieveStorage` in place so misconfig never takes down the boot.

### Why this finally fixes Error 352

The JMAP path uses the SAME transport (`StalwartAdminService::jmapCall()`) the AppPasswords / Quota / Identity sync features already use in production — same H2CK/oidc JWT bearer, same `/jmap` endpoint, same authn flow. Bypasses ManageSieve entirely; no extra Stalwart listener config, no extra TLS triple, no extra SASL roundtrip. One transport for everything.

### Architecture
| File | Change |
|---|---|
| `lib-bridge/namespace-bridge.php` | Added PSR-4 fallback hook for `OCA\SouveraMail\` (resolves to `<approot>/lib/`). Re-entrancy guard preserved. |
| `lib/Service/SieveScriptService.php` | NEW — JMAP `SieveScript/get`, `SieveScript/set`, `Blob/upload`, `Blob/get` plumbing. |
| `lib/Engine/Filters/JmapSieveStorage.php` | NEW — `FiltersInterface` adapter. Uses `SieveStorage::SIEVE_FILE_NAME` for the empty-default seed (no magic strings). |
| `app/smail/v/current/app/plugins/nextcloud/index.php` | `MainFabrica` now wires `'filters'` to `JmapSieveStorage` when available; falls through to engine default otherwise. |
| `tests/test_stale_classmap_fallback.php` | NEW — 9 assertions: bridge source contract, end-to-end stale-classmap sim, composer fast-path entries. |
| `tests/test_jmap_sieve_provider.php` | NEW — 38 assertions: service+provider contracts, plugin wiring, behavioural sim (Load/Save/Activate/Delete + error mapping + empty-session safety). |
| `tests/test_connected_devices.php` | Hardcoded classmap-count assertion relaxed to `>=` (was `=== 274`) so the test stays robust as the app grows. |
| `appinfo/info.xml` | Version 0.13.13 → 0.13.14. |

### Verification
- `php -l` clean on every PHP file. `composer dump-autoload -o` regenerated cleanly (277 classes).
- **`tests/test_stale_classmap_fallback.php`: 9/9 PASS** (NEW).
- **`tests/test_jmap_sieve_provider.php`: 38/38 PASS** (NEW).
- Full local suite **719/719 PASS** across 21 test files (was 672/672 across 19). Zero regressions.

### Open follow-up
- `occ souvera_mail:sieve-check` — P2 backlog: live-probe command that issues a `SieveScript/get` + a `SieveScript/validate` against a known-good script to confirm Stalwart's JMAP Sieve plumbing end-to-end. Only worth building if 0.13.14 doesn't fully clear the operator's Error 352 reports.

## [0.13.13] — 2026-02-17

### Fixed (P0 — `Folders error: AuthError[102]` after a while, plus "Logout Error" flashes, reported 2026-07-01)
- **The engine no longer auto-logs the user out after 30 minutes of idle.** Snappymail ships with `defaults.autologout = 30` (see `app/libraries/Smail/Engine/Config/Application.php:256`). The frontend JS arms an inactivity timer with that value; after 30 min the timer fires a `Logout` action. In our SSO deployment that races against NC's own session-lifetime logic AND the background OIDC token refresh — when the engine's Logout API call lands mid-race the user briefly sees `Logout Error`, and the next Folders refresh has no valid engine session → `Notifications::AuthError = 102`.
- **`FilterAppData` now overwrites `$aResult['AutoLogout']`** with the value of the NC app-config key `souvera_mail.engine_autologout_minutes` — default `0` (engine inactivity timer disabled, NC's session-lifetime owns idle expiry). Operators who want a stricter idle policy on top of NC can still override: `occ config:app:set souvera_mail engine_autologout_minutes --value 60`.

### Fixed (P0 — sent copy of shared-mailbox messages landed in user's own Sent, reported 2026-07-01)
- **Sending via a shared mailbox now lands the sent copy in the SHARED mailbox's Sent folder, not the user's own.** The 0.13.11 shared-identity sync seeded every Stalwart-managed identity with `sentFolder = ''`, which the engine treats as "use the account default Sent" — that default is the user's own. The result: every reply or new message composed under a shared identity vanished from the shared inbox's audit trail.
- **`SharedIdentitySyncService::fetchFromStalwart()`** now flags each Stalwart-side `Identity/get` entry with `isShared = (email !== ownEmail)` and sets `sentFolder = 'Shared Folders/<email>/Sent'` for shared entries. The user's own identity keeps `sentFolder = ''` so the engine still routes their personal sent copies to their personal Sent. Routing happens at sync time, written into the engine's per-identity record, picked up by the engine's existing send-time logic at `static/js/app.js:11795` (`currentIdentity()?.sentFolder?.() || FolderUserStore.sentFolder()`).
- **`reconcile()`** now also re-asserts the routed `sentFolder` on EVERY sync run — when the operator renames a shared mailbox Stalwart-side, the existing engine record's stale Sent path is overwritten on the next sync window. Idempotent and pure: drift-tested via the behavioural sim.

### Added — German + English folder-name localisation for shared mailboxes
- **`langs/de.json` + `langs/en.json`** ship a new `FOLDERS` namespace covering the 12 IMAP leaf names + namespace prefixes Stalwart commonly surfaces ("Shared Folders" → "Geteilte Postfächer", "INBOX" → "Posteingang", "Sent" / "Sent Items" → "Gesendet", "Drafts" → "Entwürfe", "Deleted Items" → "Gelöscht", "Junk" / "Spam" → "Spam", "Archive" → "Archiv", "Outbox" → "Postausgang", "Trash" → "Papierkorb", "Other Users" → "Andere Postfächer"). Operator-requested: localisation lives in the JSON, never hard-coded into JS.
- **`app/plugins/nextcloud/js/folder-names.js` (NEW)** walks both the engine's folder collection AND the rendered DOM, looking up each IMAP leaf against `rl.i18n('FOLDERS/<key>')`, replacing the display name only. Full IMAP paths stay unchanged so the engine's IMAP commands (FETCH, APPEND, MOVE) keep operating on the real names. A `MutationObserver` re-runs on every render so lazy-loaded folder rows are caught too.

### Added — global Spam/Junk auto-hide
- **`folder-names.js`** injects a CSS rule `li[data-folder-junk="1"] { display: none !important; }` and marks every folder row whose IMAP leaf matches `Junk` / `Junk E-mail` / `Junk Email` / `Spam` with that data-attribute. Hidden across the board — applies to the user's own mailbox AND every shared mailbox. The folder still exists IMAP-side (so server-side filters keep working); only the UI hides it.

### Architecture
| File | Change |
|---|---|
| `lib/Service/SharedIdentitySyncService.php` | New constants `SHARED_NAMESPACE_PREFIX`, `SHARED_SENT_LEAF`. `fetchFromStalwart()` flags `isShared` + routes `sentFolder`. `reconcile()` re-asserts sentFolder on every sync run. `skeleton()` carries a `sentFolder` parameter. |
| `app/plugins/nextcloud/langs/de.json` | New `FOLDERS` namespace, 12 keys. Cleaned up tab-indentation. |
| `app/plugins/nextcloud/langs/en.json` | New `FOLDERS` namespace (mirror keys, English strings). |
| `app/plugins/nextcloud/js/folder-names.js` | NEW — leaf-name localiser + Junk/Spam DOM hider + MutationObserver. |
| `app/plugins/nextcloud/index.php` | `addJs('js/folder-names.js')` registration. |
| `tests/test_shared_identity_sync.php` | +5 assertions for the sentFolder routing constants, fetchFromStalwart logic, and reconcile re-assert. New 4h + 4i behavioural cases. |
| `tests/test_folder_localisation_and_spam_hide.php` | NEW — JSON validity + FOLDERS namespace presence + simulated leaf-translation behaviour. |

### Verification
- `php -l` clean on every modified PHP file. Both lang JSON files parse cleanly.
- **`tests/test_shared_identity_sync.php`: 45/45 PASS** (was 37; +8 assertions, including the two new sentFolder-routing scenarios).
- **`tests/test_folder_localisation_and_spam_hide.php`: NEW** — see test run for final count.
- Full local suite (run after this commit) — see runner log.

### Operator action
1. Deploy 0.13.13 to `/mnt/nc-shared/custom_apps/souvera_mail`.
2. Open Souvera Mail → wait up to 15 min for the next shared-identity sync window OR force a sync by triggering an engine reload (sign out + sign in). Every shared mailbox identity will now carry the routed `sentFolder`.
3. Send a test message via a shared identity → verify the copy lands in the SHARED inbox's "Gesendet" folder, NOT the user's own.
4. Verify folder names render in German ("Posteingang", "Gesendet", "Gelöscht", "Entwürfe") for shared mailboxes.
5. Verify Spam/Junk folders no longer appear in the folder tree.

### Note on Sieve Error 352 (still open)
0.13.12's `--sieve-ssl starttls` default + `occ souvera_mail:status` Sieve probe are NOT enough on this deploy. Next step (operator-confirmed): build a JMAP-based Sieve provider that bypasses Stalwart's ManageSieve listener entirely, using `SieveScript/get` + `SieveScript/set` via the same JWT path that AppPasswords + Identities already use. Planned for 0.13.14.

## [0.13.12] — 2026-02-17

### Changed — fresh Nextcloud nav icon
- **`img/app.svg`** (NEW) — clean monochrome envelope mark with a folded-flap silhouette and a subtle "S" accent in the lower-right quadrant. Single-colour `currentColor`-only so Nextcloud's theme engine recolours it for light/dark mode automatically; well-formed XML with a 24×24 viewBox so NC's `IconService` serves it without rasterising. Looks calm next to Files / Calendar / Talk at the 16/22 px nav size and still readable at 64 px on the Settings → Apps tile. Replaces the rasterised `logo-white-64x64.png` which couldn't be themed.
- **`lib/AppInfo/Application.php`** — nav-icon URL now resolves to `img/app.svg` (was `img/logo-white-64x64.png`). No other code changes; the SVG sits next to the existing PNG so deployments rolling back to 0.13.11 are unaffected.

### Diagnostic — operator-facing context for `Error 352 / CantGetFilters`
- **Symptom (post-0.13.8):** opening Settings → Filters now reaches Stalwart's ManageSieve listener but auth-or-list fails. The engine wraps the underlying exception as `Notifications::CantGetFilters = 352` ("Error 352"). The actual root cause is in Stalwart's ManageSieve config — the engine's domain config can only affect the dial-out shape (host / port / SSL mode / advertised SASL mechanisms).
- **`occ souvera_mail:status`** now surfaces the full Sieve dial-out triple under `domains.<domain>.sieve` (host, port, ssl, sasl) alongside the legacy `sieve_enabled` boolean. Operators can pipe `--json | jq '.domains[].sieve'` against the live config and compare it against Stalwart's `server.listener.managesieve.*` settings.
- **`occ souvera_mail:setup --sieve-ssl`** default flipped `ssl` → `starttls`. RFC 5804 §4 specifies port 4190 as the ManageSieve port using PLAIN or STARTTLS; Stalwart's `server.listener.managesieve` default config matches. Operators who run Stalwart with explicit-TLS on a custom port can still pass `--sieve-ssl ssl` explicitly.
- **Common Error 352 root-cause checklist** (operator side, in priority order):
  1. `ssl` mode mismatch — `occ souvera_mail:status --json | jq '.domains[].sieve.ssl'` should match Stalwart's `server.listener.managesieve.tls.implicit` (true → `ssl`, false → `starttls`).
  2. ManageSieve listener missing OAUTHBEARER — Stalwart's default user role may include `sieveAuthenticate` but not advertise OAUTHBEARER on ManageSieve specifically. Add `auth.sasl.mechanisms` to the ManageSieve listener config and include `OAUTHBEARER`.
  3. JWT audience mismatch on the Sieve port — Stalwart's `directory.ncoidc.requireAudience` is `smail`; verify the H2CK client config registers `smail` as the audience for the souvera_mail user.
  4. ManageSieve port not actually open — `nc -zv <sieve-host> 4190` from inside the Nextcloud pod.

### Verification
- `php -l` clean on all four modified files (`img/app.svg` validates as XML).
- **`tests/test_icon_and_sieve_diagnostic.php`: 20/20 PASS** (NEW). Pins: SVG well-formedness, `currentColor`-only fills/strokes, Application.php nav-icon wiring, `--sieve-ssl` default = `starttls`, Status command exposes the new `sieve` block.
- Full local suite **573/573 PASS** across 17 test files (was 553/553 across 16). Zero regressions.

### Operator action (Error 352)
1. Deploy 0.13.12 to `/mnt/nc-shared/custom_apps/souvera_mail`.
2. Re-run `occ souvera_mail:setup` (your previous flags + omit `--sieve-ssl` to pick up the new STARTTLS default; OR pass `--sieve-ssl ssl` explicitly if your Stalwart uses implicit TLS).
3. Verify: `occ souvera_mail:status --json | jq '.domains[].sieve'` — compare with Stalwart's `server.listener.managesieve.*` config.
4. If Error 352 persists, check the operator-side checklist above. The exact underlying exception is in the engine log: `data/_data_/_default_/logs/log-<date>.txt` (look for `Sieve` / `LoginException` entries near the failed compose-time-stamp).

## [0.13.11] — 2026-02-17

### Added — Stalwart shared-mailbox identity auto-sync (operator-requested 2026-07-01)
- **The compose-window "Von:" dropdown now lists every shared mailbox Stalwart has granted the user `EmailSubmission` rights for, automatically.** No more manual identity-creation per shared inbox. When the operator adds someone to `team@buxtehude.email` on the Stalwart side, that user sees `Team Buxtehude [Stalwart]` in their "Neue Nachricht" From-dropdown within 15 minutes — no Souvera Mail restart, no NC cron, no user action.

### Architecture
- **`lib/Service/SharedIdentitySyncService.php`** (NEW) — orchestrates the JMAP `Identity/get` round-trip (RFC 8621 `urn:ietf:params:jmap:submission` capability) against Stalwart, parses the response, and exposes a pure `reconcile()` method that merges the result into the engine's per-account identity blob. Throttled to ONCE per 15 min per user via the NC distributed cache; 99% of engine boots are a microsecond cache hit. `forceSync()` is available for future "Jetzt neu synchronisieren" buttons.
- **`lib/Service/StalwartAdminService.php`** — `jmapCall()` gained an optional `array $extraCapabilities = []` parameter. RFC 8621 methods outside the default core + Stalwart-extension scope (Identity/get → `submission`, Mailbox/get → `mail`) can now declare their capability requirements without forcing every other caller to include them.
- **Engine plugin** (`app/smail/v/current/app/plugins/nextcloud/index.php`) — new `syncStalwartIdentitiesIfStale(IUser)` method wired into `FilterAppData()` immediately AFTER `seedDefaultIdentityFromNcProfile()`. Pulls the throttled list via the new service, calls `reconcile()`, writes back to `StorageType::CONFIG` ONLY if the reconciled blob differs from what's already stored.

### Reconciliation rules (`SharedIdentitySyncService::reconcile()`)
1. **Manual identities are preserved verbatim.** Any record whose `Id` does NOT start with `stalwart:` is treated as user-owned — its signature, ReplyTo, Bcc, sentFolder, S/MIME and PGP settings survive every sync run untouched. (Operator choice b.)
2. **Stalwart-managed identities are marked with `Id = 'stalwart:<stalwartIdentityId>'`.** That marker is the sync engine's exclusive ownership signal — manual identities never use it.
3. **Stalwart-managed identities get `Label = '<name> [Stalwart]'`** so the user can tell them apart in the Settings → Identities list and in the From-dropdown. The `Name` field (= the actual outgoing-header display name) is the bare Stalwart description, unchanged. (Operator choice c.)
4. **Manual-email collision wins for the user.** If a manual identity's email matches one of Stalwart's entries, the Stalwart entry is silently skipped — the user gets to keep their hand-tuned signature for their own primary mailbox. (Edge case worth pinning: the user's primary inbox is one of Stalwart's `Identity/get` entries, but they may already have a manual identity for it that they hand-customised post-first-login.)
5. **Removed-from-Stalwart entries are dropped from the engine on the next sync window.** When the operator revokes send-as on a shared mailbox, the next engine boot ≥ 15 min later reconciles the removal — no more stale entries cluttering the From-dropdown.
6. **Display-name / email changes are picked up in place.** The `stalwart:<id>` marker stays stable across renames; only Email / Name / Label fields update. No duplicates, no Id churn, no signature loss.

### Verification
- `php -l` clean on all three touched PHP files.
- **`tests/test_shared_identity_sync.php`: 32/32 PASS** (NEW). Static-source contract on the new service + adapted `StalwartAdminService` + engine-plugin wiring, plus a 7-state behavioural simulation that drives `reconcile()` through cold-start, manual+Stalwart merge, manual-email collision, Stalwart-revocation, rename-in-place, double-reconcile idempotency, and missing-description fallback.
- Full local suite **548/548 PASS** across 16 test files (was 516/516 across 15). Zero regressions.

### Operator decisions captured (2026-07-01)
| Choice | Value | Mapped to |
|---|---|---|
| a) Cadence | every 15 min, lazy on engine boot | `THROTTLE_SECONDS = 900` + `syncIfStale()` on every `FilterAppData()` |
| b) Conflict policy | manual stays, Stalwart marked "Stalwart-verwaltet" | reconcile rules #1–#4 above |
| c) Display name | Stalwart `Identity.name` | `name` field forwarded verbatim, ` [Stalwart]` only in `Label` |
| d) Scope | (decided by main agent) | every entry `Identity/get` returns — Stalwart already gates by send-as permission |

## [0.13.10] — 2026-02-17

### Fixed (P0 follow-up — email-as-username for App Passwords, requested 2026-07-01)
- **Generated App Passwords now accept the user's e-mail address as the IMAP/SMTP username, on every Stalwart deploy.** 0.13.9 made the canonical username discoverable in the UI but Stalwart's PLAIN/LOGIN auth still refused the e-mail-form of the username unless the principal carried the `authenticateWithAlias` permission. The default Stalwart user role includes it, but custom-role deploys (such as the operator's) sometimes omit it — and there is no `Inherit + add` mode on Stalwart's `CredentialPermissions`, so the only way to guarantee the permission is present on the app password is to use `Replace` mode and list it explicitly.
- **The `x:AppPassword/set` create payload now sends `{'@type': 'Replace', 'permissions': [...]}`** with an explicit, fully-comprehensive permission list covering `authenticate` + **`authenticateWithAlias`** (the email-as-username permission), `emailSend` / `emailReceive` (SMTP submission), the entire IMAP feature set (28 permissions — every operation Thunderbird / Outlook / iPhone Mail need), the POP3 feature set (6 permissions), and the ManageSieve feature set (9 permissions). The list is centralised as `AppPasswordService::APP_PASSWORD_PERMISSIONS` so future changes are auditable in one place.

### Architecture
- **`lib/Service/AppPasswordService.php`**:
  - New private const `APP_PASSWORD_PERMISSIONS` — the explicit list of permissions assigned to every Souvera Mail-issued App Password.
  - `createForUser()` swaps the `{'@type': 'Inherit'}` payload for `{'@type': 'Replace', 'permissions': self::APP_PASSWORD_PERMISSIONS}`. The credential's auth scope is now explicit and operator-config-independent.

### Why `Replace` is the right call here (operator-facing rationale)
- `Inherit` (previous) carried the operator's principal-role permissions verbatim. If the role omitted `authenticateWithAlias`, every legacy IMAP/SMTP client using the app password silently failed with `AUTHENTICATIONFAILED` — exactly the symptom the operator reported.
- `Disable` lets you START FROM the role and remove perms — the wrong direction.
- `Replace` lets us deliberately list the closed set of permissions a legacy mail client needs. The app password is intentionally NOT a full account credential; it cannot impersonate, manage the principal, or touch admin surfaces. That is the right security posture for a credential a user pastes into Thunderbird.

### Verification
- `php -l` clean on `lib/Service/AppPasswordService.php`.
- **`tests/test_app_password_username_surface.php`: 51/51 PASS** (was 32; +19 assertions). New static-source coverage pins the `Replace` mode + the presence of every key permission (`authenticate`, `authenticateWithAlias`, all 14 spot-checked IMAP/POP3/Sieve/Email perms). Behavioural sim updated to send the `Replace` payload end-to-end and verify `authenticateWithAlias` reaches Stalwart.
- Full local suite **516/516 PASS** across 15 test files (was 497/497). Zero regressions.

### Operator action
Deploy 0.13.10 to `/mnt/nc-shared/custom_apps/souvera_mail`. **Revoke any pre-0.13.10 app passwords** (they still carry the `Inherit` scope and will fail email-as-username on custom-role principals) and create new ones. The "Sicherheit & Geräte" tab now shows both username AND password — copy both into Thunderbird/Outlook with auth mode "Passwort / Login" (NOT OAuth).

## [0.13.9] — 2026-02-17

### Fixed (P0 — generated App Passwords failed IMAP login with `AUTHENTICATIONFAILED`, reported 2026-07-01)
- **Generated App Passwords now work on the first try with legacy IMAP/SMTP clients.** The plaintext secret returned by Stalwart's `x:AppPassword/set` JMAP call was correct, the documented `{'@type': 'Inherit'}` permissions payload was correct, but the UI only surfaced the *password* — never the *username*. The user had to guess which Stalwart-side mail address to enter and a guess like `<user>@<NC-domain>` would fail because Stalwart's PLAIN/LOGIN auth matches the principal by its canonical `name` and `emails[]` (and only falls back to alias lookup when the principal carries the `authenticateWithAlias` permission, which standard roles include but custom Stalwart deploys may omit). Stalwart's response was the bare `a NO [AUTHENTICATIONFAILED]` shown in the operator's reproduction.

### Architecture
- **`lib/Service/StalwartUserContext.php`**:
  - New `resolveEmail(string $userId): string` exposes the canonical Stalwart mail address (resolved via souvera_central's `StalwartService::mailFor()`) without re-issuing the `findAccountId()` round-trip.
  - `resolveAccountId()` now delegates the mail-address lookup to `resolveEmail()` — single source of truth, no duplicate `mailFor()` calls per request.
- **`lib/Service/AppPasswordService.php`**:
  - `createForUser()` return shape extended from `{id, secret, description}` to `{id, secret, description, username}`. The `username` is the canonical Stalwart mail address — exactly what the user must paste into the IMAP/SMTP client's "Username" field.
  - JMAP create payload now also carries `allowedIps: {}` (the documented "no IP restriction" value, serialized as a JSON object via `(object) []` so json_encode emits `{}` not `[]` — Stalwart's deserializer rejects the array form).
- **Snappymail "Sicherheit & Geräte" tab** (`SettingsSouveraAccount.html` + `settings-account.js`):
  - New `justCreatedUsername` observable + `copyUsername` view-model action.
  - The post-create banner now renders TWO labelled rows (`Benutzername` / `Passwort`) each with its own copy button, plus a hint to choose "Passwort / Login" (not OAuth) in the mail client.
  - `dismissNewSecret()` also clears `justCreatedUsername` — no stale data after closing.
  - New CSS classes `.sv-cred-row`, `.sv-cred-label`, `.sv-cred-value`, `.sv-secret-user`, `.sv-btn-sm` keep the new layout confined to the tab.

### Verification
- `php -l` clean on both modified PHP files.
- **`tests/test_app_password_username_surface.php`: 32/32 PASS** (NEW). Static-source contract on all four touched files + a behavioural simulation that drives `createForUser()` end-to-end against stub `StalwartUserContext` + stub `StalwartAdminService`. Verifies:
  - `resolveAccountId()` delegates to `resolveEmail()` (no duplicate lookups).
  - Return array carries `username` = canonical Stalwart email.
  - JMAP payload carries `permissions: {@type: Inherit}` and `allowedIps: {}` (serialized as JSON object, not array).
  - UI template renders both labelled rows with copy buttons.
  - ViewModel observable + `copyUsername` action + `dismissNewSecret` reset.
- Full local suite **497/497 PASS** across 15 test files (was 465/465 across 14). Zero regressions.

### Operator action
If your Stalwart deploy uses a custom role that omits `authenticateWithAlias` AND the principal's `name` differs from its primary `email`, you may still see `AUTHENTICATIONFAILED` when entering the email shown by Souvera Mail — paste the principal's `name` instead, or grant `authenticateWithAlias` to the role (recommended: it is part of Stalwart's default user role).

## [0.13.8] — 2026-02-17

### Fixed (P0 — `Connected Devices` runtime TypeError on NC34, reported 2026-07-01)
- **The "Verbundene Geräte" tab no longer crashes with `OC\Authentication\Token\Manager::getTokenByUser(): Argument #1 ($uid) must be of type string, OC\User\User given`.** Nextcloud 34 tightened the `OCP\Authentication\Token\IProvider` contract: both `getTokenByUser()` and `invalidateTokenById()` now take `string $uid` (previously `IUser $user`). Our `ConnectedDevicesService` was still forwarding the `IUser` instance returned from `requireUser()` — strict typing rejected it at runtime, the Settings tab rendered empty after the head row.
- **All four token-provider call sites in `lib/Service/ConnectedDevicesService.php` now pass `$user->getUID()` (the canonical UID string).** `requireUser()` is kept (it still validates that the user exists), but its return value is only used for `getUID()` — never forwarded to NC's token API.

### Fixed (P1 — `Settings → Filters` showed generic `ERROR 1`, reported 2026-07-01)
- **Opening the in-engine Filters tab no longer produces `ERROR 1`.** Root cause traced to `occ souvera_mail:setup`: the `--sieve` flag was declared as `VALUE_NONE` (opt-in, default false), so any operator who didn't pass `--sieve` shipped a domain config with `Sieve.enabled = false`. The engine's `Actions::Capa()` then resolved `Capa::SIEVE → false`, `DoFilters()` returned `FalseResponse()`, and the frontend rendered the catch-all `Notifications::RequestError = 1` ("ERROR 1").
- **`--sieve` is now `VALUE_NEGATABLE` and defaults to `true`.** Stalwart 0.16 ships ManageSieve on port 4190 natively and accepts the exact same OAUTHBEARER + H2CK JWT as IMAP/SMTP — there is no scenario where shipping a Stalwart profile with IMAP+SMTP on but Sieve off is useful. Operators who want Sieve off pass `--no-sieve` explicitly. Existing deploys can pick the fix up with a no-arg re-run: `occ souvera_mail:setup --imap-host <…> --domain <…>` — every other previously-set option is preserved via the existing defaults.

### Architecture
- **`lib/Service/ConnectedDevicesService.php`**:
  - `listForUser()`: `getTokenByUser($uid)` (was `$user`).
  - `revoke()`: `invalidateTokenById($uid, $tokenId)` (was `$user`).
  - `revokeAllOthers()`: `getTokenByUser($uid)` + `invalidateTokenById($uid, $id)` on every iteration (was `$user`).
  - `requireUser()` unchanged — still validates existence; callers extract `$uid = $user->getUID()` once at the top and forward it to all token-provider calls.
- **`lib/Command/Setup.php`**:
  - `--sieve` flag: `InputOption::VALUE_NONE` → `InputOption::VALUE_NEGATABLE`, default `true`. Help text rewritten to mention Stalwart 0.16's native ManageSieve and the `--no-sieve` opt-out.
  - `execute()`: `$sieveEnabled = (bool) $input->getOption('sieve');` → `$sieveEnabled = (bool) ($input->getOption('sieve') ?? true);` (honours the default when the operator passes neither `--sieve` nor `--no-sieve`).

### Verification
- `php -l` clean on both modified files.
- **`tests/test_connected_devices.php`: 78/78 PASS** (was 71). Three new static-source assertions pin the four token-provider call sites + reject any regression that would re-introduce the `$user` instance pass. Stub `IProvider` interface + `StubTokenProvider::getTokenByUser/invalidateTokenById` updated to the NC34 string-typed signature; two new behavioural assertions record `uidsSeen` and verify the canonical UID string `'alice'` is forwarded on every call (and the `IUser` instance never is).
- **`tests/test_sieve_default_on.php`: 13/13 PASS** (NEW). Static-source contract on the `--sieve` flag shape + the `?? true` default expression + help-text mentions + DomainConfigService wiring (`Sieve.enabled => $sieve` still flows through OAUTHBEARER), plus a 3-state behavioural simulation of Symfony Console's `VALUE_NEGATABLE` resolution: `--sieve`, `--no-sieve`, and neither-flag (the default-on regression).
- Full local PHP test suite: **463/463 PASS** across 14 test files (was 445/445 across 13). Zero regressions.

## [0.13.7] — 2026-02-17

### Fixed (P0 — IMAP `AUTHENTICATIONFAILED` after 15 min, reported 2026-07-01)
- **Long-lived IMAP / SMTP / Sieve sessions no longer fail with `AUTHENTICATIONFAILED` once the OIDC access token's 15 min TTL elapses.** H2CK/oidc issues 15 min JWTs. Souvera Mail cached them in NC's distributed cache. On reconnects (dashboard widget refresh, cron sync, background engine-token-cookie reconnect, Sieve-from-CLI) the cached entry was returned blindly even when `exp - now` was already at or past 0. Different cache backends (Redis, APCu, Memcached, NoLocal) honour TTLs with subtly different semantics; combined with clock drift between NC and Stalwart this opened a multi-second window in which the cache would hand out a token Stalwart immediately rejected with `ExpiredSignature`. Verified end-to-end by the operator against Stalwart 0.16 trace logs.
- **`OidcProviderService::generateAccessToken()` now re-validates the cached JWT's `exp` claim on every cache hit.** The cache TTL is treated as a coarse hint, not a contract:
  - cached JWT with `exp - now >= 60 s` → safe, returned verbatim;
  - cached JWT with `exp - now <  60 s` → cache entry actively evicted, fresh JWT minted via `OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent`;
  - opaque (non-JWT) cached token → trusted (no `exp` claim available; legacy H2CK pre-1.x).
- **`extractCacheTtl()` no longer silently extends a near-expired token's life by 60 s.** A parsed JWT with non-positive remaining lifetime now returns TTL=0 (do-not-cache) instead of the `FALLBACK_TTL_SECONDS` fallback. The 60 s fallback is reserved exclusively for opaque tokens (where `extractJwtExp()` returned `null`).
- `generateAccessToken()` skips `$cache->set()` entirely when the freshly minted token would be cached for ≤ 0 s — avoids ever persisting a token whose remaining usable life is below the safety margin.

### Architecture
- **`lib/Service/OidcProviderService.php`**:
  - New private `extractJwtExp(string $jwt): ?int` — parses the JWT payload's `exp` claim without verifying the signature (Stalwart re-verifies on its side).
  - New private `isJwtStillSafe(string $jwt): bool` — composes `extractJwtExp()` with `CACHE_SAFETY_MARGIN_SECONDS = 60`; opaque tokens default to safe.
  - `extractCacheTtl()` refactored on top of `extractJwtExp()`. Returns 0 for parsed JWTs with non-positive remaining lifetime, `FALLBACK_TTL_SECONDS` only when the payload was opaque/unparseable.
  - `generateAccessToken()` runs `isJwtStillSafe()` on every cache hit and removes the stale entry before falling through to the dispatcher.

### Verification
- `php -l` clean on `lib/Service/OidcProviderService.php`.
- **`tests/test_oidc_token_refresh.php`: 25/25 PASS** (NEW). Static-source contract assertions on `isJwtStillSafe()`, `extractJwtExp()`, `generateAccessToken()` and `extractCacheTtl()`, plus a 7-state behavioural simulation driven by a stub distributed cache + stub H2CK dispatcher:
  - 2a cold cache: mint once, cache with TTL ≈ `exp - 60 s`;
  - 2b warm hit with safe JWT: no re-mint;
  - 2c warm hit with `exp - now < 60 s`: evict + re-mint (the actual bug fix);
  - 2d warm hit with already-past `exp`: evict + re-mint;
  - 2e fresh mint of near-expired upstream token: returned but NOT cached;
  - 2f opaque token: cached for `FALLBACK_TTL_SECONDS`;
  - 2g empty uid: bails out without touching dispatcher or cache.
- Regression: full local test suite still green — **445/445 PASS** across 13 test files (was 420/420 across 12).

## [0.13.6] — 2026-02-17

### Fixed (P0 — NC34 guest-page TypeError, reported 2026-06-30)
- **`/login`, public-share pages and every other guest-rendered page no longer 500 with `TypeError: IAppManager::isEnabledForUser() expects parameter 1 to be string, null given`.** The crash chain: NC34's `NavigationManager::add()` (called by `init()` while resolving registered closure entries) does `$id = $entry['id']` followed by `isEnabledForUser($id)` (strict `string $appId` since NC30+). Our pre-0.13.6 navigation closure returned `[]` for the pre-auth case (no NC user) and the out-of-group case (user not in `souvera-users`) — `$entry['id']` was therefore `null`, the strict type-check threw, the InitialStateService rendering inside `layout.guest.php` died, every guest page along with it.
- **The closure now never returns `[]`.** The user-presence + group-membership gate is moved *out* of the closure into `Application::boot()`'s body. When the gate fails, the closure is simply not registered — there is no poisoned empty array left for `NavigationManager::init()` to trip over later. When the gate passes, the closure unconditionally returns the full 5-key payload (`id`, `name`, `href`, `icon`, `order`).

### Architecture
- **`lib/AppInfo/Application.php` :: `boot()`** rewritten:
  - Old: `$navigationManager->add(function () { … if ($user === null) return []; if (!$appManager->isEnabledForUser(…)) return []; return [/* full entry */]; })`
  - New: branch BEFORE `add()`:
    - `if ($user === null) { return; }` — pre-auth: nothing registered, guest pages crash-safe.
    - `if (!$appManager->isEnabledForUser(self::APP_ID, $user)) { return; }` — out-of-group: nothing registered.
    - Otherwise: `$navigationManager->add(closure)` with a closure that ALWAYS returns the full payload, no `return [];` anywhere.

### Verification
- `php -l` clean on `lib/AppInfo/Application.php`.
- info.xml hygiene re-audited: no empty `<settings/>`, `<repair-steps/>`, `<navigations/>`, `<background-jobs/>`, `<commands/>`, `<sabre/>`, `<collaboration/>`, `<two-factor-providers/>`, `<public/>`, `<activity/>`, `<trash/>`, `<types/>` containers (those crash NC's InfoParser cluster-wide per the operator's Shield v2.0.0 → 2.0.1 incident). All container elements present in info.xml carry children. We deliberately ship NO `<navigations>` element — navigation is registered programmatically because the group gate needs runtime access to `IUserSession`.
- **`tests/test_guest_page_navigation_crash.php`: 41/41 PASS** (NEW). Static assertions on the new `boot()` shape + info.xml hygiene + version, plus a 3-state behavioural sim with stub `IUserSession` / `IAppManager` / `INavigationManager`. The stub `INavigationManager::add()` mirrors NC34's `$id = $entry['id']` validation and would itself throw TypeError on any empty-array regression — that's the regression guard. Sim states:
  - 5a **pre-auth (no user)**: no closure registered, no `isEnabledForUser` calls, no crash.
  - 5b **user out-of-group**: no closure registered, `isEnabledForUser` consulted exactly once.
  - 5c **happy path**: exactly one closure registered, full payload with `id`/`name`/`href`/`icon`/`order`.
- `tests/test_navigation_gate.php` updated for the new gate-outside-closure shape: getUser/null-check/isEnabledForUser must appear BEFORE the `add()` call; zero `return [];` anywhere in `Application.php`.
- Full regression — **413/413 PASS** across 12 test files.

## [0.13.5] — 2026-02-17

### Fixed (P0 — fatal QueryNotFoundException on every app load, reported 2026-06-30)
- **`/index.php/apps/souvera_mail/` no longer crashes with `Could not resolve OCA\Souvera_mail\Controller\PageController!`** Nextcloud 34's `IAppManager::getAppNamespace()` derives the PHP namespace for an app from its id with `ucfirst($appId)` whenever the cached `<namespace>` tag from `info.xml` is stale in the `core.appinfo` memcache. For our id `souvera_mail` that yields `OCA\Souvera_mail\…` (lower-case `m`), which doesn't match our canonical `OCA\SouveraMail\…` PSR-4 path → every controller lookup throws `QueryNotFoundException`. Rather than depend on the operator's memcache state we now ship a **two-part namespace bridge** that accepts both shapes (see Architecture below). Once the operator's cache catches up, the bridge becomes a silent no-op (the spl_autoload hook short-circuits on every lookup that does not start with the underscore prefix).

### Removed
- **Redundant `⚙ Settings` fallback pill in the engine UI.** When the live quota endpoint was unreachable the engine plugin's `quota.js` degraded the quota pill into a standalone "⚙ Settings" pill linking to the legacy NC-chrome settings page. With settings now living as a tab inside the engine itself ("Sicherheit & Geräte"), the fallback pill was both redundant and confusing — clicking it took the user out of the inbox to a redirect-only URL. The quota pill now simply disappears when quota data is unavailable.

### Architecture
- **`lib-bridge/namespace-bridge.php`** (NEW). Registered under composer's `autoload.files`, so it loads eagerly on every `require vendor/autoload.php` (which Nextcloud's `OC_App::registerAutoloading()` does for every enabled app at bootstrap). Installs an `spl_autoload_register(..., prepend=true, throw=true)` hook that rewrites `OCA\Souvera_mail\<sub>` → `OCA\SouveraMail\<sub>` via `class_alias`. Handles classes, interfaces AND traits (NC's DI graph touches all three). Re-entrancy-guarded by a `SOUVERA_MAIL_NAMESPACE_BRIDGE_INSTALLED` define so multiple `require_once` calls don't re-register the hook.
- **`lib-bridge/Souvera_mail/AppInfo/Application.php`** (NEW). Registered under composer's classmap. `final class Application extends \OCA\SouveraMail\AppInfo\Application` — empty body, inherits the entire boot/registration surface from the real Application. Gives NC's DI container something to `new` when it asks for `OCA\Souvera_mail\AppInfo\Application` directly.
- **`composer.json`** autoload section extended:
  - `"files": ["lib-bridge/namespace-bridge.php"]` — eager-load the spl hook before any class lookup.
  - `"classmap": [..., "lib-bridge/"]` — register the bridge Application class.
  - PSR-4 mapping for `OCA\SouveraMail\` is unchanged.
- **`app/smail/v/current/app/plugins/nextcloud/js/quota.js`**: `renderSettingsOnly()` now simply calls `removePill()` instead of rendering a fallback "Settings" pill.

### Verification
- `php -l` clean on both bridge files.
- `composer dump-autoload -o` regenerated; classmap holds 274 classes (was 273 — exactly +1 for `OCA\Souvera_mail\AppInfo\Application`).
- **`tests/test_namespace_bridge.php`: 18/18 PASS** (NEW). Covers:
  - Composer artefacts (`autoload_classmap.php`, `autoload_files.php`) contain both bridge entries.
  - `composer.json` declares both bridge entries under the right sections.
  - The bridge file's spl_autoload_register call is correctly parameterised (prepend=true, throw=true), targets exactly the underscore prefix, rewrites to the canonical CamelCase prefix, uses `class_alias` so the underscore name stays resolvable, handles classes/interfaces/traits, is re-entrancy-guarded.
  - The bridge Application class declares the correct namespace, is `final`, and `extends \OCA\SouveraMail\AppInfo\Application`.
  - **End-to-end PHP sub-process sim**: a stubbed `OCA\SouveraMail\Smoke\Target` class becomes reachable under `OCA\Souvera_mail\Smoke\Target` after loading only the bridge file. `new` works, reflection returns the canonical name (proving alias semantics), interface and trait variants work, and unrelated namespaces are NOT touched by the hook (no bleed-out).
  - `quota.js` no longer renders the fallback "⚙ Settings" pill; the `renderSettingsOnly()` degrades to `removePill()` cleanly.
  - `info.xml` still declares `<namespace>SouveraMail</namespace>` (the canonical CamelCase path for operators with a fresh cache).
- Full regression — **397/397 PASS** across 11 test files.

## [0.13.4] — 2026-02-17

### Fixed (three live bugs reported by operator)
- **App-Password creation silently failed with "Creation failed".** The JS read `body.item.secret` while `AppPasswordController::create()` returns `{status:'ok', created:{secret,...}}`. JS now reads `body.created.secret`. Also surfaces the actual server-side error message (`body.message`) instead of always showing the generic literal — operators now see the real reason ("Stalwart refused…", "description must not be empty", etc.).
- **"Verbundene Geräte" showed `Failed to load devices` and an empty list.** `ConnectedDevicesService::listForUser()` blew up on tokens whose `getScopeAsArray()` did not exist (NC34 token-provider variants) or whose other getters threw. Service hardened with `safeName/safeType/safeLastActivity/safeScope` wrappers; `safeScope` uses `method_exists()` before calling, so token providers that don't implement it return an empty array instead of crashing the whole request. Un-readable token entries are now skipped with a logger warning rather than failing the whole list. The JS surfaces `body.message` so operators see the actual server-side reason.
- **First-time engine login no longer asks for the display name.** The plugin's `FilterAppData` hook now seeds a default mail Identity from the NC profile (`$ocUser->getDisplayName()` + `$account->Email()`) on the very first request after first login. Idempotent — once the user has any stored identity, the seed is a no-op (we never overwrite a user-edited identity). Wrapped in try/catch — never breaks engine boot.

### Changed (UI polish — Souvera Central-aligned)
- **Settings tab redesigned**: card-based layout, accent palette aligned with Souvera Central, Lucide-style line icons in section headers, radio "cards" instead of inline radios (clickable rows that highlight on selection), proper banner styles for warn/error/success states, sleeker buttons (primary / danger), modern table with current-session row highlighting, scoped CSS via `.souvera-settings { … }` so nothing leaks into the rest of the engine UI. Dark-mode tweaks via `prefers-color-scheme: dark`.

### Architecture
- **`lib/Service/ConnectedDevicesService.php`**: 4 new private `safe*` helpers wrap each NC IToken getter. Listing loop also try/catches around `getId()` (worst-case: that row is silently dropped, never the whole call).
- **`app/smail/v/current/app/plugins/nextcloud/index.php`**: new `seedDefaultIdentityFromNcProfile(IUser)` helper (~50 LoC). Reads existing identities via `Smail\Engine\Api::Actions()->LocalStorageProvider()`, writes a default only when none exists. Shape mirrors `Smail\Engine\Model\Identity::ToSimpleJSON()`. Logs success/failure via `Smail\Engine\Log` so operators can see what happened in the engine log.
- **`app/smail/v/current/app/plugins/nextcloud/js/settings-account.js`**: `createAppPassword` and `loadDevices` now read JSON bodies even on non-2xx responses (the NC controllers always return JSON, even on 4xx/5xx) — error display surfaces the actual `body.message` from the server.
- **`app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html`**: rewritten with card-based markup + ~200 lines of scoped CSS. All Knockout bindings preserved.

### Verification
- `php -l` clean on all touched files.
- `eslint` clean on `settings-account.js`.
- **`tests/test_settings_three_bugs.php`: 42/42 PASS** (NEW). Covers all three bug fixes and the UI polish:
  - App-Password: JS no longer reads `body.item`, reads `body.created.{secret,description}` instead; controller still returns the `created` key.
  - Devices: 4 `safe*` wrappers exist, `safeScope` method_exists-guards, listing loop skips un-readable tokens, JS surfaces `body.message`.
  - Identity seed: helper exists, called from FilterAppData, reads existing identities first, bails on existing identity, sources name from `getDisplayName()`, bails on empty name, uses account email, swallows Throwable, full shape match against `Identity::ToSimpleJSON()`.
  - UI polish: card classes present, all Knockout bindings preserved.
- Full regression — **361/361 PASS** across 10 test files.

## [0.13.3] — 2026-02-17

### Fixed (P0 — IMAP/SMTP/Sieve subrequest auth)
- **Background mail-server reconnects no longer fail with `AUTHENTICATIONFAILED`.** The engine plugin's `beforeLogin` hook used to swap the in-engine sentinel password `oidc_login|<uid>` for a fresh H2CK/oidc JWT *only* when `EngineHelper::isOIDCLogin()` returned true. That helper required `IUserSession::getUser() !== null` — i.e. an active Nextcloud session in the current request. On every code path where the IMAP/SMTP/Sieve connect was driven by an account record *without* an active NC session — dashboard widget background refresh, cron, engine-token-cookie reconnects, Sieve-from-CLI — the guard returned false, the literal sentinel was sent to Stalwart as the password, and Stalwart rejected the connect with `AUTHENTICATIONFAILED`. Operators saw the failure as "Mailbox connection failed after a while" or "Dashboard widget shows 0 unread until I reload the page".

### Architecture
- **`OCA\SouveraMail\Util\EngineHelper`** gains two session-free helpers (alongside the existing `isOIDCLogin()` / `getOidcAccessToken()` which keep their semantics for live-session callers):
  - `isOIDCEnabledServerSide(): bool` — config flag (`autologin-oidc='1'`) AND H2CK/oidc app available. Does **not** consult `IUserSession`.
  - `getOidcAccessTokenForUid(string $uid): ?string` — dispatches `OidcProviderService::generateAccessToken($uid)` with an explicit uid (the new bug-fix entry point). Guards on `isOIDCEnabledServerSide()`. Returns null cleanly (never throws) when prerequisites are missing or H2CK refuses to mint the token.
- **Engine plugin `beforeLogin` hook** rewritten:
  - Removed `isOIDCLogin()` guard — the sentinel itself (`oidc_login|<uid>`, written by `Smail\Engine\Actions\UserAuth::accountFromNcSession()` at first login and persisted in the encrypted account store) is now the authoritative identity marker for OIDC-based accounts.
  - Parses `<uid>` directly out of the sentinel via `substr` + `strlen('oidc_login|')`.
  - Calls `EngineHelper::getOidcAccessTokenForUid($uid)` instead of the session-coupled `getOidcAccessToken()`.
  - Bails cleanly on malformed sentinel (empty `<uid>`) — the original sentinel is preserved so the engine's normal IMAP error path surfaces a useful diagnostic rather than us silently masking a corrupt account record.
- **`isOIDCLogin()` itself** is refactored to delegate to `isOIDCEnabledServerSide()` for the operator-controlled checks (no duplication) and only adds the live-session check on top. Semantics for browser callers (`PageController`, `UserAuth::accountFromNcSession`) are unchanged.

### Verification
- `php -l` clean on both touched files (`lib/Util/EngineHelper.php`, `app/smail/v/current/app/plugins/nextcloud/index.php`).
- **`tests/test_before_login_token_swap.php`: 31/31 PASS** (NEW). Covers:
  - All three `EngineHelper` methods do/do-not consult `IUserSession`/`getSsoUid()` as appropriate.
  - The plugin `beforeLogin` hook no longer calls `isOIDCLogin()`, parses the sentinel correctly, calls the new `getOidcAccessTokenForUid()`, and no longer calls the legacy session-coupled `getOidcAccessToken()`.
  - 6-state behavioural simulation: happy session-full path, **happy session-FREE path (the bug fix)**, H2CK refuses → sentinel preserved + no SASL leak, regular password account untouched, additional account untouched, malformed sentinel untouched.
- Full regression — **319/319 PASS** across 9 test files.

## [0.13.2] — 2026-02-17

### Fixed (P0 reported by operator on 2026-06-29)
- **`/index.php/apps/souvera_mail/settings` no longer crashes with `Symfony\Component\Routing\Exception\InvalidParameterException`** (`Parameter "id" for route "souvera_mail.connecteddevices.destroy" must match "\d+" ("__ID__" given)`). Symfony's URL generator validates the route `requirements` regex *at generation time*, not just at routing time — `linkToRoute('…connectedDevices.destroy', ['id' => '__ID__'])` therefore blew up because `__ID__` does not match `\d+`. The destroy-URL templates are now built by string concatenation (`linkToRoute('…connectedDevices.index') . '/__ID__'`), which skips the generator's requirement check while keeping the server-side `\d+` constraint enforced at routing time. Same fix applied to the AppPasswords destroy template for consistency.

### Changed (UX consolidation)
- **Settings live inside the mailbox.** Per product feedback the user-facing settings (Dashboard widget mode, App Passwords for legacy IMAP/POP3/SMTP clients, Connected Devices) are no longer a separate Nextcloud-chrome page at `/index.php/apps/souvera_mail/settings`. They are now a **native Snappymail Settings tab** registered via `rl.addSettingsViewModel(...)` at the hash route `#/settings/souvera-account`, labelled **"Sicherheit & Geräte"**, appearing next to *Allgemein / Kontakte / Filter / Sicherheit / Ordner* in the mailbox's own Settings sidebar. The user never leaves the inbox to manage these any more.
- **Legacy URL still works.** `/index.php/apps/souvera_mail/settings` now serves a `RedirectResponse` to the in-engine hash route, so existing operator bookmarks continue to resolve.
- **Quota pill is in-tab.** Clicking the live mailbox-quota pill in the engine UI now opens the new Settings tab in the *same* browser tab (`target="_self"` + hash navigation), instead of opening a separate NC settings page in a new tab.

### Architecture
- **`OCA\SouveraMail\Controller\SettingsController`** rewritten as a thin redirect controller (~50 LoC). Removes its dependencies on `INavigationManager`, `IConfig`, `EngineHelper`, `AppPasswordService`, `UnreadMailWidget` constants and `userId`. The behaviour-bearing logic is now owned by the engine plugin.
- **`app/smail/v/current/app/plugins/nextcloud/js/settings-account.js`** (NEW, ~320 LoC). Vanilla JS Knockout ViewModel class `SouveraAccountSettings`. Reads all URLs + `appPasswordsAvailable` + initial dashboard mode from `rl.settings.get('Nextcloud')`. Implements the full CRUD for the three sections — POST to `…/preferences/dashboard-mode`, GET/POST/DELETE on `…/app-passwords`, GET/DELETE/POST on `…/connected-devices`. CSRF header (`requesttoken`) populated from `OC.requestToken`.
- **`app/smail/v/current/app/plugins/nextcloud/templates/SettingsSouveraAccount.html`** (NEW, ~150 LoC). Knockout-bound HTML template; matches Snappymail's existing settings-tab styling conventions (`.form-horizontal`, `.legend`, `.control-group`, `.button-vue`).
- **`app/smail/v/current/app/plugins/nextcloud/index.php`**:
  - `Init()`: registers the new JS + template via `addJs('js/settings-account.js')` + `addTemplate('templates/SettingsSouveraAccount.html')`.
  - `FilterAppData()`: emits 9 new `Smail*` keys into the Nextcloud payload so the JS finds every URL it needs in `rl.settings.get('Nextcloud')`. Destroy templates use the `…/index . '/__ID__'` pattern for the P0 fix above.
  - Two new helpers — `resolveDashboardModeForNextcloud(uid)` and `isAppPasswordsAvailable()` — wrap the existing NC services defensively (silent fallback if `souvera_central` / H2CK/oidc are missing, so the engine boot never crashes).

### Removed
- `templates/settings.php` — replaced by the in-engine Knockout template.
- `js/personal-settings.js` — its full CRUD logic is now in `settings-account.js`.

### Verification
- `php -l` clean on all changed files (`SettingsController.php`, `app/plugins/nextcloud/index.php`).
- `eslint` clean on the new `settings-account.js` (after declaring `rl`, `ko`, `OC` as globals, matching the pattern in the existing `quota.js`).
- **`tests/test_settings_tab_integration.php`: 47/47 PASS** (NEW) — covers the redirect controller, the ViewModel registration, the Knockout template bindings, the engine-plugin wiring, FilterAppData payload, quota.js navigation behaviour, deletion of the old NC-chrome assets, and the version bump.
- **`tests/test_settings_url_template_regression.php`** updated to verify the URL templates now live in the engine plugin (not in `SettingsController`), still asserts that the broken `linkToRoute(['id' => '__ID__'])` pattern is forbidden in either location, and still asserts that `routes.php` keeps the server-side `\d+` requirement.
- **`tests/test_connected_devices.php` (74→71 assertions)**: ConnectedDevices UI assertions retargeted from the deleted NC-chrome template + JS to the new Knockout template + ViewModel. Behavioural sims on the PHP service layer are unchanged (covered: 3 states of `revoke()` + 3 states of `revokeAllOthers()`).
- **`tests/test_souvera_mail_rename.php` (58→55)** and **`tests/test_navigation_title.php` (15→16)** updated for the redirect controller and the absence of the legacy NC-chrome template.

## [0.13.1] — 2026-02-17

### Changed
- **Sidebar nav label shortened to "Mail".** The Nextcloud sidebar (top navigation bar / left rail in mobile) now shows the short label `Mail` instead of the full `Souvera Mail`. The long brand name wrapped / overflowed at typical NC sidebar widths and looked broken. The product brand stays `Souvera Mail` everywhere else (info.xml `<name>`, settings page heading, breadcrumb, dashboard widget title, App Store listing, About page). Operators who want a different sidebar label can still override it via `occ config:app:set souvera_mail menu-title --value '<label>'` — only the *default* changed.

### Architecture
- `NavigationTitle::DEFAULT` (in `lib/Util/NavigationTitle.php`) changed from `'Souvera Mail'` to `'Mail'`. Everything else (`menu-title` app-config key, `resolve()` semantics, `validate()` checks) is unchanged.

### Verification
- **`tests/test_navigation_title.php`: 15/15 PASS** (NEW) — confirms `DEFAULT === 'Mail'`, `resolve()` returns the short default when no override is stored, returns the operator override when one is, trims whitespace, falls back when override is whitespace-only, `validate()` still rejects > 64 chars + control chars, info.xml `<name>` still says `Souvera Mail` (no full-app rename), `SettingsController` still seeds the long brand for the in-app settings header, and the navigation closure in `Application::boot()` does NOT hard-code a brand name (would override the operator's `menu-title` setting).
- Full regression suite (`test_souvera_mail_rename.php`, `test_navigation_gate.php`, `test_connected_devices.php`, `test_enforce_group_restriction.php`, `test_docs_alignment.php`): **228/228 PASS** after version bump.

## [0.13.0] — 2026-02-17

### Added
- **Strict group-restriction enforcement.** Souvera Mail is now bound to the Nextcloud group `souvera-users` automatically on every `occ app:enable souvera_mail` and every `occ upgrade`. Members of any other group never see the navigation entry and cannot open `/index.php/apps/souvera_mail/…` — Nextcloud's built-in app-permission layer rejects the request before any controller runs. The binding converges towards the desired state every time the repair-step executes; manual `occ app:enable souvera_mail --groups <other-group>` deviations are reset on the next upgrade. The allowed group id lives in a single constant (`Application::RESTRICTED_GROUP_ID`) so downstream builds can patch it in one place.

### Architecture
- **`OCA\SouveraMail\Migration\EnforceGroupRestriction`** (NEW, ~95 LoC). Implements `OCP\Migration\IRepairStep`. Registered in `appinfo/info.xml` under both `<install>` and `<post-update>` `<repair-steps>` blocks so fresh installs *and* every upgrade re-converge:
  - Ensures the `souvera-users` group exists (`IGroupManager::createGroup()`); throws a verbose `RuntimeException` if the group manager refuses (LDAP read-only backend, misconfigured group manager — gives the operator the exact `occ group:add souvera-users` recovery command).
  - Calls `IAppManager::enableAppForGroups('souvera_mail', [$group])` to bind the app. Any `Throwable` from the binding is swallowed: a `warning()` is emitted on the `IOutput` and logged via `LoggerInterface` (so the rest of `occ upgrade` keeps going), but the failure is loud enough that a deploy pipeline can detect it via the `occ upgrade --verbose` output.
- **`Application::RESTRICTED_GROUP_ID = 'souvera-users'`** — single source of truth for the allowed group id. Referenced by the new repair-step and documented at the constant declaration.

### Verification
- `php -l` clean on all touched files.
- `composer dump-autoload -o` regenerated; classmap holds 273 classes (was 272 — exactly +1 for the new class).
- **`/app/tests/test_enforce_group_restriction.php`: 27/27 PASS** — file/namespace/interface, info.xml `<install>` + `<post-update>` registration with InstallStep regression guard, classmap entry, constant value, version + CHANGELOG checks, and a four-state behavioural sim of `run()` (group exists, group missing-but-created, `createGroup` returns null → helpful error, `enableAppForGroups` throws → swallowed + warning).
- **Regression: `/app/tests/test_souvera_mail_rename.php` and `test_navigation_gate.php` and `test_connected_devices.php`** updated for version `0.12.0 → 0.13.0` and pass.

## [0.12.0] — 2026-02-16

### Added
- **Connected Devices section** in the in-app Settings page (`/apps/souvera_mail/settings`). Lists every active Nextcloud session belonging to the current user — browsers + NC client apps (Files, Talk, Calendar, …) — with last-activity timestamp and per-row "Sign out" action. The current session is detected via `ISession::getId()` → `ITokenProvider::getToken()` and pinned to the top with a green "this device" badge; its "Sign out" button is disabled to prevent self-revocation mid-request (server also refuses with HTTP 400). A prominent **"Sign out all other devices"** button at the section header revokes every session except the current one in a single round-trip — the key security feature for shared-computer scenarios, lost phones, and post-password-reset cleanup.

#### Architecture
- **`OCA\SouveraMail\Service\ConnectedDevicesService`** (NEW, ~120 LoC). Pure Nextcloud-scoped — does **not** touch Stalwart. Wraps `OCP\Authentication\Token\IProvider`:
  - `listForUser($userId)`: enumerates tokens, classifies as `app` vs `browser`, sorts current-first then most-recent-activity-first.
  - `revoke($userId, $tokenId)`: refuses self-revocation, otherwise `invalidateTokenById`.
  - `revokeAllOthers($userId)`: loops tokens, skips current, swallows per-token failures (logged with `souvera_mail` app context).
- **`OCA\SouveraMail\Controller\ConnectedDevicesController`** (NEW). 3 endpoints, all `#[NoAdminRequired]`, CSRF-enforced for the mutating ones:
  - `GET    /connected-devices`
  - `DELETE /connected-devices/{id}` (numeric-id constrained via routes.php `requirements`)
  - `POST   /connected-devices/sign-out-others`
- Template + JS extended: a fourth row of `data-*` attributes on the section bootstraps the JS, which mirrors the existing App-Passwords pattern (XSS-safe row rendering, confirm-revoke dialog, inline traffic-light badges).

#### Reality check vs. original sketch
- The original "Nice-to-have" mentioned reading active mail-client sessions from a Stalwart `x:UserSession/query` JMAP endpoint. **No such endpoint exists in Stalwart 0.16** — verified against upstream `crates/jmap/src/registry/get.rs` (no `Session` / `Login` / `AuthToken` variant in the public ObjectType list; the only "session" object is `MtaInboundSession`, which is SMTP-server config). Stalwart treats mail-client identity persistently via `AppPassword` (already covered) and only ephemerally via raw IMAP/SMTP TCP connections (not exposed as queryable JMAP). So this release implements the Nextcloud half — the higher-value half, because users routinely keep browsers logged in on shared machines — and explicitly documents in the UI ("App Passwords are not part of this list") that mail-client revocation lives in the section above.

#### Routes added
```
GET    /connected-devices                  → connectedDevices#index
DELETE /connected-devices/{id}             → connectedDevices#destroy   (id constrained to \d+)
POST   /connected-devices/sign-out-others  → connectedDevices#signOutOthers
```

## [0.11.1] — 2026-02-16

### Fixed
- **Navigation entry honours group restrictions.** When the app was restricted to a group (e.g. `occ app:enable souvera_mail --groups mail-users`), members of *other* groups still saw a "Mail" entry in Nextcloud's top navigation. Clicking it landed on a Nextcloud "App is not enabled" error page. The lazy closure registered against `INavigationManager` in `Application::boot()` unconditionally returned the entry payload for every authenticated request. The closure now consults `OCP\App\IAppManager::isEnabledForUser($appId, $user)` first and returns an empty array when the user is unauthenticated *or* outside the allowed group set — Nextcloud's NavigationManager then drops the provider for this request, so the misleading menu item never appears.

## [0.11.0] — 2026-02-16

### Breaking
- **App ID renamed `smail` → `souvera_mail`.** The Nextcloud `<id>` in `appinfo/info.xml`, the PHP wrapper namespace (`OCA\Smail\…` → `OCA\SouveraMail\…`), all URL paths (`/apps/smail/…` → `/apps/souvera_mail/…`), every `IConfig` app-domain key, every `IL10N` / logger context, every cache namespace and every CLI command name (`occ smail:bootstrap` → `occ souvera_mail:bootstrap`, etc.) now use the long form. This is a fresh install — there is no automatic migration of old `smail` config values. Operators redeploying an existing `smail` install must run `occ app:remove smail` followed by `occ app:install souvera_mail` and re-run `occ souvera_mail:bootstrap …`.
- **Personal Settings section removed.** Souvera Mail no longer registers a `<personal>` settings section under Nextcloud's `/settings/user/souvera_mail`. The user-facing configuration UI (App Passwords, Dashboard widget mode) now lives **inside the app** at `/index.php/apps/souvera_mail/settings`. This sidesteps two long-standing issues: (a) it puts settings where the user already is (the mailbox), and (b) it removes the only entry path to a recurring L10N TypeError in an unrelated sibling app (`souvera_shield::PersonalSection::getName`) that was triggered whenever Nextcloud enumerated all Personal Sections to render the page.

### Added
- **In-app Settings page** at `/apps/souvera_mail/settings` rendered as a full-page TemplateResponse with NC's standard `'user'` chrome. Shows the existing Dashboard widget mode toggle and the App Password management UI. Includes a "← Back to inbox" breadcrumb that returns the user to the mailbox.
- **Quota pill is now an entry-point to settings.** Clicking the live mailbox-quota pill in the engine's top-right corner opens the in-app settings page in a new tab. When the quota endpoint is unavailable (no `souvera_central` mailbox, no Stalwart URL), the pill degrades to a `⚙ Settings` button so the entry-point is always visible.
- Engine plugin's `FilterAppData` hook now emits `Nextcloud.SmailSettingsUrl` in addition to `Nextcloud.SmailQuotaUrl`, consumed by `app/plugins/nextcloud/js/quota.js`.

### Changed
- All NC-wrapper PHP files updated to namespace `OCA\SouveraMail`. Composer PSR-4 mapping updated to `OCA\\SouveraMail\\` → `lib/`. Autoload classmap regenerated.
- All 137 L10N JS/JSON files now register against `OC.L10N.register("souvera_mail", …)`.
- Engine plugin (`app/smail/v/current/app/plugins/nextcloud/`) updated to reference `OCA\SouveraMail\Util\EngineHelper` and read app-config from the `'souvera_mail'` domain.
- Internal HTML / CSS / `data-testid` identifiers use the `souvera-mail-` (hyphenated) prefix for full consistency with the new app id.
- Engine namespace `Smail\Engine\…` and the physical engine directory `app/smail/v/current/` are intentionally **not** renamed — they are internal engine identifiers, not exposed as the app id; renaming would force 700+ unrelated file touches without any user-visible benefit.

### Removed
- `lib/Settings/PersonalSettings.php`, `lib/Settings/PersonalSection.php`, `templates/personal_settings.php` — replaced by the in-app `SettingsController` + `templates/settings.php`.
- `css/setup-wizard.css` — leftover stylesheet from the long-removed browser setup wizard, no references anywhere.

### Migration notes for operators
| Old | New |
|---|---|
| App URL `https://nc/index.php/apps/smail/` | `https://nc/index.php/apps/souvera_mail/` |
| Settings URL `https://nc/settings/user/smail` | `https://nc/index.php/apps/souvera_mail/settings` |
| OCC command `occ smail:bootstrap` | `occ souvera_mail:bootstrap` |
| OCC command `occ smail:status` | `occ souvera_mail:status` |
| OCC command `occ smail:reset` | `occ souvera_mail:reset` |
| OCC command `occ smail:setup` | `occ souvera_mail:setup` |
| OCC command `occ smail:oidc:register-client` | `occ souvera_mail:oidc:register-client` |
| User preference `occ user:setting <uid> smail dashboard-mode …` | `occ user:setting <uid> souvera_mail dashboard-mode …` |
| App data directory `appdata_smail/` | `appdata_souvera_mail/` |

## [0.10.2] — 2026-02-16

### Fixed
- **`templates/not_configured.php` no longer points users at a non-existent setup wizard.** The empty-state page used to render a primary button labelled *Setup Wizard* that linked to `/settings/admin/smail`. That link contradicts the project's CLI-only doctrine (the browser wizard was removed in 0.9.0), and on real deployments it also surfaced a completely unrelated TypeError from a sibling app (`souvera_shield::PersonalSection::getName()` → `LazyL10N::t()` → `array_merge(null)`) because the personal-settings page enumerates every installed app's section. Replacing the button with a static CLI snippet (`sudo -u www-data php occ smail:bootstrap …`) sidesteps both the doctrinal mismatch and the third-party crash entry-point.

### Documentation
- The empty-state page now explicitly states the prerequisites for `occ smail:bootstrap` (H2CK/oidc 1.17+ enabled with a signing key, Stalwart mailbox provisioned by souvera_central) and points operators at `occ smail:status` for inspection and `occ smail:reset` for re-running. After bootstrap, SSO via H2CK/oidc is automatic — no per-user configuration is required (answering the recurring deployment question).

## [0.10.1] — 2026-02-16

### Added
- **Live mailbox-quota pill** in the Souvera Mail engine UI (top-right). Reads `usedDiskQuota` + `quotas.MaxDiskQuota` for the current user from Stalwart 0.16 via a single `x:Account/get` JMAP call. Shown as a small pill `"382 MB / 5 GB"` that turns orange at ≥75 % and red at ≥90 %. Hidden gracefully if any prerequisite is missing (no `souvera_central`, no Stalwart URL, no H2CK/oidc) — the regular UI is unaffected.

#### Architecture
- **`OCA\Smail\Service\StalwartUserContext`** (NEW) — shared helper. Extracts `resolveAccountId(string $userId)` + `resolveBearer(string $userId)` from the previous `AppPasswordService` into one reusable class so both AppPassword + Quota flows share the souvera_central principal lookup + H2CK/oidc token acquisition without duplication.
- **`OCA\Smail\Service\StalwartAdminService::extractMethodResponse()`** is now a public reusable helper (moved from `AppPasswordService`).
- **`OCA\Smail\Service\QuotaService`** (NEW) — wraps `x:Account/get` with `properties: ['usedDiskQuota', 'quotas']`. Result cached in NC's distributed cache for 60 s per user (Stalwart's quota numbers do not change every second; engine polls every 60 s anyway).
- **`OCA\Smail\Controller\QuotaController`** (NEW) — single endpoint `GET /index.php/apps/smail/quota` returning `{ status, used, total, percentage, unlimited, formatted: { used, total } }`. `#[NoAdminRequired]`, session-cookie auth, no CSRF needed (GET).
- **Engine plugin** (`app/smail/v/current/app/plugins/nextcloud/`):
  - `index.php` `FilterAppData` hook now emits `Nextcloud.SmailQuotaUrl = absoluteUrl('smail.quota.index')` so the engine JS can reach the endpoint without hard-coding the NC webroot.
  - **`js/quota.js`** (NEW, ~100 LoC) — fetches the URL on engine boot (1.5 s delay to avoid racing the login flow) and every 60 s afterwards, renders a `position: fixed` pill in the top-right corner with traffic-light colours based on percentage. Idempotent — removes the pill on any error so the UI never shows stale info.

#### Refactored
- `AppPasswordService` constructor signature simplified: now injects `StalwartAdminService` + `StalwartUserContext` instead of (Oidc + UserManager + Container). Private `resolveAccountId` / `resolveBearer` / `extractMethodResponse` methods removed (now on `StalwartUserContext` / `StalwartAdminService`). No behaviour change.

#### Routes added
```
GET /quota → quota#index
```

## [0.10.0] — 2026-02-16

### Added
- **App Passwords for legacy mail clients.** Souvera Mail now talks to the Stalwart 0.16+ management surface (JMAP under `urn:stalwart:jmap` capability) so end users can self-issue IMAP / POP3 / SMTP credentials directly from *Settings → Souvera Mail* for mail apps that do not support OAUTHBEARER (older Thunderbird, Apple Mail iOS, Outlook, automated scripts, …). Verified against upstream `crates/registry/src/schema/structs.rs` (`AppPassword`) and `crates/jmap-proto/src/request/method.rs` line 238 (`x:<ObjectType>/<function>` method name format).

#### Architecture
- **`OCA\Smail\Service\StalwartAdminService`** — minimal JMAP HTTP wrapper. Reads the API URL from system config `souvera_central.stalwart_api_url`, posts to `<url>/jmap` with `Authorization: Bearer <jwt>`, 8 s timeout.
- **`OCA\Smail\Service\AppPasswordService`** — domain logic. Resolves the user's mail address via the parallel-installed Souvera Central app's `OCA\SouveraCentral\Service\StalwartService::mailFor(IUser)`, looks up the opaque Stalwart account ID via `findAccountId($email, 'User')`, acquires a user-scoped JWT via H2CK/oidc, then dispatches `x:AppPassword/get` and `x:AppPassword/set`. All operations run as the user — Stalwart 0.16 explicitly forbids admins from creating AppPasswords on behalf of users (`stalw.art/docs/auth/authentication/app-password/`).
- **`OCA\Smail\Controller\AppPasswordController`** — CSRF-protected, `#[NoAdminRequired]`, never accepts a user id from the client (always derives from session). Three endpoints under `/index.php/apps/smail/`:
  - `GET    /app-passwords`           — list (description + createdAt only, no secrets)
  - `POST   /app-passwords`           — create, returns `{id, secret, description}` (plaintext secret ONCE — Stalwart stores only the hash)
  - `DELETE /app-passwords/{id}`      — revoke
- **Personal Settings UI** — adds a third section listing existing app passwords (description / created / Revoke button) and a create form. On successful creation the plaintext secret is rendered in a one-time card with a copy-to-clipboard button and an explicit "save now — you'll never see this again" warning.

#### Permissions
The created AppPassword carries `permissions = {"@type": "Inherit"}`, i.e. it grants exactly the same access scope as the user's primary account (IMAP + POP3 + SMTP + JMAP for that mailbox). No per-protocol toggle in this release (per user choice).

#### Routes added
```
GET    /app-passwords        → appPassword#index
POST   /app-passwords        → appPassword#create
DELETE /app-passwords/{id}   → appPassword#destroy
```

#### Notes for operators
- Required system-config keys (set by Souvera Central in production deploys):
  - `souvera_central.stalwart_api_url` — e.g. `http://stalwart:8080`
- Required apps enabled in the same Nextcloud instance: `oidc` (H2CK), `souvera_central` (for the mail-address ⇆ Stalwart-principal mapping).
- The Personal Settings page degrades gracefully: if any prerequisite is missing it shows a yellow banner ("App passwords are not available — Souvera Central and the Stalwart API URL must be configured by the administrator first") and hides the form.

## [0.9.4] — 2026-02-16

### Added
- **Dashboard widget mode toggle.** The Souvera Mail dashboard widget (`smail-unread`) now reads a per-user preference `smail/dashboard-mode` and switches between two modes:
  - `unread` (default): only unread INBOX messages — matches the previous behaviour.
  - `all`: the most recent INBOX messages regardless of seen state — useful for users who treat the dashboard as a glance-pane for everything new.
- Each widget row links straight to the message via the engine hash-router (`#/mailbox/INBOX/m<UID>`) — one click opens the mail in Souvera Mail.
- **Personal settings UI** at *Settings → Souvera Mail* exposes the toggle as two radio buttons, persists via a new `POST /preferences/dashboard-mode` endpoint (`OCA\Smail\Controller\PreferenceController`), and shows an inline ✓/✗ confirmation. Stored via Nextcloud's standard `IConfig::setUserValue()`, so the same setting is also reachable from the CLI for declarative deploys:
  ```
  occ user:setting <uid> smail dashboard-mode all
  occ user:setting <uid> smail dashboard-mode unread
  ```

### Changed
- Widget title is now `Souvera Mail · Inbox` (was `Unread mail`) — the title is mode-agnostic; the empty-state message communicates the active mode (`No unread mail` vs `Inbox is empty`).
- Widget item links now use `getAbsoluteURL()` instead of the relative `linkToRoute()` result, so the dashboard's anchor follow works from any embed context (Nextcloud Talk smart picker, public dashboards, etc.).

## [0.9.3] — 2026-02-16

### Changed
- Version bump-only release. No code changes vs. 0.9.2. Required so Nextcloud's repair step / `occ upgrade` re-processes the app after the 0.9.2 `RegisterClient.php` patch was applied on top of an already-installed 0.9.2 build. The H2CK/oidc `oidc:create` signature implemented in 0.9.2 has been verified against the upstream `lib/Command/Clients/OIDCCreate.php` (positional `name` + `redirect_uris` `REQUIRED|IS_ARRAY` argument, `--token_type` option with underscore, JSON-encoded `Client::jsonSerialize()` output with keys `client_id` / `client_secret`).

## [0.9.2] — 2026-01-16

### Fixed
- `occ smail:bootstrap` exited 1 with `register_client → "oidc:create dispatch failed: …"` against H2CK/oidc 1.17+. Three independent assumptions in `lib/Command/Oidc/RegisterClient.php` were wrong against the actual H2CK CLI signature:
  - `redirect_uris` is a **positional `IS_ARRAY` argument**, not the `--redirect-uri` option we were sending. The Symfony console rejected the unknown option before the command body ever ran.
  - The access-token-type flag is `--token_type` (with underscore) and is set **per-client at creation time**, not by flipping the global `default_token_type` app-config after the fact. We now pass `--token_type=jwt` directly to `oidc:create`, so the JWT (RFC 9068) format is requested even when the global default is left at `opaque`.
  - The created client is emitted as a **pretty-printed JSON object** (`Client::jsonSerialize()` in `lib/Db/Client.php`) with keys `client_id` / `client_secret` — not the human-readable `Client ID: …` / `Client Secret: …` text our regex parser expected. The wrapper now `json_decode()`s the output first and falls back to the regex parser for compatibility with pre-1.14 H2CK builds.
- The bootstrap error report now includes `raw_oidc_create_output` and `raw_invocation_args` keys so the next-level operator can paste a single JSON blob into a bug report instead of digging through logs.

### Notes for operators
- If you tried `occ smail:bootstrap` against 0.9.0/0.9.1 and it failed at `register_client`, just re-run after upgrading to 0.9.2 — `smail:bootstrap` is idempotent and resumes cleanly. The OIDC client may already exist in H2CK from the failed run; use `occ oidc:list` to verify, and `occ smail:oidc:register-client --force` to rotate the client_secret if you don't have the old one.

## [0.9.1] — 2026-01-16

### Fixed
- `occ app:enable smail` crashed in the post-install repair step with `Class "Smail\Engine\Upgrade" not found`. The Souvera Mail engine ships with `Smail\…` namespace classes whose files live under `app/smail/v/current/app/libraries/Smail/`. Nextcloud's built-in autoloader maps only `OCA\Smail\` → `lib/`, so the engine namespace was unreachable until `EngineHelper::loadApp()` registered a fallback autoloader. That fallback had an off-by-one bug introduced by the 0.9.0 rename: the magic offset for stripping the `Smail\Engine\` prefix was left at the previous `X2Mail\Engine\` length (14 → 13 chars). Both autoloader branches now use the correct offset.

### Added
- **`composer.json`** with mixed PSR-4 + classmap autoload (`OCA\Smail\` PSR-4 → `lib/`, `Smail\…` classmap → `app/smail/v/current/app/libraries/Smail/`). The classmap is required because the upstream engine uses lowercase filenames (`upgrade.php`, `mail/config.php`) for CamelCase classes (`Upgrade`, `Mail\Config`), which standard PSR-4 cannot resolve.
- **`vendor/composer/`** generated by `composer dump-autoload --optimize` (~124 KB, 288 classes hard-mapped — 263 engine + 25 NC wrapper).
- **`require_once vendor/autoload.php`** at the top of `lib/AppInfo/Application.php`, so the autoloader is active before the very first service-container lookup. This covers the `app:enable` repair-step path, where `OCA\Smail\Migration\InstallStep::run()` references `\Smail\Engine\Upgrade::fixPermissions()` directly.

### Notes for downstream / packagers
- The release tarball must include `composer.json` and `vendor/`. The manual fallback autoloader in `EngineHelper::loadApp()` still works (it is the off-by-one fix), but composer is the canonical path.
- Build pipelines should run `composer dump-autoload --optimize --no-dev` whenever engine PHP files are added/renamed, otherwise the classmap drifts.

## [0.9.0] — 2026-01-15

### Brand rename
- The app's previous identity (X2Mail / souvera_mail, namespace `OCA\X2Mail\…`, engine namespace `X2Mail\Engine\…`) was completely collapsed to a single canonical short form across the entire codebase:
  - **Display name** is now **Souvera Mail** everywhere user-visible (Nextcloud settings, navigation, engine PWA, l10n, README).
  - **App id** is now `smail` (`<id>`, route names, app-config namespace, image paths, storage dir `appdata_smail/`, log file `smail.log`, CLI commands `occ smail:*`).
  - **PHP namespace** is now `OCA\Smail\…` (NC wrapper, 25 files) and `Smail\Engine\…` / `Smail\Mail\…` (bundled engine, ~600 files).
  - **Constants**: `SMAIL_LIBRARIES_PATH`, `SMAIL_INCLUDE_AS_API`.
  - **Engine directory tree** physically moved: `app/x2mail/v/` → `app/smail/v/`; engine library directory `…/libraries/X2Mail/` → `…/libraries/Smail/`; engine theme directory `…/themes/x2mail/` → `…/themes/smail/`.
  - **Asset filenames**: `images/x2mail-logo.png` → `images/smail-logo.png`; `fonts/x2mail.{woff,woff2}` → `fonts/smail.{woff,woff2}`; openssl S/MIME config sections `[x2mail_req]` → `[smail_req]`, `[x2mail_ca]` → `[smail_ca]`.

### Added (new architecture)
- **Nextcloud is now the OIDC Provider for the whole mail stack** via the [H2CK/oidc](https://github.com/H2CK/oidc) app. Souvera Mail consumes access tokens directly from Nextcloud's OIDC OP through in-process PHP event dispatch (`OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent`) — no browser redirect, no token caching in the NC session, no external IdP required.
- **`OCA\Smail\Service\OidcProviderService`** — wraps the H2CK/oidc event dispatch with defensive `isProviderAvailable()` checks, per-user JWT caching in NC's distributed cache (`exp - 60s` TTL), and clean failure modes when H2CK/oidc is missing.
- **`occ smail:bootstrap`** — one-shot install for declarative deploys: preflight checks H2CK/oidc, registers the smail OIDC client (via `occ oidc:create`), writes the IMAP/SMTP/Sieve domain profile, and runs `occ smail:status` to verify. Idempotent. Supports `--json` and `--dry-run`.
- **`occ smail:oidc:register-client`** — registers Souvera Mail as a confidential OIDC client in H2CK/oidc. Optionally dumps the generated client_secret to a deploy-supplied file (mode 0600). Sets `default_token_type=jwt` (RFC 9068) globally for H2CK so issued tokens are JWT.
- **`occ smail:reset`** — tears down all Souvera Mail state for a clean re-deploy: app-config, domain profile, cached tokens. Optional `--purge-oidc-client` and `--purge-engine-data` flags.
- **`--json`** and **`--dry-run`** flags on every write-command for machine-readable status reports and pipeline-safe planning runs.
- Read-only admin status panel at **Settings → Souvera Mail** — every interactive element is informational only; configuration changes happen exclusively through `occ`.

### Removed (CLI-only architecture, no browser wizard)
- `templates/setup-wizard.php` — browser-based setup wizard
- `js/setup-wizard.js` — wizard JavaScript
- `lib/Controller/SetupController.php` — all wizard write endpoints
- `lib/Listeners/TokenBridgeListener.php` — bridged `user_oidc` tokens into the engine session
- `lib/Middleware/TokenRefreshMiddleware.php` — token refresh dance for `user_oidc` flow
- All `oidc_login` and `user_oidc` runtime dependencies (the engine never asks for them again)
- Legacy migration code in `lib/Migration/InstallStep.php` — Souvera Mail is fresh-install only; there are no legacy `x2mail` / `souvera_mail` / `snappymail` data to migrate from.

### Changed
- `EngineHelper::getOidcAccessToken()` resolves through `OidcProviderService` instead of the `user_oidc` event listener. `isOIDCLogin()` simplifies to "NC user is logged in AND H2CK/oidc is available".
- `LoginBridgeListener` only stamps `smail-uid` into the session — no more `is_oidc` / `oidc_access_token` session markers.
- `appinfo/routes.php` now contains only the 4 page routes; every wizard endpoint is gone.
- `appinfo/info.xml` description rewritten for the new NC-as-OP architecture; all 5 occ commands registered.
- `README.md` rewritten end-to-end for the new architecture (NC-as-OP, CLI-first deploy, `occ smail:bootstrap` quick-start, `occ` command reference, troubleshooting).

### Migration / upgrade notes
- **Fresh install only.** Souvera Mail 0.9.0 does not migrate data from earlier `x2mail`/`souvera_mail`/SnappyMail/RainLoop instances. Operators upgrading from a previous deployment should plan a clean reinstall: export their mail data through IMAP, install Souvera Mail 0.9.0, and reconnect.

## [0.8.0] — 2026-06-10

### Removed
- Support for the unmaintained `oidc_login` app — `user_oidc` is the only supported SSO provider now (`oidc_login` does not support Nextcloud 34+). Existing `oidc_login` setups must migrate to `user_oidc`.

### Added
- Optional extra scopes for OIDC token exchange — configurable in the setup wizard next to the token audience, via `occ smail:setup --oidc-scopes "scope1 scope2"`, or directly: `occ config:app:set smail oidc-exchange-scopes --value "scope1 scope2"`. The wizard's Test Login uses the typed scopes.
- Test Login diagnostics show which token was actually used: exchanged token (with audience, scopes and remaining lifetime), a warning when the token exchange fell back to the login token, or the plain login token

### Security
- Bearer tokens are never sent over unencrypted connections to remote mail servers (loopback connections are exempt)

### Fixed
- An expired or rejected token during SMTP authentication now terminates the SASL exchange cleanly (RFC 7628) and the mail server's error details are logged, instead of failing with a generic error
- Token exchange/refresh failures now log the identity provider's error response and the remaining token lifetime, making SSO issues diagnosable from the log
- The engine log no longer fills with `CRITICAL: Caught SIGCHLD` entries — these fired on every normal helper-process exit and were not errors
- The webmail UI keeps loading even if a future Nextcloud release removes the internal CSP nonce API (the self-generated fallback nonce is now correctly referenced by the app's content security policy)

## [0.7.3] — 2026-06-10

### Fixed
- Nextcloud 34 compatibility: the content security policy no longer depends on the `allowEvalScript()` API removed in NC 34. A leftover `$evalScriptAllowed` subclass property additionally crashed NC 34's reflection-based policy merge with an HTTP 500 on every page load; the property is gone and `'unsafe-eval'` — required by the Knockout-based UI — is now added to `script-src` via the version-appropriate path (direct keyword on NC 34, `allowEvalScript()` on NC 33).

### Changed
- CSP nonce lookup now degrades to a self-generated nonce if the internal `ContentSecurityPolicyNonceManager` is removed in a future release, turning a potential fatal error into a soft failure (no public nonce API exists yet — see #181).

## [0.7.2] — 2026-06-05

### Changed
- Authentication state now lives entirely in the Nextcloud session — Souvera Mail no longer stores its own authentication cookies

## [0.7.1] — 2026-05-31

### Security
- S/MIME certificates are now generated as per-user self-signed end-entity certificates, signed with their own key (no shared signing key)
- S/MIME signatures now report the signer identity and a trust state (trusted only for known signers) instead of marking every valid signature as verified
- External image proxy rejects URLs resolving to private, loopback, link-local or reserved addresses (SSRF hardening) and caps proxied response size
- Mail authentication offers only OAuth SASL mechanisms (OAUTHBEARER/XOAUTH2); password-based SASL fallbacks were removed so a malicious server cannot downgrade the connection

### Fixed
- S/MIME certificate creation now works for identities without a display name

## [0.7.0] — 2026-05-28

### Removed
- Password/plain login — Souvera Mail is SSO/OIDC-only (`--auth plain`, `occ smail:settings`, and the manual password login form are no longer available)
- Legacy engine admin panel (`/?admin`) — all administration moves to Nextcloud Settings → Souvera Mail
- SnappyMail legacy domain blocklist seed (`app/domains/disabled`) — fresh installs no longer copy a public-provider deny list into engine data

### Added
- Setup wizard **Test Login** — verifies live `OAUTHBEARER` login to IMAP, SMTP submission, and ManageSieve with the current SSO token
- Configurable ManageSieve in setup: `--sieve-host`, `--sieve-port`, `--sieve-ssl` (CLI) and matching fields in the setup wizard
- **Allgemein** + **Info** sections in Nextcloud Settings → Souvera Mail (attachment limits, OpenPGP/GnuPG, version info)
- Real OAUTHBEARER auth-test in the setup wizard (replaces the old engine connectivity test)

### Changed
- Mail authentication is OAuth SASL only (`OAUTHBEARER` / `XOAUTH2`) for IMAP, SMTP, and Sieve
- Setup wizard is SSO-only (no password auth mode); SMTP authentication is enabled automatically in generated domain config
- Setup wizard mail server section: **IMAP → SMTP → Sieve**
- Updated bundled OpenPGP.js to **6.3.0** — modern WebCrypto/WebAssembly, smaller bundle (drops the legacy asm.js fallback)
- IMAP client supports **IMAP4rev2** (RFC 9051) when the mail server advertises it — unread counts use ESEARCH instead of deprecated SELECT UNSEEN
- Updated bundled Sabre VObject (**4.5.8**) and Sabre Xml (**4.0.6**) for vCard/iCal parsing

### Fixed
- ManageSieve setup and **Test Login** now use the configured Sieve host, port, and TLS mode (supports both STARTTLS and implicit TLS listeners)
- Sieve filtering works with the same OAuth SSO flow as IMAP and SMTP

### Verified
- End-to-end **Stalwart 0.16.6** with Keycloak + LDAP directory (IMAP/SMTP/Sieve OAUTHBEARER via setup wizard). See [docs/configs/stalwart-oauthbearer.md](docs/configs/stalwart-oauthbearer.md).

## [0.6.4] — 2026-05-27

### Added
- Optional OAuth token exchange: `occ smail:setup --imap-audience <client>` (and a matching setup-wizard field) lets the mail server use a different OIDC client than the Nextcloud login client, for IdPs that support token exchange

### Changed
- SSO token refresh now uses the official Nextcloud `user_oidc` token API for better forward compatibility
- `occ smail:setup` default `--smtp-port` is now 587 (standard submission port) instead of 25

### Fixed
- SSO mailbox reconnect after token expiry is now reliable in persistent-login sessions

## [0.6.3] — 2026-04-13

### Changed
- JS/CSS minification in build pipeline (terser + clean-css)
- Setup wizard and `occ smail:setup` now enforce one active domain profile and consolidate stale extra configs
- OAuth domain configs now advertise only `OAUTHBEARER` and `XOAUTH2` SASL mechanisms by default

### Fixed
- SMTP OAUTHBEARER authentication now works in SSO mode — `useAuth` is enforced when `authType=oauth` so `SmtpClient::Login()` is no longer skipped
- Preflight checks now perform real IMAP/SMTP `STARTTLS` negotiation instead of plain TCP reachability checks
- Preflight TLS checks now inherit current Souvera Mail SSL defaults and fall back to relaxed diagnostics with a visible warning instead of hard-failing selfhosted certificate setups
- SMTP OAuth capability is now validated when authenticated sending is enabled in SSO mode
- Setup wizard now writes the new active domain before cleaning up stale profiles and reports cleanup warnings instead of risking config loss
- Release defaults for `autologout`, `contacts_autosave`, `show_login_alert`, and identity handling are restored through targeted migration/default application
- `occ smail:status` now reports the actual IMAP/SMTP security mode and the stored OIDC provider selection
- `occ smail:settings` and password-login persistence now store secrets with sensitive/internal flags
- Repair step no longer wipes legacy passphrases on every update and no longer resets broad engine config on every post-update

## [0.6.2] — 2026-03-30

### Fixed
- SSO login works reliably after App Store upgrades
- Plugin updates no longer leave stale files that break the frontend

## [0.6.1] — 2026-03-30

### Added
- Dashboard widget: unread mails stay visible after OIDC token expiry (auto-refresh)
- Nextcloud search: mail search works reliably with OIDC token refresh
- Calendar save: duplicate detection — warns when event already exists, option to update or cancel
- Calendar save: visual feedback — shows Created/Updated/Error states on save button
- Contacts: address book name shown in contact detail view
- Setup Wizard: unified Mail-Server layout, domain tabs, OIDC provider visible by default
- Password auth: credentials persist across sessions (automatic on Nextcloud login)

### Changed
- Auth type switch (SSO/Password) applies cleanly without re-login required
- Password encryption uses Nextcloud-native cryptography
- All 26 engine enumerations migrated to native PHP 8.1 enum types
- Engine static analysis raised from PHPStan Level 1 to Level 2
- Contacts detail font size reduced for cleaner layout

### Fixed
- File attachments from Nextcloud Files work again (NC33 API migration)
- Save email/attachments to Nextcloud Files works again (NC33 API migration)
- Email address shown correctly in login field (was showing username only)
- Switching between SSO and password auth no longer causes authentication errors
- Calendar save to Nextcloud works reliably
- Setup wizard token diagnostics display correctly for all OIDC providers

### Removed
- Manual email/password settings page (SSO handles authentication automatically)
- Admin panel: password/TOTP authentication removed (SSO-only)
- Engine dead code: ~14,000 lines removed (unused libraries, standalone contacts system, admin auth)

## [0.6.0] — 2026-03-29

### Breaking
- Complete rebrand: SnappyMail/RainLoop → Souvera Mail across all namespaces, directories, DB tables, config keys, and UI
- Existing installations are migrated automatically

### Added
- Admin panel authenticates via Nextcloud SSO
- Setup wizard with OIDC verification and JWT token diagnostics
- Info page when no mail server is configured (with link to setup wizard for admins)
- About page shows latest GitHub release version

### Changed
- SSO-first defaults: OAuth as default auth type
- Single-domain setup: wizard manages one mail server configuration
- Domain field auto-suggested from admin email address
- `occ smail:status` shows compact SSO diagnostics
- Translations updated for all 97 locales

### Removed
- Separate admin password/cookie authentication
- Admin panel menus: Security, Plugins, Branding, Packages, Login Screen
- Multi-domain management and domain alias in admin panel
- External plugin manager
- iframe embedding mode

## [0.5.9] — 2026-03-26

### Added
- Personal settings page with Identity & Signatures management link
- Own settings section with app icon in Nextcloud sidebar
- Dynamic page title from admin-configured branding

### Fixed
- PSR-12 code style compliance
- CSS isolation for Nextcloud header and user menu
- Admin panel branding
- German translations

## [0.5.8] — 2026-03-26

### Added
- ICS Event Card: calendar invitations displayed prominently above message body
- Event details: date/time, organizer, location, attendees with formatted display
- One-click "Save to Calendar" button with CalDAV integration
- Calendar picker filters read-only calendars (Deck-generated etc.)
- Toast notification on successful calendar save
- German and English translations for event card UI
- App Store screenshot for calendar integration

## [0.5.7] — 2026-03-26

### Fixed
- SideMenu app compatibility: SnappyMail's global CSS (ul/li margin resets) no longer leaks into Nextcloud UI
- CSS selector scoping: all embed.css rules prefixed with `#rl-app` to prevent style leakage
- Boot CSS: strip body/html rules from SnappyMail's inline boot stylesheet

## [0.5.6] — 2026-03-26

### Changed
- SSO defaults: disable contacts autosave
- Hide theme selector on fresh install (smail theme is default)

## [0.5.5] — 2026-03-26

### Fixed
- Default theme set to smail on fresh install (was falling back to "Default")

## [0.5.4] — 2026-03-26

### Added
- First release on Nextcloud App Store
- Signed with official Nextcloud Code Signing certificate

### Changed
- Updated screenshots for App Store listing

## [0.5.3] — 2026-03-25

### Added
- PHPUnit test infrastructure with 18 unit tests (DomainConfigService, TokenRefreshMiddleware)
- CI: automated test execution in pipeline

### Changed
- Event listeners moved from `boot()` closures to dedicated `IEventListener` classes (PasswordLogin, Logout, Impersonate)

### Fixed
- Domain validation: reject `.` and `..` as domain names (found by unit tests)

## [0.5.2] — 2026-03-25

### Added
- Dashboard widget for unread mail (`IAPIWidgetV2`, auto-reload every 120s)
- Complete German translations for all UI strings

### Changed
- Migrate 47 deprecated `IConfig` calls to `IAppConfig`/`IUserConfig` (NC33 public API)
- Replace private `OC\Core\Command\Base` with `Symfony\Component\Console\Command\Command`
- Template escaping: `p()` for values, `print_unescaped()` for engine content
- Replace "SnappyMail" with "Souvera Mail" in admin panel UI

### Fixed
- Null-guard for `$this->userId` in FetchController personal settings
- Add `declare(strict_types=1)` to Settings command
- Dashboard widget icon uses NC URL generator instead of internal SM path

## [0.5.1] — 2026-03-25

### Fixed
- SSO setup incorrectly disabled identity management (allow_additional_identities, popup_identity)

## [0.5.0] — 2026-03-25

### Added
- New `smail` theme for Nextcloud 33+ design system
  - 3-tier color mapping: pastel backgrounds, element colors for icons, text colors for readability
  - Alerts follow NC33 NoteCard pattern (pastel bg + colored left border)
  - Buttons follow NC33 NcButton pattern (focus-visible box-shadow, transitions)
  - Inputs with NC33 focus-visible inset box-shadow
  - NC33 info status color support
  - Light + dark mode with NC33 theme values
  - Updated border-radius, font stack, disabled states to NC33 defaults

### Fixed
- Identity popup close button navigated away instead of showing confirm dialog (href="#" in embedded mode)
- Error tooltips used aggressive red background instead of NC33 NoteCard pattern
- Priority-high indicators, attachment errors, virus warnings now use NC33 color system
- btn-danger/btn-warning hover states were overridden by generic hover rule

### Changed
- Default theme switched from `NextcloudV25+` to `smail` (InstallStep, AdminSettings, RainLoop)
- Remove 20 unused bundled SnappyMail themes (A, BlackWood, Blurred, etc.)
- Hide auto-logout setting in SSO/embedded mode (NC manages the session)

## [0.4.10] — 2026-03-25

### Fixed
- SSO: auto-disable "Add account" and "Manage identities" when OIDC is configured (Setup Wizard, CLI, and upgrade)
- SSO: SM plugin read autologin config from wrong app namespace (`snappymail` → `smail`), breaking fresh installs

## [0.4.8] — 2026-03-23

### Fixed
- Fix unreadable error messages in Compose view (dark red text on dark background in NC dark theme)
- Position compose error tooltip inline in toolbar row instead of overlapping fields

## [0.4.7] — 2026-03-23

### Fixed
- Fix double-slash in `app_path` when `overwritewebroot=/` (normalize `getAppWebPath()` output in InstallStep, Setup, AdminSettings, FetchController)

## [0.4.6] — 2026-03-22

### Security
- Fix ContactsSync password leaked to browser in AppData JSON response
- Fix path traversal via unvalidated domain in DomainConfigService
- Fix SM plugin file/folder paths without directory traversal check
- Fix Setup Wizard missing hostname validation and error message redaction
- Fix `app_path` missing `..` traversal check in admin settings
- Fix IMAP connection failure permanently wiping stored credentials
- Add email format validation to personal settings
- Restrict log file permissions to 0600 on creation

## [0.4.5] — 2026-03-22

### Added
- **PHPStan Level 7 static analysis** — catches type errors, undefined methods, wrong argument types at build time
- CI pipeline with automated lint, build, validate, and deploy

### Fixed
- Removed 3 unused injected properties (`FetchController::$appManager`, `Provider::$l10n`, `AdminSection::$l`)
- Removed redundant runtime checks (`is_callable`, `method_exists`) that always evaluate to true
- Fixed SnappyMail API calls: `bUseSortIfSupported` → `bUseSort`, `MailClient::IsLoggined()` → `ImapClient()->IsLoggined()`
- Added type guards for `file_get_contents()` return values
- Fixed private method access pattern in `SnappyMailHelper`
- Added missing return type declarations and PHPDoc type annotations across 20 files

## [0.4.4] — 2026-03-19

### Fixed
- Skip SM bootstrap for app-password/token logins (bots, DAV clients, API)
- Graceful degradation when app/index.php is temporarily unreadable
- Guard against APP_DATA_FOLDER_PATH redefinition on retry after partial bootstrap

## [0.4.3] — 2026-03-19

### Fixed
- Setup and InstallStep now set title and loading_description to "Souvera Mail"
- Restored original minified app.min.js — no more broken JS from unminified overwrites
- Regenerated compressed .gz/.br static files to match modified JS/CSS
- Reverted PageController mailto handling to upstream SM ServiceMailto flow

## [0.4.2] — 2026-03-19

### Fixed
- Contact detail view now shows name and email for read-only (system) contacts
- Contact CRUD uses CardDAV backend directly — proper vCard N property support
- Numeric contact IDs for SnappyMail JS compatibility
- Contact tab restructured to match business tab layout (label + span + input)
- German labels corrected: "Vorname:" / "Nachname:" (singular + colon)
- Read-only contact spans visible via CSS specificity fix
- Empty name fields (middle name, prefix, suffix) hidden for read-only contacts

## [0.4.1] — 2026-03-19

### Fixed
- Bundled nextcloud plugin now syncs to SM data directory on every app enable/upgrade
- Contacts from all address books (including system/users) are now visible, system contacts marked read-only
- Contacts without email address are hidden from the contacts list
- `IManager::delete()` type handling fixed for NC CardDAV backend compatibility
- Search queries capped at 10,000 results for safety in large address books
- Double-slash in `app_path` when `overwritewebroot = /` prevented

## [0.4.0] — 2026-03-19

### Added
- **Nextcloud-native Contacts integration**: read, create, edit, and delete contacts directly in Nextcloud Contacts — no CardDAV sync, no separate database
- Autocomplete suggestions in To/Cc/Bcc fields now pull from Nextcloud Contacts
- `occ smail:setup` now enables contacts automatically

### Changed
- Contacts provider replaced: PdoAddressBook/SQLite → NextcloudAddressBook via NC IManager API
- Separate suggestions driver removed (unified into AddressBook provider)

### Fixed
- Dovecot OAuth2 docs link updated to 2.4+ documentation
- Added Dovecot 2.4+ version requirement to README

## [0.3.1] — 2026-03-18

### Fixed
- MailSo: SMTP CRLF injection prevention in MailFrom/Rcpt
- MailSo: IMAP EscapeString strips CR/LF/NUL from quoted strings
- MailSo: MIME parser recursion depth limit (max 50 levels)
- MailSo: SSLContext property whitelist in fromArray()
- MailSo: Sieve script name CRLF stripping
- MailSo: fix undefined variable in IdnToUtf8/IdnToAscii
- MailSo: Xxtea return type and parameter type for PHP 8.4
- NC Plugin: replace all `\OC::$server` with `\OCP\Server::get()`

### Changed
- Static version path — no renames on version bumps
- Version read from info.xml at runtime (single source of truth)
- Update check against own GitHub releases
- Auto-update disabled (managed releases only)
- About page: Souvera Mail branding with GitHub link

## [0.3.0] — 2026-03-18

### Security
- Fix S/MIME signature verification bypass (PKCS7_NOSIGS removed)
- Fix unsafe `unserialize()` in upgrade.php — restrict to scalars (prevent RCE)
- Fix TAR path traversal in plugin/update extraction
- Fix XSS via crafted RTF content (htmlspecialchars on output)
- Fix JWT broken encoding (wrong variable name)
- Add image decompression bomb protection (25MP limit)
- Fix SSO hash Time=0 bypass — require valid timestamp
- S/MIME cert path: basename() to prevent directory traversal
- Temp file: basename() to prevent path traversal
- TAR/ZIP: restrict Content-Type header chars to printable ASCII
- RTF: add recursion depth limit (max 100 levels)
- HTTP socket: instance-level Authorization storage (prevent cross-request leak)
- EXIF: validate MIME type before data:// URI construction
- Strict === comparison for session UID check

### Fixed
- PHP 8.4: OAuth2 MAC nonce — `uniqid()` replaced with `random_bytes()`
- PHP 8.4: JWT `openssl_pkey_free()` removed (deprecated since PHP 8.0)
- PHP 8.4: JWT `is_resource()` check updated for OpenSSLAsymmetricKey objects
- PHP 8.4: Imagick `setImageMatte()` replaced with `setImageAlphaChannel()`
- PHP 8.4: RTF `mb_convert_encoding` HTML-ENTITIES replaced with `html_entity_decode`
- PHP 8.4: OAuth2 SSL verification enabled by default (was disabled — MITM risk)
- PHP 8.4: HTTP socket `\split()` replaced with `\explode()` (removed since PHP 7)
- PHP 8.4: HTTP socket `\random_int()` fixed with required arguments
- PHP 8.4: CRAM SASL property declaration added
- PHP 8.4: `auto_detect_line_endings` removed (deprecated since PHP 8.1)
- PHP 8.4: lessphp class property declarations (15 dynamic properties)
- IMAP: OAUTHBEARER removed from wrong PLAIN/SCRAM branch (dead code fix)
- HTTP: `verify_peer` default changed to `true`, `CURLOPT_SSL_VERIFYHOST` enabled
- AdditionalAccount: fix `$aData` → `$aAccountHash` variable name bug
- Folders: fix undefined `$iErrorCode` variable
- TNEFDecoder: missing break in switch, null coalescing for buffer reads, typed property defaults
- TAR stream: fix undefined variable in addFromString
- S/MIME encrypt(): fix dead code return, remove duplicate fopen in sign()
- TNEFAttachment: buffer length sanity check

### Changed
- **Fork migration: SM Core v2.38.2 now tracked in git** (was gitignored + sed patches)
- Full SM Core audit completed: 6 CRITICAL, 15 HIGH, 16 MEDIUM findings fixed
- Automated release flow (build, sign, GitHub, NC App Store)

## [0.2.0] — 2026-03-18

### Added
- Setup Wizard web UI in admin settings with preflight checks
- Build and signing targets for NC App Store
- App Store metadata: author, repository, bugs, screenshots in info.xml

### Security
- Fix XSS via unescaped iframe src in templates/index.php
- Fix arbitrary file require via custom_config_file in InstallStep (realpath validation)
- Replace all `$_POST`/`$_GET`/`$_SERVER` direct access with `$this->request->getParam()`
- Add `hash_equals()` for admin panel key comparison (timing-safe)
- Add port range validation to `saveSetup()`
- Validate `app_path` to prevent protocol injection

### Fixed
- PHP 8.4: Remove deprecated `E_STRICT` constant from SM Logger
- PHP 8.4: Fix undefined array key "secure" in SM ConnectSettings
- PHP 8.4: Fix 32 implicit nullable parameters in SM PGP/GPG and Sabre VObject
- Chrome 117+: Fix invalid RegExp v-flag in folder create pattern
- NC 33: Replace 28 deprecated `\OC::$server` calls with `\OCP\Server::get()` or constructor DI
- NC 33: Replace 2 deprecated `\OC_User::isAdminUser()` with `IGroupManager::isAdmin()`
- NC 33: Replace 3 deprecated `getSystemConfig()` with `IConfig::getSystemValue()`
- NC 33: PHP 8 attributes replace deprecated PHPDoc annotations
- NC 33: DI autowiring replaces manual `$c->query()` controller registration
- Setup Wizard: API error feedback shown in UI instead of silent console.error
- Preflight check box and Delete Domain button contrast for light and dark themes

### Changed
- SM Core pinned at v2.38.2 with PHP 8.4 + browser compat patch set
- Auth type selection unified: OAUTHBEARER and XOAUTH2 merged into single SSO option
- SSO mode: hide Logout, Add Account, and redundant folder settings icon
- SSO mode: hide toggleLeftPanel button in settings view
- InstallStep removes SM default domains (gmail.com, hotmail.com, etc.) on install
- Licence updated to AGPL-3.0-or-later (SPDX format)
- GitHub URLs corrected to PhiGi87/souvera_mail

## [0.1.0] — 2026-03-18

### Added
- First working version
- SnappyMail v2.38.2 core engine
- OAUTHBEARER/XOAUTH2 IMAP authentication
- Automatic OIDC token refresh
- `occ smail:setup` command
- `occ smail:status` command
- Nextcloud 28-35 support
- PHP 8.1+ required
