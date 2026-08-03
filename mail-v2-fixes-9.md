# souvera_mail v2 — Fix-Liste Runde 10: Subject-Doppelrahmen zurücknehmen + Klick-Fokus im Editor

Zwei getrennte Themen, zwei Dateien.

---

## 1. Subject: Rahmen-um-den-Rahmen zurücknehmen

**Datei:** `src-v2/components/ComposeEditor.vue`

### Ursache (jetzt zweifelsfrei geklärt, direkt aus der Nextcloud-Bibliothek gelesen)

In `node_modules/@nextcloud/vue/dist/assets/NcInputField-DpyFJ1xw.css` steht wörtlich als Kommentar: *"With Nextcloud 32+ there is no real border anymore but we use a box-shadow."* — das Subject-Feld (`NcTextField`) hat also **schon längst** einen Rahmen, nur wird er nicht mit der CSS-Eigenschaft `border` gezeichnet, sondern mit `box-shadow` (das ist ein moderner Trick, den Nextcloud selbst benutzt). Die Farbe dieses Box-Shadow-Rahmens kommt aus einer CSS-Variable namens `--input-border-color`.

In der letzten Runde haben wir **zusätzlich** einen echten `border: 1px solid ...` auf das Element gelegt. Jetzt gibt es zwei Rahmen: den originalen (unsichtbareren) Box-Shadow-Rahmen von Nextcloud, und unseren neuen, extra hinzugefügten `border` — das sieht aus wie "ein Rahmen um den Rahmen".

### Der Fix

Suche in `ComposeEditor.vue` diesen Block (aus der letzten Runde):
```css
.compose-field :deep(.input-field) {
	width: 100% !important;
	border: 1px solid var(--color-border) !important;
	border-radius: var(--border-radius-large) !important;
}
```

**Ersetze ihn genau durch diesen Block:**
```css
.compose-field :deep(.input-field) {
	width: 100% !important;
	--input-border-color: var(--color-border);
}
```

Erklärung: Wir fügen **keinen zweiten Rahmen** mehr hinzu. Statt einen eigenen `border` zu zeichnen, ändern wir nur die Farbe, die der **bereits vorhandene** Nextcloud-eigene Box-Shadow-Rahmen benutzt (`--input-border-color`), und setzen sie auf denselben Farbwert (`var(--color-border)`), den From/To auch benutzen. Das macht den bestehenden Rahmen kräftiger/dunkler, **ohne** einen zweiten Rahmen zu erzeugen.

**Bitte nicht wieder `border:` oder `border-radius:` in dieser Regel ergänzen** — genau das hat letztes Mal den Doppelrahmen verursacht.

---

## 2. Editor: Klick unterhalb des Textes setzt den Cursor nicht

**Datei:** `src-v2/components/composer/RichTextEditor.vue`

### Problem (Nutzer-Beschreibung)
Man muss genau auf die Zeile mit "Write your message..." bzw. auf eine vorhandene Textzeile klicken, um in den Editor zu kommen. Klickt man weiter unten in den scheinbar leeren, weißen Bereich (der optisch wie Teil des Editors aussieht), passiert nichts — man landet nicht im Editor und kann nicht schreiben.

### Ursache
Das eigentliche editierbare Element (von der Editor-Bibliothek TipTap erzeugt, Klasse `ProseMirror`) ist nur so hoch wie der tatsächlich vorhandene Text — bei "Write your message..." also nur eine Zeile hoch. Der weiße Bereich darunter *sieht* wie Teil des Editors aus (gleicher weißer Hintergrund), *ist* aber technisch schon außerhalb des klickbaren/editierbaren Elements — deshalb passiert beim Klicken dort nichts.

### Der Fix

**Schritt 2.1** — Suche im Template diese Zeile (aktuell Zeile 48):
```html
		<editor-content :editor="editor" class="richtext-editor__content" />
```
**Ersetze sie durch:**
```html
		<editor-content :editor="editor" class="richtext-editor__content" @click="focusEditor" />
```

**Schritt 2.2** — Suche im `<script>`-Teil den `methods: { ... }`-Block. Suche dort die Methode `focus()` (aktuell Zeile 114):
```js
		focus() { this.editor?.commands.focus() },
```
**Direkt danach eine neue Methode ergänzen:**
```js
		focusEditor() {
			if (this.editor && !this.editor.isFocused) {
				this.editor.commands.focus('end')
			}
		},
```

Erklärung: Jetzt reagiert der **gesamte weiße Bereich** (nicht nur die editierbare Textzeile selbst) auf Klicks. Wenn man irgendwo in diesen Bereich klickt und der Editor noch nicht den Fokus hat, wird automatisch ans Ende des vorhandenen Textes gesprungen und man kann direkt weiterschreiben — genau wie in Gmail/Outlook, wo man auch überall in den leeren Bereich klicken kann.

---

## Prüfschritte

1. `cd /projects/souvera_mail && npm run build` fehlerfrei.
2. Hard-Reload im Browser (Cache leeren!).
3. Subject-Feld: **ein** Rahmen, gleich kräftig wie From/To, kein zweiter Rahmen mehr sichtbar.
4. Compose-Fenster öffnen, in den weißen Bereich klicken **deutlich unterhalb** von "Write your message..." (z.B. in der Mitte des großen weißen Bereichs) — der Cursor muss erscheinen und man muss direkt losschreiben können.
5. Falls schon Text im Editor steht: Klick unterhalb des letzten geschriebenen Wortes setzt den Cursor ans Ende des Textes, man kann direkt weiterschreiben.
