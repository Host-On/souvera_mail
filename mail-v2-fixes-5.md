# souvera_mail v2 — Fix-Liste Runde 6: Dropdown-Pfeil bei From + Editor-Rahmen/Lücke

**Bitte zuerst ausführen, bevor du irgendetwas änderst:**
1. `cd /projects/souvera_mail && npm run build`
2. Im Browser das Compose-Fenster öffnen mit einem **erzwungenen Hard-Reload ohne Cache** (Chrome/Edge: DevTools öffnen → Rechtsklick auf den Reload-Button → "Cache leeren und vollständig neu laden"; Firefox: Strg+Umschalt+R).

Grund: Im Quellcode steht für den Editor bereits `border: none` (in `RichTextEditor.vue`, Zeile 140 und 165) — wenn im Browser trotzdem noch ein Rahmen um den Editor zu sehen ist, ist es sehr wahrscheinlich, dass der Browser noch die **alte** JavaScript/CSS-Datei aus dem Cache anzeigt, nicht die aktuell gebaute Version. Bitte das oben zuerst prüfen, bevor du am Editor-Rahmen (Abschnitt 2 unten) weiterarbeitest — vielleicht ist er nach einem echten Hard-Reload schon weg.

Betroffene Dateien:
- `src-v2/components/ComposeEditor.vue`
- `src-v2/components/composer/RichTextEditor.vue`

---

## 1. From-Feld: kleinen Dropdown-Pfeil rechts ergänzen (rein optisch)

Das From-Feld funktioniert jetzt inhaltlich korrekt. Es fehlt nur noch ein kleines Pfeil-Symbol (Chevron nach unten) rechts im Feld, damit man auf den ersten Blick sieht "das ist ein Auswahlfeld". Dieses Symbol ist **nur Dekoration** — es muss nicht unbedingt funktional etwas tun (bei nur 1 Absender gibt es ja nichts zum Umschalten).

### Schritt 1.1 — Icon importieren

In `ComposeEditor.vue`, im `<script>`-Teil, ganz oben bei den anderen Icon-Imports (aktuell Zeile 74–76):
```js
import Send from 'vue-material-design-icons/Send.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
```
**Direkt danach eine neue Zeile ergänzen:**
```js
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
```

Dann bei `components: { ... }` (aktuell Zeile 89):
```js
components: { NcModal, NcButton, NcTextField, NcSelect, Send, Paperclip, TrashCan, RecipientField, RichTextEditor, AttachmentList },
```
**`ChevronDown` in diese Liste mit aufnehmen**, also:
```js
components: { NcModal, NcButton, NcTextField, NcSelect, Send, Paperclip, TrashCan, ChevronDown, RecipientField, RichTextEditor, AttachmentList },
```

### Schritt 1.2 — Icon ins Template einbauen

Suche im Template diesen Block (aktuell Zeile 8–19):
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

**Ersetze ihn genau durch diesen Block** (jedes Feld bekommt jetzt einen umschließenden `<div class="compose-field__select-wrap">` mit dem Pfeil-Icon daneben):
```html
			<div v-if="identities.length > 1" class="compose-field compose-field--from">
				<label class="compose-field__label">{{ t('souvera_mail', 'From') }}</label>
				<div class="compose-field__select-wrap">
					<select v-model="fromIdentityId" class="native-select">
						<option v-for="identity in identities" :key="identity.id" :value="identity.id">
							{{ identity.label }}
						</option>
					</select>
					<ChevronDown :size="18" class="compose-field__select-icon" />
				</div>
			</div>
			<div v-else-if="identities.length === 1" class="compose-field compose-field--from">
				<label class="compose-field__label">{{ t('souvera_mail', 'From') }}</label>
				<div class="compose-field__select-wrap">
					<div class="compose-field__static-text">{{ identities[0].label }}</div>
					<ChevronDown :size="18" class="compose-field__select-icon" />
				</div>
			</div>
```

### Schritt 1.3 — CSS ergänzen

