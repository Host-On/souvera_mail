# souvera_mail v2 — Fix-Liste Runde 8: Scrollbalken IM Editor-Textfeld endgültig beseitigen

From und To sind laut Rückmeldung jetzt erledigt — **bitte daran nichts mehr ändern**, egal was in diesem Dokument steht.

Nur eine Datei ändern: **`/projects/souvera_mail/src-v2/components/composer/RichTextEditor.vue`**

---

## Was ist kaputt und warum (diesmal mit technischer Erklärung, nicht nur "irgendwas mit CSS")

Der Scrollbalken erscheint **innerhalb** der Editor-Box (nicht im ganzen Fenster) — das ist ein wichtiger Hinweis. Grund: In der Datei steht aktuell diese Regel (Zeile 158–166):
```css
.richtext-editor__content {
	flex: 1;
	width: 100%;
	min-height: v-bind(minHeight);
	padding: 10px 20px;
	font-size: 14px; line-height: 1.6;
	overflow-y: auto;
	border: none;
}
```
Hier steht `overflow-y: auto` (nur für die Höhe), aber **kein** `overflow-x` (für die Breite). Das ist bei Browsern eine Besonderheit: Wenn man nur `overflow-y` setzt und `overflow-x` weglässt, macht der Browser automatisch **auch** `overflow-x: auto` daraus (das ist eine feste Browser-Regel, kein Zufall). Das heißt: Sobald irgendetwas im Editor — auch nur 1 Pixel — breiter ist als die Box, erscheint sofort ein horizontaler Scrollbalken **in genau dieser Box**. Das erklärt exakt das Symptom aus der Rückmeldung.

## Der Fix — zwei Zeilen ändern

Suche genau diesen Block (aktuell Zeile 158–166):
```css
.richtext-editor__content {
	flex: 1;
	width: 100%;
	min-height: v-bind(minHeight);
	padding: 10px 20px;
	font-size: 14px; line-height: 1.6;
	overflow-y: auto;
	border: none;
}
```

**Ersetze ihn genau durch diesen Block:**
```css
.richtext-editor__content {
	flex: 1;
	width: 100%;
	min-height: v-bind(minHeight);
	padding: 8px 16px;
	font-size: 14px; line-height: 1.6;
	overflow-y: auto;
	overflow-x: hidden;
	border: none;
}
```

Zwei Änderungen:
1. `overflow-x: hidden;` neu ergänzt — das verhindert den horizontalen Scrollbalken in dieser Box **strukturell und garantiert**, egal was die genaue Ursache der Breite ist.
2. `padding: 10px 20px;` → `padding: 8px 16px;` — etwas weniger Innenabstand, wie gewünscht ("zu viel Abstand zum Rand").

## Zusätzlich — damit lange Wörter/Links nicht trotzdem seitlich überstehen

Suche diesen Block (aktuell Zeile 167–171):
```css
.richtext-editor__content :deep(.ProseMirror) {
	outline: none;
	min-height: v-bind(minHeight);
	width: 100%;
}
```
**Ersetze ihn durch:**
```css
.richtext-editor__content :deep(.ProseMirror) {
	outline: none;
	min-height: v-bind(minHeight);
	width: 100%;
	word-break: break-word;
	overflow-wrap: break-word;
}
```
Das sorgt dafür, dass auch sehr lange, zusammenhängende Texte (z.B. eine lange E-Mail-Adresse oder ein langer Link ohne Leerzeichen) automatisch umgebrochen werden, statt die Box seitlich zu sprengen.

---

## Prüfschritte

1. `cd /projects/souvera_mail && npm run build` fehlerfrei.
2. Hard-Reload im Browser (Cache leeren!).
3. Compose-Fenster öffnen, viel Text in den Editor tippen (auch ein paar sehr lange Wörter ohne Leerzeichen zum Testen) — **kein** horizontaler Scrollbalken darf innerhalb der Editor-Box erscheinen, egal wie viel oder was man tippt.
4. Der Text im Editor beginnt mit etwas weniger Abstand vom linken/oberen Rand als vorher.
5. Falls danach immer noch irgendwo ein horizontaler Scrollbalken auftaucht (egal ob im Editor oder anderswo im Fenster): bitte dieses Diagnose-Skript in der Browser-Konsole ausführen (F12 → Tab "Console", Text einfügen, Enter):
```js
document.querySelectorAll('.compose-layout *').forEach(el => {
  if (el.scrollWidth > el.clientWidth + 1) {
    console.log('ÜBERLAUF:', el.scrollWidth - el.clientWidth, 'px zu breit —', el.className || el.tagName, el)
  }
})
```
Die Ausgabe genau mitteilen (Klassenname + Pixel-Zahl), bevor weitere Änderungen gemacht werden.
