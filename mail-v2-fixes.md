# souvera_mail v2 — Fix-Liste Runde 2 (nach Layout-Rework)

Diagnose anhand von Screenshots + Quellcode-Prüfung (Stand: aktueller Commit). Alle Root Causes unten sind direkt im Code verifiziert, keine Vermutungen — außer explizit als „nicht abschließend geklärt" markiert. Reihenfolge = Priorität/Nutzer-Impact.

---

## 1. E-Mails werden nicht als gelesen markiert

**Datei:** `src-v2/views/MailHomeView.vue`, Methode `onOpenEmail()` (Zeile 172–181)

```js
async onOpenEmail(email) {
    this.selectedEmail = email
    this.emailBodyHtml = ''; this.emailBodyPlain = ''; this.loadingBody = true
    try {
        const body = await fetchEmailBody(email.id)
        this.emailBodyHtml = body.htmlBody || ''; this.emailBodyPlain = body.plainBody || ''
        this.selectedEmail = { ...email, ...body }
        await markEmailRead(email.id, true)   // ← wird aufgerufen, Backend wird korrekt informiert
    } catch (e) { console.error('Failed to open email', e) } finally { this.loadingBody = false }
},
```

**Root Cause:** `markEmailRead()` wird korrekt aufgerufen (Backend-Call ist ok), aber das lokale `this.emails`-Array (aus dem die Liste gerendert wird) wird **nie aktualisiert**. `EmailListItem.vue:4/19` bindet den Ungelesen-Zustand an `email.isRead` (`v-if="!email.isRead"` für den blauen Punkt, Zeile 19). Da nur `this.selectedEmail` ersetzt wird, nicht das Objekt im `emails`-Array, bleibt der Punkt in der Liste stehen, bis der ganze Ordner neu geladen wird.

**Fix:**
```js
async onOpenEmail(email) {
    this.selectedEmail = email
    this.emailBodyHtml = ''; this.emailBodyPlain = ''; this.loadingBody = true
    try {
        const body = await fetchEmailBody(email.id)
        this.emailBodyHtml = body.htmlBody || ''; this.emailBodyPlain = body.plainBody || ''
        this.selectedEmail = { ...email, ...body }
        if (!email.isRead) {
            await markEmailRead(email.id, true)
            const listItem = this.emails.find(e => e.id === email.id)
            if (listItem) listItem.isRead = true
        }
    } catch (e) { console.error('Failed to open email', e) } finally { this.loadingBody = false }
},
```
(Der `if (!email.isRead)`-Guard vermeidet unnötige API-Calls beim erneuten Öffnen bereits gelesener Mails — optional, aber sauberer.)

---

## 2. Compose-Modal zeigt "undefined" im From-Auswahlfeld

**Datei:** `src-v2/components/ComposeEditor.vue`, Zeile 8–12

```html
<NcSelect v-if="identities.length > 1" v-model="fromIdentity"
    :options="identities"
    :label-outside="true"
    :label="t('souvera_mail', 'From')"
    class="compose-layout__from" />
```

**Root Cause:** `NcSelect` (basiert auf vue-select) verwendet die Prop `label` NICHT als sichtbare Feld-Beschriftung, sondern als **Namen des Objekt-Schlüssels**, der pro Options-Objekt zur Anzeige verwendet wird (Standard: `"label"`). Hier wird `:label="t('souvera_mail', 'From')"` übergeben — also der literale String `"From"`. Dadurch versucht vue-select für jede Option `option["From"]` anzuzeigen. Unsere Identity-Objekte (`loadIdentities()`, Zeile 165: `{ id, label, name, email }`) haben aber gar keinen Schlüssel `"From"` — das Ergebnis ist `undefined`, und Vue rendert `undefined` buchstäblich als Text "undefined".

**Fix:** Die Prop, die den korrekten Objekt-Schlüssel für die Anzeige angibt, muss auf `"label"` bleiben (matcht unser gemapptes Feld) — NICHT mit dem sichtbaren Feld-Titel überschreiben. Für die sichtbare Beschriftung "From" die dafür vorgesehene NcSelect-Prop verwenden (in `@nextcloud/vue` 9.x heißt diese `input-label`, NICHT `label`):

