/**
 * Souvera Mail — Migration API composable (Vue 3, Composition API).
 *
 * Adapter layer between the UI components (which use ergonomic keys
 * like `username`, `tls`, `ok`) and the actual PHP backend contract
 * (which uses `user`, `secure`, `{status:'ok', result:{...}}`).
 *
 * Backend endpoints under /apps/souvera_mail/migration/*:
 *
 *   GET  /welcome-state       → {status:'ok', state:{
 *                                    welcomeDismissed, activeJob,
 *                                    lastJob, available}}
 *   POST /dismiss-welcome     → {status:'ok'}
 *   POST /test-connection     body: {host, port, user, password, secure}
 *                             ok : {status:'ok', result:{success, message}}
 *                             err: HTTP 4xx/5xx + {status:'error', message}
 *   POST /list-folders        body: {host, port, user, password, secure}
 *                             ok : {status:'ok', result:[…]}
 *   POST /start               body: {host, port, user, password, secure}
 *                             ok : 201 + {status:'ok', job:{…}}
 *                             err: HTTP 4xx/5xx + {status:'error', message}
 *   GET  /status              → {status:'ok', active:{…}|null, latest:{…}|null}
 *   POST /dismiss/{jobId}     → {status:'ok'}
 *
 * This composable owns the key mapping in ONE place, so every Vue
 * component can stay contract-agnostic.  A HTTP-non-2xx response
 * raises via `jsonFetch()` with `err.message` sourced from
 * `body.message` — callers handle it with try/catch.
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
		body = { message: 'Invalid response from server.' }
	}
	if (!response.ok) {
		const err = new Error(body?.message || `HTTP ${response.status}`)
		err.status = response.status
		err.body = body
		throw err
	}
	return body
}

// UI-shape (username / tls) → backend-shape (user / secure).
function toBackendConn(uiConn) {
	return {
		host: uiConn.host,
		port: Number(uiConn.port) || 993,
		user: uiConn.username || uiConn.user || '',
		password: uiConn.password || '',
		secure: !!(uiConn.tls ?? uiConn.secure ?? true),
	}
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
	// v0.14.15 — backend field is `status` (top-level), NOT `state`.
	// `progress` is a nested object (see MigrationJob::toApiArray).
	// The provider.tools upstream status ('pending'/'running'/…) is
	// mirrored 1:1 onto our DB `status` column by refreshFromProvider.
	const jobState = computed(() => status.value?.status || activeJob.value?.status || null)
	const pillState = computed(() => {
		const s = jobState.value
		if (s === 'running' || s === 'pending') return 'running'
		if (s === 'completed') return 'done'
		if (s === 'failed' || s === 'cancelled') return 'fail'
		return 'idle'
	})
	const pillLabel = computed(() => {
		switch (pillState.value) {
			case 'running': return t('souvera_mail', 'Import running …')
			case 'done':    return t('souvera_mail', 'Import done')
			case 'fail':    return t('souvera_mail', 'Import failed')
			default:        return t('souvera_mail', 'Import old mail')
		}
	})

	// --- API calls --------------------------------------------------
	async function loadState() {
		isLoading.value = true
		try {
			const body = await jsonFetch(generateUrl('/apps/souvera_mail/migration/welcome-state'))
			const state = body?.state || {}
			available.value = !!state.available
			dismissed.value = !!state.welcomeDismissed
			activeJob.value = state.activeJob || null
			lastJob.value = state.lastJob || null
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

	/**
	 * Pre-flight IMAP source-cred check.
	 *
	 * @param {Object} uiConn — UI-shaped connection ({host, port, username, password, tls})
	 * @returns {Promise<{ok: boolean, message: string}>}
	 */
	async function testConnection(uiConn) {
		const body = await jsonFetch(generateUrl('/apps/souvera_mail/migration/test-connection'), {
			method: 'POST',
			body: JSON.stringify(toBackendConn(uiConn)),
		})
		const result = body?.result || {}
		return {
			ok: !!(result.success ?? true),
			message: result.message || '',
			raw: result,
		}
	}

	/**
	 * Pre-flight source folder inventory (best-effort, non-fatal in UI).
	 *
	 * @returns {Promise<{folders: Array, message_count: number, folder_count: number}>}
	 */
	async function listFolders(uiConn) {
		const body = await jsonFetch(generateUrl('/apps/souvera_mail/migration/list-folders'), {
			method: 'POST',
			body: JSON.stringify(toBackendConn(uiConn)),
		})
		const result = body?.result
		// provider.tools may return either an array of folder names, an
		// object with { folders, message_count }, or a plain count. We
		// normalise all three so the ConfirmScreen can render a preview.
		if (Array.isArray(result)) {
			return { folders: result, folder_count: result.length, message_count: 0 }
		}
		if (result && typeof result === 'object') {
			const folders = Array.isArray(result.folders) ? result.folders : []
			return {
				folders,
				folder_count: result.folder_count ?? folders.length,
				message_count: result.message_count ?? 0,
			}
		}
		return { folders: [], folder_count: 0, message_count: 0 }
	}

	async function startMigration(uiConn, folders = []) {
		const backendConn = toBackendConn(uiConn)
		const body = await jsonFetch(generateUrl('/apps/souvera_mail/migration/start'), {
			method: 'POST',
			body: JSON.stringify({ ...backendConn, folders }),
		})
		activeJob.value = body?.job || null
		status.value = body?.job || null
		return body
	}

	async function loadStatus() {
		const body = await jsonFetch(generateUrl('/apps/souvera_mail/migration/status'))
		const next = body?.active || body?.latest || null
		status.value = next
		if (body?.active) {
			activeJob.value = body.active
		} else if (activeJob.value) {
			// Transitioned from active → terminal.
			const previouslyActive = activeJob.value
			lastJob.value = next
			activeJob.value = null
			stopPolling()
			// v0.16.0 — P1: Auto-refresh Snappymail folder tree AND
			// remember the imported folders so a "NEW" badge can be shown
			// for 24 h in the mail-app's sidebar (`folder-imported-badge.js`).
			// We only do this on a successful completion — failed / cancelled
			// runs stay quiet so the user isn't nagged by badges for folders
			// that don't actually exist.
			try {
				const finishedState = next?.status || previouslyActive?.status
				if (finishedState === 'completed') {
					notifyMigrationCompleted(previouslyActive, next)
				}
			} catch (_e) { /* silent — the event is a QoL sugar, never fail */ }
		}
		return body
	}

	/**
	 * v0.16.0 — Broadcast "migration completed" so the mail-app can
	 *   (a) reload its folder list so the imported folders become visible
	 *       immediately (no manual F5 required)
	 *   (b) record the imported folder names in localStorage with a
	 *       24-h expiry so `folder-imported-badge.js` can render a
	 *       "NEW" badge next to them in the sidebar.
	 *
	 * The event carries the source-side folder names — since imports
	 * preserve the folder hierarchy 1:1, these are also the names in
	 * the target INBOX.
	 */
	function notifyMigrationCompleted(previouslyActive, latest) {
		// Snappymail exposes `rl.app` as a top-level global. Its
		// `foldersReload()` triggers a fresh Folder/get JMAP call and
		// re-renders the sidebar. Non-fatal if it's absent
		// (e.g. running before the engine has finished booting).
		if (typeof window !== 'undefined' && window.rl && window.rl.app) {
			try {
				if (typeof window.rl.app.foldersReload === 'function') {
					window.rl.app.foldersReload()
				} else if (typeof window.rl.app.reloadFolderList === 'function') {
					window.rl.app.reloadFolderList()
				}
			} catch (_e) { /* silent */ }
		}

		// Best-effort folder list — the API may hand back `folders`
		// on the job row (persisted at start time) OR omit it. If we
		// have none, just refresh without the badge.
		const rawFolders = latest?.folders || previouslyActive?.folders || []
		const folderNames = Array.isArray(rawFolders)
			? rawFolders.filter(f => typeof f === 'string' && f.length > 0)
			: []
		if (folderNames.length > 0 && typeof window !== 'undefined' && window.localStorage) {
			try {
				const key = 'souvera-mail:imported-folders'
				const now = Date.now()
				const ttl = 24 * 60 * 60 * 1000 // 24 h
				const prior = JSON.parse(window.localStorage.getItem(key) || '{}')
				const merged = { ...(prior || {}) }
				folderNames.forEach(name => {
					merged[name] = now + ttl
				})
				window.localStorage.setItem(key, JSON.stringify(merged))
			} catch (_e) { /* silent — quota / private mode */ }
		}

		// Broadcast for any plugin JS that wants to react (see
		// `plugins/nextcloud/js/folder-imported-badge.js`).
		try {
			window.dispatchEvent(new CustomEvent('souvera-mail:migration-completed', {
				detail: { folders: folderNames, jobId: latest?.id || previouslyActive?.id },
			}))
		} catch (_e) { /* silent — CustomEvent lack of support is extremely rare */ }
	}

	async function dismissJob(jobId) {
		await jsonFetch(generateUrl(`/apps/souvera_mail/migration/dismiss/${jobId}`), { method: 'POST' })
		if (lastJob.value?.id === jobId) lastJob.value = null
		if (status.value?.id === jobId) status.value = null
	}

	/**
	 * v0.14.16 — user-initiated cancel while the job is still in the
	 * provider.tools queue. Backend rejects with HTTP 409 if the job
	 * has already transitioned to STATUS_RUNNING.
	 */
	async function cancelActiveJob(jobId) {
		const body = await jsonFetch(
			generateUrl(`/apps/souvera_mail/migration/cancel/${jobId}`),
			{ method: 'POST' },
		)
		activeJob.value = null
		status.value = body?.job || null
		lastJob.value = body?.job || null
		stopPolling()
		return body
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
		cancelActiveJob,
		startPolling,
		stopPolling,
		// helpers
		isTerminal,
	}
}
