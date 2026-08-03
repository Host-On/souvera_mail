# souvera_mail v2 — Fix-Liste Runde 4: Compose-Formular, konkrete Restfehler

Fortschritt aus Runde 3 ist sichtbar (To-Platzhalter korrekt, Cc/Bcc jetzt echte Pill-Buttons, Toolbar hat Hintergrund/Trenner) — aber drei Dinge sind noch kaputt bzw. neu hinzugekommen. Diesmal mit **exakter Ursache aus dem aktuellen Code** (nicht nur Vermutung), da die letzten CSS-Overrides bereits geprüft wurden. Wichtig: **bitte diesmal im Browser mit DevTools gegenprüfen, bevor du das als erledigt meldest** — die letzten zwei Runden CSS-Änderungen haben teilweise nicht gewirkt oder neue Nebenwirkungen erzeugt (Beweis: der horizontale Scrollbalken ist neu entstanden).

Betroffene Dateien:
- `src-v2/components/ComposeEditor.vue` (enthält aktuell viele `:deep()`-Overrides für Kind-Komponenten)
- `src-v2/components/composer/RichTextEditor.vue`

---

## 1. Horizontaler Scrollbalken unten im Modal — Ursache: fehlendes `box-sizing: border-box`

**Beobachtung:** Unter dem Editor, direkt über der Fußzeile, ist ein durchgehender horizontaler Scrollbalken sichtbar. Das Modal selbst soll niemals horizontal scrollen müssen.

**Root Cause:** In `RichTextEditor.vue` Zeile 155–158:
```css
.richtext-editor__content {
    flex: 1;
    min-height: v-bind(minHeight);
    padding: 12px 16px;
    ...
}
```
Diese Regel setzt **kein `box-sizing`**. Der Browser-Standard ist `content-box` — das heißt: die per Flex-Stretch berechnete Breite (100% der verfügbaren Fläche) bekommt das `padding` (16px links + 16px rechts) **zusätzlich draufgerechnet**, wodurch das Element insgesamt 32px breiter wird als sein Container erlaubt. Genau das erzeugt den horizontalen Overflow/Scrollbalken. Zusätzlich überschreibt `ComposeEditor.vue` Zeile 348–352 dieselbe Klasse per `:deep()` nochmal mit `padding: 12px 20px` (40px Gesamtbreite zusätzlich) — ebenfalls ohne `box-sizing`. Gleiches Problem potenziell bei `.richtext-editor__toolbar` (Padding `8px 12px` bzw. override `6px 20px`, beide ohne `box-sizing`).

**Fix:** In **`RichTextEditor.vue`** (nicht nur im Override in `ComposeEditor.vue` — dazu gleich mehr unter Punkt 3) an allen Stellen mit gleichzeitigem `padding` + prozentualer/Flex-Breite explizit `box-sizing: border-box` ergänzen:
```css
.richtext-editor,
.richtext-editor__toolbar,
.richtext-editor__content {
    box-sizing: border-box;
}
.richtext-editor__content :deep(.ProseMirror) {
    box-sizing: border-box;
}
```
Am saubersten: einmal ganz oben im `<style scoped>`-Block von `RichTextEditor.vue` einen lokalen Reset setzen:
```css
.richtext-editor, .richtext-editor *, .richtext-editor *::before, .richtext-editor *::after {
    box-sizing: border-box;
}
```
Das verhindert dieselbe Klasse von Bug dauerhaft für alle Kind-Elemente dieser Komponente.

---

## 2. Editor soll randlos 100% Breite/Höhe bis zur Fußzeile ausfüllen, ohne eigene Umrahmung

**Anforderung des Nutzers (wörtlich):** Das Textfeld soll ohne Margin/Padding auf 100% Breite des Modals stehen, und auf 100% Höhe bis zum Fuß des Modals reichen — **ohne eigenen Rahmen**, weil die graue Toolbar (mit ihrer unteren Trennlinie) und die Fußzeile (mit ihrer oberen Trennlinie) bereits die optische Abgrenzung liefern. Ein zusätzlicher Rahmen um das Textfeld erzeugt einen unnötigen "Doppelrahmen"-Look.