```html
<NcSelect v-if="identities.length > 1" v-model="fromIdentity"
    :options="identities"
    label="label"
    :input-label="t('souvera_mail', 'From')"
    class="compose-layout__from" />
```
Falls `input-label` in der installierten `@nextcloud/vue`-Version nicht existiert (`node_modules/@nextcloud/vue/dist/Components/NcSelect*` prüfen bzw. `npx vue-styleguidist`/Storybook-Docs checken), ersatzweise einfach die `:label`-Prop entfernen (Default ist bereits `"label"`) und die Beschriftung "From" separat als `<label>`-Element über dem Select rendern.

---

## 3. Speicherquota-Donut fehlt komplett (über dem Settings-Link)

**Dateien:** `lib/Service/QuotaService.php:101`, konsumiert in `src-v2/App.vue:41` und `src-v2/views/SettingsView.vue:9`

```php
$unlimited = $totalRaw <= 0;
$percentage = ($unlimited || $used <= 0) ? 0 : (int) \min(100, \floor(($used / $totalRaw) * 100));

$result = [
    'used' => $used,
    'total' => $totalRaw,   // ← bleibt 0 bei unlimitierter Quota!
    ...
];
```
Der Controller (`V2SettingsController.php:43`) gibt `total` 1:1 durch: `return new JSONResponse(['used' => $data['used'] ?? 0, 'total' => $data['total'] ?? 0]);`

**Root Cause:** Wenn ein Nutzer-Account bei Stalwart **keine** `MaxDiskQuota` gesetzt hat (unlimitiert — sehr wahrscheinlich bei diesem Admin-Account der Fall), liefert das Backend `total: 0`. Frontend prüft aber überall nur `quotaTotal > 0` (`App.vue:41`: `<QuotaDonut v-if="quotaTotal > 0">`; `SettingsView.vue:9`: `v-if="quotaTotal > 0"`), was **unlimitiert** und **keine Daten verfügbar** identisch behandelt — der Donut verschwindet komplett, obwohl der Account durchaus Speicher benutzt.

**Fix (Backend):** In `QuotaService.php` das bereits berechnete `unlimited`-Flag im API-Response mit durchreichen (`V2SettingsController::quota()` erweitern: `'unlimited' => $data['unlimited'] ?? false` zusätzlich zu `used`/`total`).

**Fix (Frontend, beide Stellen):**
- `App.vue:41`: `<QuotaDonut v-if="quotaUsed > 0 || quotaUnlimited" :used="quotaUsed" :total="quotaTotal" :unlimited="quotaUnlimited" />` (neue Datenfelder `quotaUnlimited` ergänzen, aus `loadQuota()`-Response befüllen).
- `SettingsView.vue:9`: gleiche Anpassung, plus Text `∞` statt Prozentzahl wenn `unlimited`.
- `QuotaDonut.vue`: neue Prop `unlimited: { type: Boolean, default: false }`; wenn `unlimited`, Donut-Ring einfach als "voll grün" mit `∞`-Label statt Prozent rendern (keine Division durch `total` nötig, `total` kann 0 bleiben).

---

## 4. Komplette Oberfläche auf Englisch trotz deutscher Nextcloud-Instanz

**Datei:** `lib/Controller/PageController.php`, Methode `renderV2()`, Zeile 261–273

```php
private function renderV2(): TemplateResponse
{
    $this->navigationManager->setActiveEntry('souvera_mail');
    try {
        $lang = \OC::$server->get(\OCP\IL10N::class)->getLanguageCode();
    } catch (\Throwable) {
        $lang = 'en';
    }
    \OCP\Util::addScript('souvera_mail', 'l10n-' . $lang);   // ← FALSCHE API
    \OCP\Util::addScript('souvera_mail', 'souvera_mail-v2');
    return new TemplateResponse('souvera_mail', 'v2', []);
}
```

