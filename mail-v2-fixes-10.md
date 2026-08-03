# souvera_mail v2 — Fix-Liste Runde 11: Anhänge in den Kopfbereich verschieben

## Was soll sich ändern (Zusammenfassung für den Kontext)

Aktuell gibt es in der Mail-Detailansicht drei optisch getrennte Blöcke übereinander: (1) ein grauer Kopfbereich mit Betreff/Absender/Empfänger, (2) direkt darunter ein eigener, unauffälliger Block mit den Anhängen (ohne grauen Hintergrund — wirkt wie ein separater Abschnitt), (3) der eigentliche Mail-Inhalt. Gewünscht: Die Anhänge sollen **Teil** des grauen Kopfbereichs werden (also mit Betreff/Absender/Empfänger in einer gemeinsamen "Karte"), und direkt darunter folgt dann nur noch der Mail-Inhalt, ohne einen dazwischenliegenden dritten Abschnitt.

Nur eine Datei: **`/projects/souvera_mail/src-v2/components/EmailDetail.vue`**

---

## Schritt 1 — Anhänge-Block im Template in den Kopfbereich verschieben

Suche diesen Block (aktuell Zeile 31–69) — das sind **zwei** direkt aufeinanderfolgende `<div>`-Blöcke, `email-detail__header` und danach `email-detail__attachments`:

```html
		<div class="email-detail__header">
			<h2>{{ email.subject || t('souvera_mail', '(no subject)') }}</h2>
			<div class="email-detail__from">
				<BimiLogo :email="email.fromAddress" />
				<strong>{{ email.fromName || email.fromAddress }}</strong>
				<span class="email-detail__addr">&lt;{{ email.fromAddress }}&gt;</span>
			</div>
			<div class="email-detail__meta">
				<span v-if="email.toAddresses">{{ t('souvera_mail', 'To:') }} {{ email.toAddresses }}</span>
				<span>{{ formatDateTime(email.receivedAt) }}</span>
			</div>
		</div>

		<div v-if="email.attachments && email.attachments.length > 0" class="email-detail__attachments">
			<div class="email-detail__attachments-header">
				<h4>{{ t('souvera_mail', 'Attachments') }} ({{ email.attachments.length }})</h4>
				<NcButton variant="tertiary" size="small" @click="openSaveAllPicker" :disabled="savingAll">
					<template #icon><FolderDownload :size="16" /></template>
					{{ savingAll ? t('souvera_mail', 'Saving…') : t('souvera_mail', 'Save all to Files') }}
				</NcButton>
			</div>
			<div class="attachment-chips">
				<div v-for="att in email.attachments" :key="att.blobId" class="attachment-chip">
					<a :href="buildBlobUrl(att.blobId, att.name)" download
						:title="t('souvera_mail', 'Download file')">
						<NcButton variant="tertiary">
							<template #icon><Download :size="16" /></template>
							{{ att.name }} ({{ formatSize(att.size) }})
						</NcButton>
					</a>
					<NcButton variant="tertiary" size="small"
						:title="t('souvera_mail', 'Save to Files')"
						:aria-label="t('souvera_mail', 'Save to Files')"
						@click="startSaveToFiles(att)">
						<template #icon><ContentSave :size="16" /></template>
					</NcButton>
				</div>
			</div>
		</div>
```

**Ersetze diese zwei Blöcke durch folgenden EINEN Block** (der Anhänge-Teil ist jetzt **innerhalb** von `email-detail__header` verschachtelt, direkt nach dem `email-detail__meta`-Div, vor dem schließenden `</div>` des Headers):

```html
		<div class="email-detail__header">
			<h2>{{ email.subject || t('souvera_mail', '(no subject)') }}</h2>
			<div class="email-detail__from">
				<BimiLogo :email="email.fromAddress" />
				<strong>{{ email.fromName || email.fromAddress }}</strong>
				<span class="email-detail__addr">&lt;{{ email.fromAddress }}&gt;</span>
			</div>
			<div class="email-detail__meta">
				<span v-if="email.toAddresses">{{ t('souvera_mail', 'To:') }} {{ email.toAddresses }}</span>
				<span>{{ formatDateTime(email.receivedAt) }}</span>
			</div>

			<div v-if="email.attachments && email.attachments.length > 0" class="email-detail__attachments">
				<div class="email-detail__attachments-header">
					<h4>{{ t('souvera_mail', 'Attachments') }} ({{ email.attachments.length }})</h4>
					<NcButton variant="tertiary" size="small" @click="openSaveAllPicker" :disabled="savingAll">
						<template #icon><FolderDownload :size="16" /></template>
						{{ savingAll ? t('souvera_mail', 'Saving…') : t('souvera_mail', 'Save all to Files') }}
					</NcButton>
				</div>
				<div class="attachment-chips">
					<div v-for="att in email.attachments" :key="att.blobId" class="attachment-chip">
						<a :href="buildBlobUrl(att.blobId, att.name)" download
							:title="t('souvera_mail', 'Download file')">
							<NcButton variant="tertiary">
								<template #icon><Download :size="16" /></template>
								{{ att.name }} ({{ formatSize(att.size) }})
							</NcButton>
						</a>
						<NcButton variant="tertiary" size="small"
							:title="t('souvera_mail', 'Save to Files')"
							:aria-label="t('souvera_mail', 'Save to Files')"
							@click="startSaveToFiles(att)">
							<template #icon><ContentSave :size="16" /></template>
						</NcButton>
					</div>
				</div>
			</div>
		</div>
```

**Wichtig:** Es wurde nichts am Inhalt geändert, nur die Position — der komplette `<div v-if="email.attachments...">`-Block ist jetzt ein Kind-Element von `email-detail__header` statt ein Geschwister-Element danach.

---

## Schritt 2 — CSS anpassen, damit es innerhalb des Kopfbereichs gut aussieht

Suche diese Regel (aktuell etwa Zeile 279):
```css
.email-detail__attachments { margin-bottom: 20px; }
```
**Ersetze sie durch:**
```css
.email-detail__attachments {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}
```
Das fügt eine feine Trennlinie zwischen der Absender/Empfänger-Zeile und den Anhängen ein — sieht dann aus wie ein zusammenhängender Kopfbereich mit einer eigenen Zeile für Anhänge, statt zwei unabhängigen Blöcken.

---

## Prüfschritte

1. `cd /projects/souvera_mail && npm run build` fehlerfrei.
2. Hard-Reload im Browser.
3. Eine Mail mit Anhängen öffnen: Betreff, Absender/Empfänger UND die Anhangs-Liste stehen jetzt alle zusammen in **einem** grauen Kasten (gleicher Hintergrund, keine Lücke dazwischen), mit einer feinen Trennlinie zwischen der Empfänger-Zeile und den Anhängen.
4. Direkt darunter folgt ohne weiteren grauen Abschnitt der eigentliche Mail-Inhalt.
5. Eine Mail **ohne** Anhänge öffnen: sieht genau wie vorher aus (Header ohne Anhangs-Zeile, kein leerer Platz).
