# souvera_mail v2 — Fix-Liste Runde 7: Subject-Doppelbox, Scrollbalken endgültig finden, Editor-Innenabstand

**Bitte genau in dieser Reihenfolge vorgehen. Nach jedem Schritt speichern, `npm run build`, dann Hard-Reload im Browser (Cache leeren!) und erst dann weiterschauen.**

Nur eine Datei ist betroffen: **`/projects/souvera_mail/src-v2/components/ComposeEditor.vue`**
(Für Schritt 3 zusätzlich: `/projects/souvera_mail/src-v2/components/composer/RichTextEditor.vue`)

---

## 1. Subject-Feld sieht falsch aus (doppelte Umrahmung) — jetzt mit Beweis, keine Vermutung mehr

Ich habe diesmal direkt in den installierten `@nextcloud/vue`-Dateien nachgesehen (Datei `node_modules/@nextcloud/vue/dist/assets/NcInputField-DpyFJ1xw.css`), um die **echten** Klassennamen zu finden, die `NcTextField` (das Subject-Feld) tatsächlich im Browser erzeugt. Ergebnis: Es gibt eine äußere Box mit Klasse `.input-field` (das ist die Umrahmung, die man sieht) und **darin** ein echtes `<input>`-Element mit der Klasse `.input-field__input`.

### Das Problem
Suche im `<style scoped>`-Block von `ComposeEditor.vue` diesen Block (aktuell etwa Zeile 327–341):

```css
/* Shared form element style */
.compose-field :deep(.vs__dropdown-toggle),
.compose-field :deep(.v-select .vs__dropdown-toggle),
.compose-field :deep(.native-select),
.compose-field :deep(.input-field),
.compose-field :deep(input:not([type=file]):not(.recipient-field__input)) {
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

Hier passiert Folgendes: Die Zeile `.compose-field :deep(.input-field)` gibt der **äußeren** Box vom Subject-Feld einen Rahmen. Die Zeile `.compose-field :deep(input:not([type=file])...)` gibt **zusätzlich auch noch** dem `<input>`-Element **innerhalb** dieser Box (also `.input-field__input`) einen eigenen Rahmen. Ergebnis: zwei Rahmen ineinander — genau wie vorher beim To-Feld, nur jetzt beim Subject-Feld.

### Der Fix — ersetze den kompletten obigen Block durch diesen hier:

```css
/* Shared form element style — nur für Elemente ohne eigenes Nextcloud-Design */
.compose-field :deep(.vs__dropdown-toggle),
.compose-field :deep(.v-select .vs__dropdown-toggle),
.compose-field :deep(.native-select) {
	border: 1px solid var(--color-border) !important;
	border-radius: var(--border-radius-large) !important;
	background: var(--color-main-background);
	min-height: 40px;
	padding: 6px 12px;
	width: 100% !important;
	box-sizing: border-box !important;
	font-size: 14px;
}

/* NcTextField (Subject) hat bereits sein eigenes Nextcloud-Design —
   wir zwingen ihm keinen eigenen Rahmen auf, sondern lassen es nur
   die volle Breite einnehmen. */
.compose-field :deep(.input-field) {
	width: 100% !important;
}
```

**Wichtig:** Die Zeile `.compose-field :deep(input:not([type=file]):not(.recipient-field__input))` gibt es in diesem neuen Block **nicht mehr** — die wird komplett gestrichen, sie war die Ursache des Problems.

### Direkt danach — diesen Block darfst du unverändert lassen (er wird jetzt einfach nicht mehr benötigt, schadet aber auch nicht, wenn er bleibt):
```css
.compose-field :deep(.recipient-field__input) {
	border: none !important;
	...
}
```
(Diesen NICHT löschen müssen, einfach so lassen wie er ist.)

---

## 2. Horizontaler Scrollbalken — diesmal mit einem Diagnose-Werkzeug statt Raten

Der Scrollbalken kommt in jeder Runde wieder, obwohl wir schon mehrfach versucht haben, ihn zu fixen. Deshalb diesmal ein anderer Ansatz: **finde die exakte Ursache mit einem kleinen Test-Skript**, bevor du irgendwas änderst.

### Schritt 2.1 — Diagnose im Browser

1. Compose-Modal öffnen (z.B. auf "Reply" klicken, so wie im Screenshot).
2. Browser-DevTools öffnen (F12 oder Rechtsklick → "Untersuchen").
3. Auf den Reiter "Console" klicken.
4. Folgenden Text **exakt** in die Konsole einfügen und Enter drücken:

```js
document.querySelectorAll('.compose-layout *').forEach(el => {
  if (el.scrollWidth > el.clientWidth + 1) {
    console.log('ÜBERLAUF:', el.scrollWidth - el.clientWidth, 'px zu breit —', el.className || el.tagName, el)
  }
})
```

5. Die Konsole zeigt jetzt eine Liste von Elementen, die breiter sind als ihr verfügbarer Platz — mit exakter Pixel-Angabe, wie viel zu breit sie sind, und dem genauen Element.

### Schritt 2.2 — Fix anhand der Ausgabe

- Wenn dort ein Element mit Klasse `input-field` oder `input-field__input` (Subject) auftaucht: das sollte nach Fix 1 (oben) bereits verschwunden sein — Fix 1 zuerst umsetzen, dann Schritt 2.1 **erneut** ausführen.
- Wenn danach immer noch etwas in der Liste erscheint: bitte den exakten Text aus der Konsole (Klassenname + Pixel-Zahl) kopieren und mitteilen, bevor weitere CSS-Änderungen gemacht werden — anhand der genauen Meldung kann die Ursache gezielt behoben werden, statt weiter zu raten.
- Häufigste Lösung, falls z.B. `richtext-editor__toolbar` oder `richtext-editor__content` in der Liste steht: dort fehlt `box-sizing: border-box` — diese eine Zeile zur jeweiligen CSS-Regel in `RichTextEditor.vue` hinzufügen.

---

## 3. Editor-Textfeld: zu viel Innenabstand oben/unten

**Datei:** `src-v2/components/composer/RichTextEditor.vue`

Suche diesen Block (aktuell etwa Zeile 158–166):
```css
.richtext-editor__content {
	flex: 1;
	width: 100%;
	min-height: v-bind(minHeight);
	padding: 16px 20px;
	font-size: 14px; line-height: 1.6;
	overflow-y: auto;
	border: none;
}
```
**Ändere nur die eine Zeile `padding: 16px 20px;` zu `padding: 10px 20px;`** (weniger Abstand oben/unten, gleicher Abstand links/rechts wie bei den anderen Feldern). Alles andere in diesem Block bleibt unverändert.

---

## Prüfschritte

1. `npm run build` fehlerfrei.
2. Hard-Reload im Browser (Cache leeren!).
3. Subject-Feld: nur **eine** durchgehende Umrahmung, keine zweite Box im Inneren.
4. Konsolen-Diagnose aus Schritt 2.1 ausführen: Liste sollte jetzt **leer** sein (keine Ausgabe). Falls nicht leer: die Ausgabe genau mitteilen, bevor weiter am Scrollbalken gebastelt wird.
5. Kein horizontaler Scrollbalken mehr irgendwo im Compose-Fenster, auch nicht beim Tippen von viel Text in Editor oder Subject.
6. Editor-Textfeld: etwas weniger Abstand oben (direkt unter der Toolbar) und unten (vor dem Ende des Schreibbereichs) als vorher, Text wirkt insgesamt kompakter/enger am Rand.
