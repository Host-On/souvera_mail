/**
 * Central-Signatur-Cache (localStorage): hält HTML + Asset-Data-URLs der
 * zentralen Signatur bereit, damit der Composer sie SOFORT synchron lesen
 * kann — unabhängig vom App-Ladezustand („zu schnelles Klicken" auf Neue
 * Nachricht darf die Signatur/Bilder nie leer lassen) und ohne Nachladen.
 *
 * - App-Start (MailHomeView mounted) schreibt den Cache einmal vor.
 * - Der Composer liest den Cache synchron und frischt ihn im Hintergrund auf.
 * - TTL: 5 Minuten — veraltete Einträge werden beim Refresh ersetzt, aber
 *   für die Instant-Darstellung auch dann genutzt, wenn sie leicht abgelaufen
 *   sind (besser aktuell-nachgeladen als leer).
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const CACHE_KEY = 'souvera_sig_cache'
const TTL_MS = 5 * 60 * 1000

/**
 * @return {{html: string, assets: Array<{cid: string, dataUrl: string}>, ts: number}|null}
 */
export function readSignatureCache() {
	try {
		const raw = localStorage.getItem(CACHE_KEY)
		if (!raw) return null
		const d = JSON.parse(raw)
		if (!d || typeof d.html !== 'string' || d.html === '') return null
		return {
			html: d.html,
			assets: Array.isArray(d.assets) ? d.assets : [],
			ts: typeof d.ts === 'number' ? d.ts : 0,
		}
	} catch (e) {
		return null
	}
}

/** true, wenn der Cache älter als die TTL ist (Refresh empfohlen). */
export function isCacheStale(entry) {
	return !entry || (Date.now() - entry.ts) > TTL_MS
}

export function writeSignatureCache(html, assets) {
	try {
		localStorage.setItem(CACHE_KEY, JSON.stringify({ html, assets: assets || [], ts: Date.now() }))
	} catch (e) {
		// Quota überschritten (übergroße Assets) — Cache überspringen.
		console.debug('Signature cache write failed (quota?)', e)
	}
}

/**
 * Holt die zentrale Signatur vom Central-Resolve-Endpunkt (inkl. Asset-
 * Data-URLs) und schreibt den Cache. Liefert die Daten oder null.
 *
 * @return {Promise<{html: string, assets: Array}|null>}
 */
export async function fetchAndCacheSignature() {
	try {
		const cs = await axios.get(generateUrl('/apps/souvera_central/api/mail-settings/signature'))
		const cdata = cs.data.ocs?.data || cs.data.data || cs.data
		if (cdata && cdata.found && cdata.html) {
			const assets = cdata.assets || []
			writeSignatureCache(cdata.html, assets)
			return { html: cdata.html, assets }
		}
	} catch (e) {
		console.debug('Central signature fetch failed', e)
	}
	return null
}