**Root Cause:** `\OCP\Util::addScript($app, $script)` lädt Dateien aus `js/<script>.js` — hier wird also nach einer nicht-existenten Datei `js/l10n-de.js` gesucht (404, still ignoriert). Die tatsächliche, korrekt gepflegte Übersetzungsdatei liegt aber unter `l10n/de.js` (**verifiziert**: `l10n/de.json` enthält u.a. `"New message": "Neue Nachricht"`, `"Newer": "Neuere"`, `"Older": "Ältere"` — die Übersetzungen sind vorhanden und korrekt, sie werden nur nie geladen). Deshalb registriert `OC.L10N` nie einen Katalog für die App `souvera_mail`, und der `t()`-Fallback in `src-v2/main.js:17` (`window.t || ((app, msg) => msg)`) gibt für jeden String einfach den unübersetzten englischen Original-Text zurück — das ist kein Bug in den Vue-Komponenten (die rufen `t()` überall korrekt auf), sondern ausschließlich dieser eine PHP-Zeile.

**Fix:**
```php
\OCP\Util::addTranslations('souvera_mail', $lang);
\OCP\Util::addScript('souvera_mail', 'souvera_mail-v2');
```
`\OCP\Util::addTranslations()` ist die korrekte Nextcloud-API, die automatisch die passende `l10n/<lang>.js`-Datei einbindet und `OC.L10N.register('souvera_mail', {...})` ausführt, sodass `window.t('souvera_mail', ...)` danach die Katalog-Einträge findet.

**Zusätzlich prüfen:** Der String `'Write your message…'` in `src-v2/components/ComposeEditor.vue:27` verwendet das Unicode-Ellipsis-Zeichen „…" (U+2026), während `l10n/de.json` den Schlüssel `"Write your message..."` mit drei ASCII-Punkten enthält — das ist ein Msgid-Mismatch, der auch nach dem obigen Fix zu einer weiterhin unübersetzten Anzeige führen würde. Nach dem addTranslations-Fix einmal komplett durch alle `t()`-Aufrufe in `src-v2/` gehen und mit den Schlüsseln in `l10n/de.json` abgleichen (v.a. bei Ellipsis-Zeichen „…" vs. „..." und bei den zusammengesetzten Strings wie `t('souvera_mail','To') + '…'` in `ComposeEditor.vue:15/20/21` — hier ist die Übersetzung von "To" ok, das „…" wird separat angehängt, das ist unkritisch).

---

## 5. E-Mail-Inhalt wird nur winzig/teilweise angezeigt (viel Leerraum darunter)

**Datei:** `src-v2/components/HtmlMailFrame.vue`, Template Zeile 13–19

```html
<iframe
    ref="frame"
    :srcdoc="srcdoc"
    class="html-mail-frame__iframe"
    :sandbox="'allow-same-origin allow-popups allow-popups-to-escape-sandbox'"
    @load="onFrameLoad"
/>
```

**Root Cause:** Die Höhenmessung selbst funktioniert (ResizeObserver + initiale Messung in `onFrameLoad()`, Zeile 60–80, berechnet `this.frameHeight` korrekt) — aber das Ergebnis wird **nirgends auf das `<iframe>`-Element angewendet**. Es fehlt die `:style`-Bindung. Das iframe bleibt dadurch auf seiner Browser-Default-Höhe (typischerweise ~150px), unabhängig vom tatsächlichen Inhalt — genau das erklärt das Screenshot-Symptom: 1–2 Zeilen Text sichtbar, Rest abgeschnitten, darunter der viele leere weiße Platz im Detail-Panel (das Panel selbst ist groß, das iframe darin ist klein).

**Fix:**
```html
<iframe
    ref="frame"
    :srcdoc="srcdoc"
    class="html-mail-frame__iframe"
    :style="{ height: frameHeight + 'px' }"
    :sandbox="'allow-same-origin allow-popups allow-popups-to-escape-sandbox'"
    @load="onFrameLoad"
/>
```
Zusätzlich (Robustheit, kein Muss): die Seiteneffekt-Zuweisung `this.blockedCount = blockedCount` innerhalb der `srcdoc`-computed-Property (Zeile 46–54) ist ein Vue-Anti-Pattern (State-Mutation in einer computed-Getter-Funktion) — funktioniert meist, ist aber fragil bei Vue-Reaktivitäts-Batching. Sauberer: `blockedCount` per `watch` auf `html`/`remoteAllowed` in einer Methode setzen statt als Nebeneffekt der computed-Property.

