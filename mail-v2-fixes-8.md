# souvera_mail v2 — Fix-Liste Runde 9: Subject braucht den gleichen grauen Rahmen wie From/To

Editor-Textfeld ist jetzt perfekt — **bitte nicht mehr anfassen**. Auch From, To, Cc/Bcc **nicht mehr anfassen**.

Nur eine Datei, nur eine Stelle: **`/projects/souvera_mail/src-v2/components/ComposeEditor.vue`**

---

## Was ist kaputt

Subject hat aktuell nur den sehr blassen, kaum sichtbaren Standard-Rahmen, den `NcTextField` von sich aus mitbringt. From und To haben dagegen einen kräftigeren, deutlich sichtbaren grauen Rahmen, weil wir dort selbst extra CSS ergänzt haben. Subject soll optisch genau gleich aussehen wie From/To.

## Der Fix

Suche im `<style scoped>`-Block diesen Block (aus der letzten Runde):
```css
/* NcTextField (Subject) hat bereits sein eigenes Nextcloud-Design —
   wir zwingen ihm keinen eigenen Rahmen auf, sondern lassen es nur
   die volle Breite einnehmen. */
.compose-field :deep(.input-field) {
	width: 100% !important;
}
```

**Ersetze ihn genau durch diesen Block:**
```css
.compose-field :deep(.input-field) {
	width: 100% !important;
	border: 1px solid var(--color-border) !important;
	border-radius: var(--border-radius-large) !important;
}
```

Es wurden nur zwei Zeilen (`border` und `border-radius`) neu ergänzt — `width: 100% !important;` bleibt wie es ist. **Wichtig: nichts an der Regel `.compose-field :deep(.input-field) input` bzw. `.input-field__input` ändern oder neu ergänzen** — das würde wieder den Doppelrahmen-Fehler von vorher zurückbringen. Nur die eine Regel oben anfassen, sonst nichts.

---

## Prüfschritte

1. `cd /projects/souvera_mail && npm run build` fehlerfrei.
2. Hard-Reload im Browser (Cache leeren!).
3. Subject-Feld hat jetzt einen genauso kräftigen, deutlich sichtbaren grauen Rahmen wie From und To — optisch nicht mehr zu unterscheiden vom Rahmen-Stil der anderen Felder.
4. Nur **eine** Umrahmung um Subject, keine doppelte Box.
5. Alles andere (From, To, Cc/Bcc, Editor) sieht exakt so aus wie vorher — nichts davon hat sich verändert.
