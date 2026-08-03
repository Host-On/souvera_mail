# souvera_mail v2 — Fix-Liste Runde 3: Compose-Formular Optik/Layout

Der Nutzer hat zwei Screenshots des "New message"-Compose-Modals geschickt. **Du (der umsetzende Agent) kannst diese Screenshots nicht sehen** — dieses Dokument beschreibt deshalb jedes optische Problem in Worten, so präzise wie möglich, mit exakten Datei-Referenzen. Ziel: ein einheitliches, modernes, zum restlichen Nextcloud-Layout passendes Formular-Design für den Composer.

Betroffene Dateien:
- `src-v2/components/ComposeEditor.vue` (Formular-Gerüst/Layout)
- `src-v2/components/composer/RecipientField.vue` (To/Cc/Bcc-Felder)
- `src-v2/components/composer/RichTextEditor.vue` (Editor + Toolbar)

---

## Ausgangslage: Das Formular wirkt wie aus 4 verschiedenen Baukästen zusammengesetzt

Jede Feld-Art im Compose-Modal hat aktuell eine **komplett andere visuelle Sprache**: das From-Feld (NcSelect) sieht anders aus als das To-Feld (RecipientField-Chips), die Cc/Bcc-Umschalter sehen aus wie reiner Fließtext ohne jede Button-Optik, die Editor-Toolbar ist eine nackte Reihe von Icons ohne Rahmen, und der Editor-Inhalt selbst ist eine winzige Box statt eines vollwertigen Schreibbereichs. Das Formular braucht **eine** konsistente Design-Sprache über alle Felder hinweg. Die bereits vorhandene Caption-Optik (kleine graue GROSSBUCHSTABEN-Beschriftung "FROM" / "TO" / "SUBJECT" direkt über jedem Feld, gefolgt von einer abgerundeten, umrahmten Box) ist der **richtige Ausgangspunkt** — dieses Muster soll konsequent auf alle Felder inkl. Cc/Bcc-Umschalter und Editor-Toolbar übertragen werden, statt es nur für From/Subject zu benutzen.

---

## 1. From-Feld (NcSelect): zu breit, hässlich abgeschnitten, teilweise doppelte Box

**Datei:** `ComposeEditor.vue`, Zeile 8–12 (NcSelect für `fromIdentity`)

**Beobachtung:**
- Die Box ist über die **gesamte Modal-Breite** gezogen (≈860px), obwohl der Inhalt ("Philip Grassegger <p.grassegger@host-on.de>") viel kürzer ist. Dadurch entsteht eine lange, größtenteils leere Box mit dem Text linksbündig gequetscht und X/Chevron-Icons weit rechts isoliert.
- Der angezeigte Name/E-Mail-Text wird an einer ungünstigen Stelle abgeschnitten (z.B. "Philip Grasse…  ost-on.de>") — es wird offenbar der komplette String `"Name" <email>` als EIN zusammenhängender Text behandelt und dann irgendwo in der Mitte mit Ellipsis gekürzt, statt Name und E-Mail als zwei getrennt behandelte, sinnvoll gekürzte Teile darzustellen (z.B. Name normal, E-Mail-Domain wenn nötig kürzen, nicht der Gesamtstring).
- In einem zweiten Screenshot erscheint **zusätzlich eine zweite, komplett leere, eigenständig umrahmte rechteckige Box** direkt rechts neben der Werte-Box, bevor X und Chevron kommen — sieht aus wie ein durchgesickertes internes Such-Input-Feld der Select-Komponente (vue-select-Komponenten haben intern ein `<input>` für die Sucheingabe, das bei vielen Themes unsichtbar/eingebettet sein soll, hier aber als eigene sichtbare Box mit eigenem Rahmen neben dem Wert erscheint). Dieses Verhalten war zwischen den beiden Screenshots nicht identisch — die Höhe der From-Zeile unterschied sich auch leicht (einmal ca. 60px, einmal ca. 44px) — das Layout ist also nicht stabil/deterministisch.

