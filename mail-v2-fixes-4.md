# souvera_mail v2 — Fix-Liste Runde 5: NUR diese zwei Stellen ändern

**Wichtig — bitte genau befolgen:**
- Ändere **ausschließlich** die zwei unten beschriebenen Stellen.
- Füge **keine** weiteren CSS-Regeln hinzu und fasse **keine** anderen Teile der Datei an, auch wenn dir dabei etwas "verbesserungswürdig" erscheint. Die letzten Runden haben durch zusätzliche Änderungen an anderen Stellen neue Fehler erzeugt.
- Nach jeder der zwei Änderungen: speichern, `npm run build` ausführen, dann erst die nächste Änderung machen.
- Am Ende exakt die Prüfschritte unten durchgehen und bestätigen, dass jeder Punkt zutrifft, bevor du das als fertig meldest.

Nur eine Datei ist betroffen: **`/projects/souvera_mail/src-v2/components/ComposeEditor.vue`**

---

## Änderung 1 von 2: From-Feld ist leer (zeigt keinen Namen mehr)

### Was ist kaputt
In der letzten Runde wurde beim `NcSelect` für das From-Feld `:searchable="false"` ergänzt. Das war ein Fehler von mir — dadurch zeigt das Dropdown jetzt gar keinen Text mehr an, es ist komplett leer. Wir nehmen diese Fehlkorrektur zurück und bauen das From-Feld stattdessen mit einem ganz normalen, einfachen HTML `<select>`-Element, das niemals solche Anzeige-Probleme hat.

### Schritt 1.1 — Suche im Template diesen Block (aktuell Zeile 8–12):

```html
			<div v-if="identities.length > 1" class="compose-field compose-field--from">
				<label class="compose-field__label">{{ t('souvera_mail', 'From') }}</label>
				<NcSelect v-model="fromIdentity" :options="identities" label="label"
					:searchable="false" :clearable="false" />
			</div>
```

**Ersetze ihn genau durch diesen Block:**

```html
			<div v-if="identities.length > 1" class="compose-field compose-field--from">
				<label class="compose-field__label">{{ t('souvera_mail', 'From') }}</label>
				<select v-model="fromIdentityId" class="native-select">
					<option v-for="identity in identities" :key="identity.id" :value="identity.id">
						{{ identity.label }}
					</option>
				</select>
			</div>
			<div v-else-if="identities.length === 1" class="compose-field compose-field--from">
				<label class="compose-field__label">{{ t('souvera_mail', 'From') }}</label>
				<div class="compose-field__static-text">{{ identities[0].label }}</div>
			</div>
```

Erklärung: Wenn es **mehr als eine** Absender-Identität gibt, zeigen wir ein normales `<select>`-Dropdown (Zeile mit `v-if="identities.length > 1"`). Wenn es **genau eine** gibt (der weit häufigere Fall), zeigen wir gar kein Eingabefeld, sondern nur reinen Text (Zeile mit `v-else-if="identities.length === 1"`) — da gibt es nichts zum Auswählen, also braucht man auch keine Auswahlbox.

### Schritt 1.2 — In `<script>`-Teil, im `data()`-Block

**Suche diese Zeile** (aktuell Zeile 112):
```js
			fromIdentity: { id: null, name: '', email: '' },
```
**Ersetze sie durch:**
```js
			fromIdentityId: null,
```

### Schritt 1.3 — In der Methode `loadIdentities()`

**Suche diesen Block** (aktuell Zeile 168–177):
```js
		async loadIdentities() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/identities'))
				const list = (data.identities || []).map(i => ({ id: i.id, label: `${i.name || ''} <${i.email}>`, name: i.name, email: i.email }))
				this.identities = list
				if (list.length > 0) this.fromIdentity = list[0]
			} catch (e) {
				console.error('Failed to load identities', e)
			}
		},
```
**Ersetze die Zeile `if (list.length > 0) this.fromIdentity = list[0]` durch:**
```js
				if (list.length > 0) this.fromIdentityId = list[0].id
```
(Der Rest der Methode bleibt unverändert — nur diese eine Zeile wird ersetzt.)

### Schritt 1.4 — In der Methode `buildPayload()`

**Suche diese Zeile** (aktuell Zeile 222):
```js
				identityId: this.fromIdentity.id,
```
**Ersetze sie durch:**
```js
				identityId: this.fromIdentityId,
```

### Schritt 1.5 — CSS ergänzen

Suche im `<style scoped>`-Block die Regel `.compose-field__label` (aktuell Zeile 343–351). **Direkt danach**, als komplett neue Regel, diese zwei Blöcke einfügen:

```css
.native-select {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-height: 40px;
	padding: 6px 12px;
	width: 100%;
	box-sizing: border-box;
	font-size: 14px;
	font: inherit;
}
.compose-field__static-text {
	padding: 6px 0;
	font-size: 14px;
	color: var(--color-main-text);
}
```

### Schritt 1.6 — NcSelect-Import darf bleiben oder entfernt werden