---

## 6. Blockierte externe Bilder erzeugen riesige graue Flächen / dunkler Balken

**Datei:** `src-v2/utils/mailSanitizer.js`, Funktion `sanitizeMailHtml()`, DOMPurify-Hook Zeile 90–116

```js
if (node.tagName === 'IMG' || node.tagName === 'SOURCE') {
    const src = node.getAttribute('src') || ''
    if (/^https?:\/\//i.test(src)) {
        node.setAttribute('data-blocked-src', src)
        node.setAttribute('src', BLANK_GIF)   // ← nur src wird ersetzt
        blockedCount++
    }
}
```

**Root Cause:** Beim Blockieren wird nur das `src`-Attribut auf ein 1×1-transparentes GIF gesetzt. Vorhandene `width`/`height`-HTML-Attribute oder `style="width:…;height:…"` des ursprünglichen `<img>`-Tags (typisch bei E-Mail-Bannern, z.B. `width="600" height="200"`) bleiben unangetastet. Der Browser streckt das winzige transparente GIF auf die deklarierte Größe — das Ergebnis ist eine große leere/graue Fläche exakt in Bildgröße (Screenshot 1 und 3 zeigen genau das). Der dunkelblaue Balken in Screenshot 3 ist vermutlich eine `<td>`/`<table>`-Zelle mit `bgcolor`/Hintergrundfarbe (bleibt bewusst erhalten, da reine Farbwerte kein Tracking sind) und einer festen `width`/`height`, die früher neben/hinter einem jetzt blockierten Bild lag — die Zelle behält ihre ursprüngliche Größe.

**Fix:** Beim Blockieren zusätzlich `width`/`height`-Attribute entfernen und `width`/`height` aus dem `style`-Attribut herausfiltern, damit das Blank-GIF in seiner natürlichen (winzigen) Größe gerendert wird:

```js
if (node.tagName === 'IMG' || node.tagName === 'SOURCE') {
    const src = node.getAttribute('src') || ''
    if (/^https?:\/\//i.test(src)) {
        node.setAttribute('data-blocked-src', src)
        node.setAttribute('src', BLANK_GIF)
        node.removeAttribute('width')
        node.removeAttribute('height')
        if (node.hasAttribute('style')) {
            node.setAttribute('style', node.getAttribute('style').replace(/(width|height)\s*:\s*[^;]+;?/gi, ''))
        }
        blockedCount++
    }
}
```
Beim späteren „Bilder laden" (`unblockRemoteImages()`, Zeile 136–151) müssten dann `width`/`height` NICHT wiederhergestellt werden — Bilder laden einfach in ihrer natürlichen Originalgröße, was für E-Mail-Banner ohnehin meist gewünscht ist (durch `img{max-width:100%}` im `BASE_CSS` von `HtmlMailFrame.vue:27` sowieso begrenzt).

Für den dunkelblauen Balken zusätzlich in `BASE_CSS` (`HtmlMailFrame.vue:27`) eine defensive Regel ergänzen, die überdimensionierte Tabellenzellen-Höhen kappt:
```css
td[height], table[height] { height: auto !important; }
```

---

## 7. Pagination-Leiste sieht unstyled/gequetscht aus

**Datei:** `src-v2/components/PaginationBar.vue`

Funktional korrekt (nutzt bereits `NcButton` und `t()`), wirkt aber optisch dürftig, weil sie ohne visuellen Container flach im Listenbereich hängt (siehe Screenshot: "< Newer  1-50/50  Older >" wirkt wie reiner Text). Verbesserungsvorschlag:

```css
.pagination-bar {
    display: flex; justify-content: space-between; align-items: center;
    gap: 8px; padding: 10px 16px;
    border-top: 1px solid var(--color-border);
    background: var(--color-main-background);
}
.pagination-bar__info { font-size: 13px; color: var(--color-text-maxcontrast); white-space: nowrap; }
```
- `justify-content: space-between` statt `center`, damit "Newer" links, Zähler mittig, "Older" rechts sitzt (klareres Scan-Muster, mehr Abstand zu den Rändern).
- Bei sehr schmaler Listenspalte (< 360px, siehe `clamp(320px, 33%, 460px)` in `MailHomeView.vue:97`) auf Icon-only-Buttons ohne Text umschalten (nur Chevron-Icons, kein "Newer"/"Older"-Label), um Gedränge zu vermeiden — z.B. per `v-if` auf eine Breiten-Media-Query oder simple CSS `@container`-Abfrage, falls Container-Queries verfügbar sind, sonst JS-`matchMedia`.

---

## 8. Settings-Seite "blinkt nur kurz auf und verschwindet" — NICHT abschließend geklärt

Router (`router.js`), `App.vue`, `SettingsView.vue` und `usePreferences.js` wurden geprüft — **kein** Navigation Guard, kein automatischer Redirect, kein synchroner Render-Fehler (`prefs.account` hat einen sauberen Default `{email:'', server:''}`) gefunden, der ein Zurückspringen zur Inbox erklären würde. Auffällig: **`App.vue` wird derzeit aktiv weiterbearbeitet** (zwischen zwei Lesevorgängen in dieser Diagnose kam ein neuer "Mail archive"-Nav-Eintrag hinzu) — falls dieser Bug zum Zeitpunkt der Umsetzung der übrigen Fixes bereits verschwunden ist, bitte einfach ignorieren.

Falls der Bug noch reproduzierbar ist, bitte folgende Stellen als nächstes prüfen (konkrete Debugging-Schritte, keine Vermutungen mehr):
1. Browser-DevTools-Konsole beim Klick auf "Settings" öffnen — gibt es einen Vue-Render-Fehler oder eine unbehandelte Promise-Rejection?
2. Network-Tab: Schlägt `GET /api/v2/settings/preferences` mit 401/403/500 fehl? Ein Session-/CSRF-Fehler auf einer der vier parallelen `Promise.all()`-Anfragen in `SettingsView.vue:121` könnte, falls ein globaler Axios-Interceptor oder Nextclouds Standard-CSRF-Handling einen automatischen Reload/Redirect auslöst, dieses Verhalten erklären — es gibt aktuell aber **keinen** eigenen Interceptor im Code (`grep -rn interceptors src-v2/` liefert nichts), also müsste es aus `@nextcloud/axios` selbst oder der Nextcloud-Core-Session-Behandlung kommen.
3. Prüfen, ob evtl. zwei Kopien der App gleichzeitig geladen werden (alter SnappyMail-Container plus neuer v2-Container beide auf der Seite, beide mit Hash-Routing auf denselben `location.hash` reagierend) — das würde zu genau so einem "kurz sichtbar, dann von der anderen Instanz überschrieben"-Verhalten führen. Kurzer Check: `document.querySelectorAll('#souvera-mail-v2-app')` sollte im DevTools genau 1 Treffer liefern.

---

## Verifikation nach Umsetzung

1. `cd /projects/souvera_mail && npm run build` fehlerfrei.
2. `php -l lib/Controller/PageController.php lib/Controller/V2SettingsController.php lib/Service/QuotaService.php`.
3. Deutsche Nextcloud-Instanz, Hard-Reload: gesamte v2-Oberfläche auf Deutsch (Modal-Titel, Buttons, Platzhalter, Pagination).
4. Mail öffnen → ungelesen-Punkt verschwindet sofort in der Liste, ohne Reload.
5. Neue Nachricht öffnen → kein "undefined" mehr im From-Feld (bzw. Feld erscheint korrekt nur wenn >1 Identität existiert).
6. Navigation: Speicher-Donut sichtbar über "Settings" (auch bei unlimitierter Quota, dann mit ∞-Anzeige).
7. HTML-Mail mit viel Inhalt öffnen → volle Höhe sichtbar, kein Abschneiden, kein Leerraum.
8. Mail mit blockierten externen Bildern öffnen → kleine, unauffällige Platzhalter statt große graue/farbige Flächen; nach "Bilder laden" korrekte Darstellung.
9. Pagination-Leiste wirkt wie ein gestalteter Bestandteil der App, nicht wie roher Text.