**Gewünschtes Ergebnis:**
- Da im Normalfall (ein Absender-Konto) **nur eine Identität** existiert, sollte hier idealerweise **gar kein Dropdown angezeigt werden**, sondern reiner Text "Philip Grassegger <p.grassegger@host-on.de>" ohne Rahmen, ohne X/Chevron (das ist im vorherigen Fix-Dokument `mail-v2-fixes.md`, Abschnitt 2, bereits als `v-if="identities.length > 1"` vorgesehen — bitte sicherstellen, dass dieser Guard tatsächlich greift und der Screenshot nicht einen Zustand mit fälschlich >1 Identitäten zeigt).
- Falls wirklich mehrere Identitäten existieren und das Dropdown gebraucht wird: Box auf eine sinnvolle **max-width** begrenzen (z.B. `max-width: 360px`, nicht 100% der Modal-Breite), Name und E-Mail-Adresse getrennt mit jeweils eigenem `text-overflow: ellipsis` auf einem festen Innen-Layout (Name fett, E-Mail-Adresse in `var(--color-text-maxcontrast)` daneben oder darunter, jeweils einzeilig gekürzt statt den kombinierten String zu kürzen).
- Das "doppelte Box"-Artefakt beheben: In den Nextcloud-`@nextcloud/vue`-NcSelect-Styles/vue-select-internen Klassen (`.vs__search`, `.vs__selected-options`) sicherstellen, dass das interne Such-Input bei vorhandenem ausgewähltem Wert **keine eigene sichtbare Breite/Rahmen** bekommt (typischerweise via `width: 0` oder `flex-basis: 0` wenn nicht fokussiert, bzw. das Nextcloud-Standard-Styling von NcSelect korrekt einbinden/nicht durch eigenes Scoped-CSS überschreiben). Am einfachsten im Browser-DevTools nachvollziehen: NcSelect isoliert (ohne die eigene `class="compose-layout__from"`-Regel) testen, ob der Fehler dann verschwindet — falls ja, liegt es an einer zu aggressiven eigenen CSS-Regel, die mit vue-selects internem Markup kollidiert.

---

## 2. To-Feld: Platzhalter zeigt nur "…" statt "To…"

**Dateien:** `ComposeEditor.vue` Zeile 15 (`<RecipientField v-model="to" :label="t('souvera_mail', 'To') + '…'" />`) und `RecipientField.vue` Zeile 8–13 + 37–39, 49–51.

**Root Cause (konkret verifiziert):** `ComposeEditor.vue` übergibt die Prop `:label="...+'…'"`, aber `RecipientField.vue` besitzt zwar eine `label`-Prop (Zeile 37), **verwendet sie im gesamten Template aber nirgends** — es ist eine toter Prop. Das tatsächlich im Input gerenderte Platzhalter-Attribut kommt aus der `placeholder`-Prop (Zeile 38), die `ComposeEditor.vue` aber nie setzt. Dadurch greift in der `placeholderText`-Computed (Zeile 49–51) immer der Fallback: `this.placeholder || '…'` → da `placeholder` leer ist, bleibt nur die literale Ellipse `'…'` übrig — exakt das, was der Screenshot zeigt ("..." statt "To...", "Cc..." oder "Bcc...").

**Fix:** In `ComposeEditor.vue` bei allen drei `RecipientField`-Verwendungen (Zeile 15, 20, 21) die Prop von `:label` auf `:placeholder` umbenennen:
```html
<RecipientField v-model="to" :placeholder="t('souvera_mail', 'To') + '…'" />
...
<RecipientField v-if="showCc || cc.length > 0" v-model="cc" :placeholder="t('souvera_mail', 'Cc') + '…'" />
<RecipientField v-if="showBcc || bcc.length > 0" v-model="bcc" :placeholder="t('souvera_mail', 'Bcc') + '…'" />
```
(Die ungenutzte `label`-Prop in `RecipientField.vue` kann bleiben oder entfernt werden — falls eine sichtbare Feld-Caption "TO"/"CC"/"BCC" im neuen einheitlichen Design gewünscht ist, siehe Abschnitt 5, dann sollte sie stattdessen dafür verwendet werden statt für den Platzhalter.)

---

## 3. Cc/Bcc-Umschalter: sehen aus wie reiner Text, nicht wie Buttons

**Datei:** `ComposeEditor.vue`, Zeile 16–19 (`compose-toggle-row` mit zwei `NcButton variant="tertiary" size="small"`)

**Beobachtung:** "Cc" und "Bcc" erscheinen im Screenshot als schwarze, nicht unterstrichene Wörter ohne jede erkennbare Button-Hülle (kein Rahmen, kein Hintergrund, kein Hover-Effekt sichtbar) — sie sehen aus wie normaler Absatztext, nicht wie klickbare Umschalter. Das ist zwar technisch bereits `NcButton`, aber `variant="tertiary"` rendert ohne Rahmen/Hintergrund im Ruhezustand, und ohne zusätzlichen visuellen Anker (Icon, Unterstrich, o.ä.) verschwindet die Klickbarkeit komplett.

**Fix:** Den beiden Buttons eine erkennbare, aber unaufdringliche Pill-Optik geben, die zum Rest des Formulars passt (angelehnt an die Chip-Optik aus `RecipientField.vue`, die schon gut aussieht):
```css
.compose-toggle-row .toggle-btn {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 2px 10px;
}
.compose-toggle-row .toggle-btn--active {
    background: var(--color-primary-element-light);
    border-color: var(--color-primary-element);
    color: var(--color-primary-element);
}
```
Zusätzlich den aktiven Zustand (Cc/Bcc-Feld sichtbar) visuell markieren, z.B. per `:class="{ 'toggle-btn--active': showCc }"`, damit der Nutzer sieht, welches Feld gerade eingeblendet ist.