**Root Cause für den noch sichtbaren Rahmen:** Das Wurzel-`<div>` des Editor-Feldes trägt im Template (`ComposeEditor.vue` Zeile 30) **zwei Klassen gleichzeitig**: `compose-field compose-field--body`. Die allgemeine Basis-Regel für `.compose-field` (Zeile 298–302):
```css
.compose-field {
    padding: 10px 20px;
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}
```
setzt einen `border-bottom`. Die speziellere Regel `.compose-field--body` (Zeile 329–336) überschreibt zwar `padding` auf `0`, **überschreibt aber `border-bottom` nicht** — dadurch bleibt die von `.compose-field` vererbte Trennlinie am unteren Rand des gesamten Editor-Blocks bestehen und summiert sich optisch mit der Toolbar-eigenen `border-bottom` und ggf. weiteren Rand-Effekten zu dem im Screenshot sichtbaren umlaufenden Rahmen.

**Fix — konkret, Zeile für Zeile:**

In `ComposeEditor.vue`, Regel `.compose-field--body` (aktuell Zeile 329–336) ergänzen:
```css
.compose-field--body {
    padding: 0;
    margin: 0;
    border-bottom: none;      /* NEU — hebt die vererbte Trennlinie von .compose-field auf */
    flex: 1 1 auto;
    min-height: 250px;
    overflow: hidden;
    display: flex; flex-direction: column;
    min-width: 0;
}
```

**Grundsätzliche Empfehlung — Zuständigkeit vereinheitlichen statt weiter mit `:deep()` zu kämpfen:**
Aktuell wird das visuelle Erscheinungsbild des Editors an zwei Stellen gleichzeitig definiert: in `RichTextEditor.vue`'s eigenem `<style scoped>` UND per `:deep()`-Override in `ComposeEditor.vue` (Zeilen 337–357). Beide Regelsätze konkurrieren um dieselben Klassen (`.richtext-editor`, `.richtext-editor__toolbar`, `.richtext-editor__content`, `.ProseMirror`) mit vergleichbarer Spezifität — welche Regel gewinnt, hängt von der Reihenfolge im gebauten CSS-Bundle ab und ist damit fehleranfällig (das erklärt vermutlich, warum frühere Fixes teils nicht griffen). **Bitte konsolidieren:**
- Alle größen-/rahmen-relevanten Regeln (`width`, `height`, `border`, `box-sizing`, `padding` der inneren Editor-Struktur) sollen **ausschließlich in `RichTextEditor.vue`'s eigenem `<style>`-Block** stehen, so dass die Komponente sich selbst korrekt darstellt, unabhängig davon, wer sie einbindet.
- `RichTextEditor.vue`, `<style scoped>`, Ziel-Zustand:
  ```css
  .richtext-editor {
      display: flex; flex-direction: column;
      width: 100%; height: 100%;
      background: var(--color-main-background);
      border: none;
  }
  .richtext-editor__toolbar {
      display: flex; align-items: center; gap: 2px;
      padding: 8px 12px;
      border-bottom: 1px solid var(--color-border);
      background: var(--color-background-hover);
      flex-wrap: wrap; flex-shrink: 0;
  }
  .richtext-editor__content {
      flex: 1;
      width: 100%;
      min-height: v-bind(minHeight);
      padding: 16px 20px;   /* nur Innenabstand fürs Schreibgefühl, KEIN Rahmen */
      font-size: 14px; line-height: 1.6;
      overflow-y: auto;
      border: none;
  }
  ```
  (jeweils mit dem `box-sizing: border-box`-Reset aus Punkt 1 kombiniert, damit das `padding` hier keinen neuen Overflow erzeugt.)
- In `ComposeEditor.vue` dann die konkurrierenden `:deep()`-Regeln für `.richtext-editor`, `.richtext-editor__toolbar`, `.richtext-editor__content`, `.ProseMirror` (aktuell Zeile 337–357) **entfernen** — `ComposeEditor.vue` soll nur noch die STRUKTURELLE Einpassung vorgeben (dass `.compose-field--body` als Flex-Container ohne eigenen Rand/Padding den vollen restlichen Platz bekommt), nicht mehr die inneren Detail-Stile der Kind-Komponente überschreiben.
- Horizontale Ausrichtung: Damit der Editor-Innenabstand (`16px 20px`) optisch zum Rest des Formulars passt (Toolbar hat `padding: 8px 12px`, Fußzeile hat `padding: 10px 20px`), den linken/rechten Innenabstand von Toolbar und Editor-Inhalt aufeinander abstimmen (z.B. beide auf `20px` links/rechts vereinheitlichen), damit Icons und Text vertikal auf einer gemeinsamen Linie stehen.

