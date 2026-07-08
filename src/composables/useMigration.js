/**
 * Souvera Mail — Migration API composable (Vue 3, Composition API).
 *
 * Wraps the 7 backend endpoints under /apps/souvera_mail/migration/*.
 * The backend behaviour is unchanged from v0.14.10 — this composable
 * only exposes the same operations through a Vue-friendly reactive API.
 *
 *   loadState()           GET   /migration/welcome-state
 *   dismissWelcome()      POST  /migration/dismiss-welcome
 *   testConnection()      POST  /migration/test-connection
 *   listFolders()         POST  /migration/list-folders
 *   startMigration()      POST  /migration/start
 *   loadStatus()          GET   /migration/status
 *   dismissJob(jobId)     POST  /migration/dismiss/{jobId}
 *
 * All calls carry the NC `requesttoken` header for CSRF protection and
 * `credentials: 'same-origin'`.  Networking is a plain fetch() call —
 * @nextcloud/axios would add ~30 KB to the bundle and we don't need
 * anything beyond what fetch provides.
 */

import { ref, computed, readonly } from 'vue'
import { generateUrl } from '@nextcloud/router'

// Terminal / non-terminal job states — must stay in sync with the
// enum in lib/Db/Migration.php.
const TERMINAL_STATES = ['completed', 'failed', 'cancelled', 'dismissed']

function isTerminal(state) {
	return TERMINAL_STATES.includes(state || '')
}

function csrfHeaders(extra = {}) {
	const token = (typeof OC !== 'undefined' && OC.requestToken) || ''
	return {
		'Content-Type': 'application/json',
		requesttoken: token,
		Accept: 'application/json',
		...extra,
	}
}

async function jsonFetch(url, init = {}) {
	const response = await fetch(url, {
		credentials: 'same-origin',
		...init,
		headers: {
			...csrfHeaders(),
			...(init.headers || {}),
		},
	})
	// The backend always returns JSON — even for error branches — so
	// parse regardless of status.  We surface `body.message` verbatim.
	let body = null
	try {
		body = await response.json()
	} catch (e) {
		body = { message: 'Ungültige Antwort vom Server.' }
	}
	if (!response.ok) {
		const err = new Error(body?.message || `HTTP ${response.status}`)
		err.status = response.status
		err.body = body
		throw err
	}
	return body
}

export function useMigration() {
	// --- reactive state ----------------------------------------------
	const available = ref(false)
	const dismissed = ref(false)
	const activeJob = ref(null)
	const lastJob = ref(null)
	const status = ref(null)

	const isLoading = ref(false)
	const isPolling = ref(false)
	let pollHandle = null

	// --- derived views ----------------------------------------------
	const hasActive = computed(() => activeJob.value !== null)
	const jobState = computed(() => status.value?.state || activeJob.value?.state || null)
	const pillState = computed(() => {
		const s = jobState.value
		if (s === 'running' || s === 'pending') return 'running'
		if (s === 'completed') return 'done'
		if (s === 'failed' || s === 'cancelled') return 'fail'
		return 'idle'
	})
	const pillLabel = computed(() => {
		switch (pillState.value) {
			case 'running': return t('souvera_mail', 'Import läuft …')
			case 'done':    return t('souvera_mail', 'Import fertig')
			case 'fail':    return t('souvera_mail', 'Import fehlgeschlagen')
			default:        return t('souvera_mail', 'Alte Mails importieren')
		}
	})

	// --- API calls --------------------------------------------------
	async function loadState() {
		isLoading.value = true
		try {
			const body = await jsonFetch(generateUrl('/apps/souvera_mail/migration/welcome-state'))
			available.value = !!body.available
			dismissed.value = !!body.dismissed
			activeJob.value = body.activeJob || null
			lastJob.value = body.lastJob || null
			if (activeJob.value) {
				status.value = activeJob.value
			}
		} finally {
			isLoading.value = false
		}
	}

	async function dismissWelcome() {
		await jsonFetch(generateUrl('/apps/souvera_mail/migration/dismiss-welcome'), { method: 'POST' })
		dismissed.value = true
	}

	async function testConnection(payload) {
		return jsonFetch(generateUrl('/apps/souvera_mail/migration/test-connection'), {
			method: 'POST',
			body: JSON.stringify(payload),
		})
	}

	async function listFolders(payload) {
		return jsonFetch(generateUrl('/apps/souvera_mail/migration/list-folders'), {
			method: 'POST',
			body: JSON.stringify(payload),
		})
	}

	async function startMigration(payload) {
		const body = await jsonFetch(generateUrl('/apps/souvera_mail/migration/start'), {
			method: 'POST',
			body: JSON.stringify(payload),
		})
		activeJob.value = body.job || null
		status.value = body.job || null
		return body
	}

	async function loadStatus() {
		const body = await jsonFetch(generateUrl('/apps/souvera_mail/migration/status'))
		status.value = body.job || null
		if (isTerminal(status.value?.state)) {
			lastJob.value = status.value
			activeJob.value = null
			stopPolling()
		}
		return body
	}

	async function dismissJob(jobId) {
		await jsonFetch(generateUrl(`/apps/souvera_mail/migration/dismiss/${jobId}`), { method: 'POST' })
		if (lastJob.value?.id === jobId) lastJob.value = null
		if (status.value?.id === jobId) status.value = null
	}

	// --- polling ----------------------------------------------------
	function startPolling(intervalMs = 5000) {
		if (pollHandle) return
		isPolling.value = true
		pollHandle = window.setInterval(() => {
			loadStatus().catch(() => { /* transient errors: keep polling */ })
		}, intervalMs)
	}

	function stopPolling() {
		if (pollHandle) {
			window.clearInterval(pollHandle)
			pollHandle = null
		}
		isPolling.value = false
	}

	return {
		// state (read-only from the outside)
		available: readonly(available),
		dismissed: readonly(dismissed),
		activeJob: readonly(activeJob),
		lastJob: readonly(lastJob),
		status: readonly(status),
		isLoading: readonly(isLoading),
		isPolling: readonly(isPolling),
		hasActive,
		jobState,
		pillState,
		pillLabel,
		// actions
		loadState,
		dismissWelcome,
		testConnection,
		listFolders,
		startMigration,
		loadStatus,
		dismissJob,
		startPolling,
		stopPolling,
		// helpers
		isTerminal,
	}
}