---

## 4. Editor-Toolbar: nackte Icon-Reihe ohne Struktur

**Datei:** `RichTextEditor.vue`, Zeile 3–47 (Template) + Zeile 139–149 (CSS)

**Beobachtung:** Die Formatierungs-Icons (Fett, Kursiv, Unterstrichen, dann Listen, Link, "Formatierung löschen") stehen dicht nebeneinander ohne sichtbaren Container, ohne Hintergrund/Rahmen, ohne erkennbare Gruppierung — wirkt wie zufällig über dem Editor "schwebende" Symbole statt wie eine Werkzeugleiste. Es gibt zwar bereits `.richtext-editor__separator`-Trenner im Code (Zeile 22, 41 im Template; Zeile 143–145 im CSS: `width:1px; background:var(--color-border)`), aber diese sind vermutlich zu unauffällig/dünn, um im Screenshot als Gruppierung wahrgenommen zu werden.

**Fix:**
```css
.richtext-editor__toolbar {
    display: flex; align-items: center; gap: 2px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-background-hover);
    flex-wrap: wrap; flex-shrink: 0;
}
.richtext-editor__separator {
    width: 1px; height: 20px;
    background: var(--color-border);
    margin: 0 6px;
}
```
- Sichtbare Hintergrundfläche (`var(--color-background-hover)`) für die gesamte Toolbar, damit sie sich klar vom Editor-Inhalt darunter absetzt (ähnlich einer echten Editor-Toolbar wie in Google Docs/Nextcloud Text).
- Trenner höher (`height:20px` statt implizit über `margin`) und mit mehr seitlichem Abstand, damit die drei Gruppen (Text-Stil: Bold/Italic/Underline — Listen: Bullet/Numbered/Link — Formatierung löschen) optisch klar erkennbar sind.
- Hover-Zustand der einzelnen Buttons prüfen: `NcButton variant="tertiary"` sollte bereits einen Hover-Hintergrund haben (Standard-NC-Verhalten) — falls im Screenshot kein Hover-Feedback sichtbar war, das nicht weiter behandeln (Hover ist im Static-Screenshot ohnehin nie sichtbar), aber den aktiven Zustand (`richtext-editor__btn--active`, Zeile 146–149) beibehalten/verstärken (evtl. zusätzlich `border-radius: var(--border-radius)` für einen klar abgegrenzten "gedrückt"-Look).

---

## 5. Editor-Schreibbereich: winzige Box, riesiger ungenutzter Weißraum

**Das größte optische Problem im gesamten Formular.** Die eigentliche "Write your message…"-Eingabefläche ist im Screenshot nur eine kleine Box von ca. 130px Breite × 190px Höhe, obwohl das Modal ca. 860px breit und deutlich höher ist — rechts und unterhalb der kleinen Box bleibt sehr viel Fläche komplett leer/weiß. Ein moderner E-Mail-Composer muss den Schreibbereich über die **volle verfügbare Breite und Höhe** des Modals ausdehnen.

**Betroffene Dateien/Stellen:**
- `ComposeEditor.vue` Zeile 27–28: `<RichTextEditor ref="editor" v-model="bodyHtml" ... class="compose-layout__body" />`
- `ComposeEditor.vue` CSS Zeile 288 (`.compose-layout { display:flex; flex-direction:column; max-height:85vh; }`) und Zeile 295 (`.compose-layout__body { flex:1; min-height:280px; margin:8px 16px; }`)
- `RichTextEditor.vue` CSS Zeile 134–158 (`.richtext-editor { height:100%; ... }`, `.richtext-editor__content { flex:1; ... }`)