---

## 3. From-Feld: Phantom-Box zwischen Namens-Chip und X/Chevron ist immer noch da

**Beobachtung:** Trotz des CSS-Versuchs in Runde 3 (`ComposeEditor.vue` Zeile 305–310, erzwingt `width:0 !important` etc. auf `.vs__search` / `.vs__selected-options input`) ist im neuen Screenshot weiterhin eine kleine, leere, eigenständig umrahmte weiße Box zwischen dem Namens-Chip "Philip Grassegger <info@host-on.de>" und dem X-Icon sichtbar. Das CSS-Gegensteuern gegen die internen vue-select-Klassen greift offenbar nicht zuverlässig (möglicherweise weil vue-select die Breite des Such-Inputs per **inline `style`-Attribut per JavaScript** setzt, was externes CSS auch mit `!important` nicht immer zuverlässig überschreiben kann, oder weil die tatsächliche DOM-Struktur dieser NcSelect-Version andere Klassennamen verwendet als angenommen).

**Robusterer Fix statt weiterer CSS-Battles:** Da unter 1–2 Absender-Identitäten ein Such-Feld im Dropdown ohnehin keinen Nutzen hat, das Such-Input **strukturell entfernen** statt es per CSS zu verstecken:

```html
<!-- ComposeEditor.vue, Zeile 10 -->
<NcSelect v-model="fromIdentity" :options="identities" label="label"
    :searchable="false"
    :clearable="false" />
```
- `:searchable="false"` entfernt laut vue-select/NcSelect-API das interne Such-`<input>` komplett aus dem DOM — damit verschwindet die Phantom-Box strukturell, nicht nur optisch versteckt.
- `:clearable="false"` entfernt zusätzlich das X-Icon — ein Absender-Feld sollte sich ohnehin nicht auf "leer" zurücksetzen lassen (man braucht immer einen Absender), das X ergibt hier keinen Sinn.
- Falls `:searchable="false"` in der installierten `@nextcloud/vue`-Version keine Wirkung zeigt (kurz im Browser prüfen), ersatzweise das komplette CSS-Override-Konstrukt aus Zeile 304–310 entfernen und stattdessen direkt im Browser-DevTools nachsehen, welches tatsächliche DOM-Element die leere Box erzeugt (Rechtsklick auf die Box → "Untersuchen"), und exakt dessen Klasse/Selektor gezielt behandeln statt der bisherigen Mehrfach-Selektor-Vermutung.

---

## Verifikation (bitte diesmal tatsächlich im Browser, nicht nur Code-Review)

1. `cd /projects/souvera_mail && npm run build` fehlerfrei.
2. Compose-Modal öffnen, Browser-Fenster auf ca. 900px Breite: **kein** horizontaler Scrollbalken irgendwo im Modal, auch nicht beim Tippen von langem Text oder Umbrechen von Zeilen im Editor.
3. Editor-Textfeld: keine sichtbare Umrandung um den Schreibbereich; die einzigen sichtbaren horizontalen Linien sind die Toolbar-Unterkante (oben) und die Fußzeilen-Oberkante (unten); Editor füllt die komplette Breite bis an die Toolbar-Icons heran (gleiche linke/rechte Flucht wie Toolbar-Padding) und die komplette Höhe bis zur Fußzeile.
4. From-Feld: kein leeres/leeres zusätzliches Kästchen mehr neben dem Namens-Chip; kein X-Icon mehr (da `clearable:false`); Dropdown-Pfeil bleibt, falls mehrere Identitäten vorhanden sind.
5. Fenstergröße variieren (900px → 500px) und erneut auf Scrollbalken/Umbruch-Verhalten prüfen — Layout darf nicht "springen".