`NcSelect` wird jetzt nirgends mehr benutzt. Du kannst den Import (`import { NcModal, NcButton, NcTextField, NcSelect } from '@nextcloud/vue'`, aktuell Zeile 66) so lassen wie er ist ODER `NcSelect` aus dem Import und aus der `components: {...}`-Liste entfernen. Beides ist ok, das ist nicht wichtig für den Fix — bitte hier **nicht** noch mehr Zeit verbringen.

---

## Änderung 2 von 2: To-Feld hat einen doppelten Rahmen

### Was ist kaputt
Es gibt in dieser Datei eine CSS-Regel, die **allen** `<input>`-Elementen innerhalb eines `.compose-field`-Blocks automatisch einen Rahmen gibt. Das war ursprünglich nur für einfache Felder wie "Subject" gedacht. Das Problem: Das To-Feld (Komponente `RecipientField.vue`) hat aber **bereits selbst** eine umrahmte Box (das ist die kompakte Box mit den kleinen Empfänger-"Chips" drin). Innerhalb dieser Box gibt es ein kleines, eigentlich unsichtbares Text-Eingabefeld zum Tippen neuer Empfänger. Die zu allgemeine CSS-Regel gibt diesem inneren, eigentlich unsichtbaren Eingabefeld **zusätzlich auch noch einen eigenen Rahmen** — deshalb sieht man zwei Rahmen ineinander.

### Schritt 2.1 — Suche diesen CSS-Block (aktuell Zeile 313–327):

```css
/* Shared form element style */
.compose-field :deep(.vs__dropdown-toggle),
.compose-field :deep(.v-select .vs__dropdown-toggle),
.compose-field :deep(.native-select),
.compose-field :deep(.input-field),
.compose-field :deep(input:not([type=file])) {
	border: 1px solid var(--color-border) !important;
	border-radius: var(--border-radius-large) !important;
	background: var(--color-main-background);
	min-height: 40px;
	padding: 6px 12px;
	width: 100% !important;
	box-sizing: border-box !important;
	font-size: 14px;
}
```

**Ersetze in genau dieser Regel nur die eine Zeile:**
```css
.compose-field :deep(input:not([type=file])) {
```
**durch:**
```css
.compose-field :deep(input:not([type=file]):not(.recipient-field__input)) {
```

Der Rest des Blocks (alle anderen Zeilen) bleibt **unverändert**. Diese eine Änderung sorgt dafür, dass das kleine Tipp-Eingabefeld im To/Cc/Bcc-Feld von dieser Regel ausgenommen wird und keinen eigenen Rahmen mehr bekommt.

### Schritt 2.2 — Direkt nach diesem Block (nach der schließenden `}` der eben geänderten Regel), diese komplett neue Regel einfügen:

```css
.compose-field :deep(.recipient-field__input) {
	border: none !important;
	background: transparent !important;
	width: auto !important;
	min-width: 60px !important;
	min-height: 0 !important;
	padding: 4px 0 !important;
	flex: 1 !important;
}
```

Das stellt sicher, dass das Tipp-Eingabefeld wieder genauso aussieht wie ursprünglich in `RecipientField.vue` vorgesehen: randlos, transparent, ohne eigene Box.

---

## Prüfschritte — bitte JEDEN Punkt einzeln durchgehen

1. `cd /projects/souvera_mail && npm run build` — muss ohne Fehler durchlaufen.
2. Compose-Modal öffnen (Klick auf "New message" bzw. "Neue Nachricht").
3. **Falls du testen kannst mit nur 1 Absender-Identität:** Es erscheint unter "FROM" nur reiner Text mit Name und E-Mail-Adresse, **kein** umrahmtes Feld, **kein** Dropdown-Pfeil, **kein** X-Icon.
4. **Falls es mehr als 1 Absender-Identität gibt:** Es erscheint unter "FROM" ein normales, umrahmtes Auswahlfeld mit sichtbarem Namen/E-Mail-Text drin (nicht leer!) und einem kleinen Pfeil zum Aufklappen rechts. Klick öffnet eine normale Liste zur Auswahl.
5. Im "TO"-Feld: Es gibt nur **eine** durchgehende Umrahmung um die gesamte Box (die mit den Empfänger-Chips), **keine** zweite kleinere Umrahmung im Inneren um das Textfeld zum Tippen.
6. Eine Test-E-Mail-Adresse ins To-Feld eintippen und mit Enter bestätigen — es muss ein Chip mit der Adresse erscheinen, und man muss weiter tippen können, ohne dass etwas "springt" oder falsch aussieht.
7. Nach dem Absenden einer Test-Mail: in der PHP-Fehler-Log bzw. beim Öffnen der versendeten Mail prüfen, dass der Absender ("From") korrekt gesetzt ist (nicht leer) — das testet, ob `identityId` beim Senden noch korrekt ankommt, nachdem `fromIdentity` in `fromIdentityId` umbenannt wurde.