Suche im `<style scoped>`-Block diese Regel (aktuell Zeile 382–386):
```css
.compose-field__static-text {
	padding: 6px 0;
	font-size: 14px;
	color: var(--color-main-text);
}
```
**Direkt davor** diese zwei neuen Regeln einfügen:
```css
.compose-field__select-wrap {
	position: relative;
}
.compose-field__select-icon {
	position: absolute;
	right: 12px;
	top: 50%;
	transform: translateY(-50%);
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}
```
Und die native-select-Regel (aktuell Zeile 370–381) um etwas Platz für das Icon rechts ergänzen — suche:
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
```
**Ersetze `padding: 6px 12px;` in dieser Regel durch `padding: 6px 36px 6px 12px;`** (mehr Platz rechts, damit der Text nicht unter dem Pfeil-Icon verschwindet). Und bei `.compose-field__static-text` genauso `padding: 6px 0;` durch `padding: 6px 36px 6px 0;` ersetzen.

**Hinweis:** Da `select` von Natur aus schon einen eigenen Browser-Pfeil mitbringt, könnte es nun **zwei** Pfeile geben (den nativen Browser-Pfeil UND unser neues Icon). Falls das im Browser so aussieht (zwei Pfeile nebeneinander), bei `.native-select` zusätzlich diese Zeile ergänzen, um den nativen Browser-Pfeil zu verstecken:
```css
.native-select {
	appearance: none;
	-webkit-appearance: none;
	-moz-appearance: none;
}
```

---

## 2. Editor: Rahmen entfernen und Lücke zur Fußzeile schließen

**Nur weitermachen, wenn nach einem echten Hard-Reload (siehe ganz oben) der Rahmen um das Schreibfeld immer noch da ist!** Falls er nach dem Hard-Reload weg ist, kannst du diesen Abschnitt komplett ignorieren.

Falls der Rahmen weiterhin da ist:

### Schritt 2.1 — Rahmen mit maximaler Priorität entfernen

In `ComposeEditor.vue`, suche diesen Block (aktuell Zeile 346–358):
```css
/* #5: Editor fills full modal size */
.compose-field--body {
	padding: 0; margin: 0;
	border: none; border-bottom: none;
	flex: 1 1 auto;
	min-height: 250px;
	overflow: hidden;
	display: flex; flex-direction: column;
	min-width: 0;
}
.compose-field--body :deep(.richtext-editor) {
	flex: 1; height: auto;
}
```
**Ersetze ihn durch:**
```css
/* #5: Editor fills full modal size */
.compose-field--body {
	padding: 0; margin: 0;
	border: none; border-bottom: none;
	flex: 1 1 auto;
	min-height: 250px;
	overflow: hidden;
	display: flex; flex-direction: column;
	min-width: 0;
}
.compose-field--body :deep(.richtext-editor) {
	flex: 1 1 auto;
	height: auto;
	min-height: 0 !important;
	border: none !important;
}
.compose-field--body :deep(.richtext-editor__content) {
	border: none !important;
	min-height: 0 !important;
}
.compose-field--body :deep(.ProseMirror) {
	border: none !important;
	min-height: 0 !important;
}
```

Erklärung der Zeile `min-height: 0 !important`: In verschachtelten Flexbox-Layouts (Spalte in Spalte) verhindert das fehlende `min-height: 0` manchmal, dass ein Kind-Element sich korrekt bis zum Ende des verfügbaren Platzes ausdehnt — es bleibt dann auf seiner "natürlichen" Mindesthöhe stehen und lässt darunter eine Lücke frei. Das könnte die Lücke vor der Fußzeile erklären.

### Schritt 2.2 — Falls der Rahmen DANACH immer noch da ist

Falls du nach Schritt 2.1 (und einem erneuten `npm run build` + Hard-Reload) den Rahmen **immer noch** siehst: Bitte im Browser mit Rechtsklick direkt auf den sichtbaren Rahmen klicken → "Untersuchen" (Inspect). Im sich öffnenden DevTools-Fenster im rechten Bereich unter "Styles" bzw. "Computed" nachsehen, welche CSS-Regel (mit welcher Datei/Zeile) den `border` tatsächlich setzt, und genau diese Regel entfernen oder auf `border: none` ändern. Bitte nicht weiter raten/CSS-Regeln nach Vermutung hinzufügen, sondern die tatsächlich im Browser aktive Regel identifizieren.

---

## Prüfschritte

1. `npm run build` fehlerfrei.
2. Hard-Reload im Browser (Cache leeren!).
3. From-Feld zeigt jetzt rechts ein kleines graues Pfeil-Symbol (nach unten zeigend), egal ob 1 oder mehrere Identitäten vorhanden sind. Nur **ein** Pfeil sichtbar, nicht zwei.
4. Editor-Schreibfeld: keine sichtbare Umrahmung mehr (kein Rahmen-Rechteck um den Text-Bereich), und das Schreibfeld reicht direkt bis zur Fußzeile herunter, ohne weißen Leerraum dazwischen.
5. Alles andere (From-Text, To-Feld, Subject-Feld, Cc/Bcc-Buttons) unverändert lassen — hier nichts zusätzlich anfassen.