**Wahrscheinliche Ursache (zur Verifikation im Browser-DevTools, nicht 100% sicher ohne Live-Rendering):**
1. `.compose-layout` hat nur `max-height: 85vh`, **keine feste `height`**. In einem Flexbox-Column-Container ohne definierte `height` können Kind-Elemente mit `flex: 1` (wie `.compose-layout__body`) nicht zuverlässig in die Höhe wachsen, weil der Container selbst keine definitive Höhe hat, an der sich der verfügbare Platz bemisst — er richtet sich stattdessen nach dem Inhalt. Das erklärt primär das Höhenproblem, potenziell aber auch Folgeeffekte auf die Breite, falls TipTap/ProseMirror bei fehlender definitiver Box-Größe auf "shrink-to-fit" zurückfällt.
2. `RichTextEditor.vue`'s Wurzel-Element trägt **gleichzeitig zwei Klassen** (`richtext-editor` vom eigenen Template UND `compose-layout__body` von außen übergeben) — dadurch gelten für dasselbe Element sowohl `height:100%` (aus `.richtext-editor`) als auch `flex:1` (aus `.compose-layout__body`). Das ist keine Konflikt-verursachende Kombination per se, verstärkt aber die Abhängigkeit von einer definitiven Höhe des Elternelements (siehe Punkt 1).
3. ProseMirror-basierte Editoren (TipTap) sind dafür bekannt, dass ihr `contenteditable`-Wurzelelement auf die **Inhaltsbreite schrumpft**, wenn nicht explizit `width: 100%` (bzw. `align-self:stretch` in einem Flex-Kontext mit korrekt propagierter Breite) gesetzt ist — das ist die wahrscheinlichste Erklärung für die winzige ~130px-Breite bei leerem/kurzem Platzhaltertext.

**Fix-Vorschlag (im Browser verifizieren, nicht blind übernehmen):**
```css
/* ComposeEditor.vue */
.compose-layout {
    display: flex; flex-direction: column;
    height: 85vh;        /* statt nur max-height */
    max-height: 85vh;
}
.compose-layout__body {
    flex: 1 1 auto;
    min-height: 280px;
    margin: 8px 16px;
    display: flex;        /* damit sich height:100% im Kind (RichTextEditor) korrekt auflöst */
    min-width: 0;          /* verhindert Flex-Item-Shrink-to-content in Row-Kontexten */
}
```
```css
/* RichTextEditor.vue */
.richtext-editor {
    display: flex; flex-direction: column;
    width: 100%;
    height: 100%;
    background: var(--color-main-background);
}
.richtext-editor__content {
    flex: 1;
    width: 100%;
    min-height: v-bind(minHeight);
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.6;
    overflow-y: auto;
}
.richtext-editor__content :deep(.ProseMirror) {
    outline: none;
    width: 100%;
    min-height: v-bind(minHeight);
}
```
Nach Anwendung im Browser prüfen: Editor-Box muss die komplette verfügbare Breite des Modals ausfüllen (bis auf die 16px-Innenabstände) und bis kurz vor die Toolbar/Footer-Grenze in die Höhe wachsen, kein sichtbarer Leerraum rechts/unten mehr.

---

## 6. Allgemeine Konsistenz: einheitlicher Abstand zwischen den Formularzeilen

**Beobachtung:** Der vertikale Abstand zwischen From-Zeile, To-Zeile, Cc/Bcc-Zeile und Subject-Zeile wirkt uneinheitlich — mal größere, mal kleinere Lücken zwischen den Sektionen, wodurch das Formular nicht wie eine saubere, gleichmäßig gerasterte Liste wirkt.

**Fix:** Für alle Feld-Container in `ComposeEditor.vue` (`.compose-layout__from`, `.compose-layout__recipients`, `.compose-layout__subject`) ein einheitliches vertikales Rhythmus-Schema festlegen, z.B.:
```css
.compose-layout__from,
.compose-layout__recipients,
.compose-layout__subject {
    padding: 10px 16px;
}
```
und jede Sektion mit derselben Caption-Typografie versehen (kleine graue Großbuchstaben-Beschriftung wie aktuell schon bei "FROM"/"TO"/"SUBJECT" — dieses Muster auch für die Cc/Bcc-Zeile beibehalten, falls dort ebenfalls Captions ergänzt werden).

---

## Verifikation nach Umsetzung

1. `cd /projects/souvera_mail && npm run build` fehlerfrei.
2. "New message" öffnen: From-Feld zeigt entweder gar kein Dropdown (nur 1 Identität) oder ein kompaktes, korrekt beschriftetes Dropdown ohne doppelte Box.
3. To-Feld zeigt Platzhalter "To…" (nicht nur "…"); Cc/Bcc analog nach Klick auf die jetzt klar als Buttons erkennbaren Umschalter.
4. Editor-Toolbar wirkt wie ein zusammenhängender Werkzeugleisten-Balken mit klar erkennbaren Gruppen.
5. Schreibbereich füllt die komplette verfügbare Breite und Höhe des Modals aus, kein nennenswerter Leerraum rechts/unten.
6. Alle Formularzeilen (From/To/Cc/Bcc/Subject) haben gleichmäßigen vertikalen Abstand zueinander.
7. Fenster auf ca. 900px Breite UND auf schmalerer Breite (z.B. 500px, falls Compose responsive skaliert) testen — Layout darf nicht "hüpfen"/instabil werden (siehe die beobachtete Größenabweichung zwischen den zwei Screenshots).
