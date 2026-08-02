/**
 * JMAP EventSource (SSE) push composable.
 *
 * Listens to Stalwart's push events and triggers callbacks for new mail,
 * flag changes, and quota updates. Falls back silently if SSE is not
 * supported or the connection fails.
 */

export function usePush() {
	let source = null
	let reconnectTimer = null
	const callbacks = { newMail: [], flagChanged: [], quotaChanged: [] }

	function connect() {
		if (typeof EventSource === 'undefined') return
		if (source) return

		try {
			source = new EventSource('/.well-known/jmap/event-source')
			source.onmessage = (event) => {
				try {
					const data = JSON.parse(event.data)
					handlePush(data)
				} catch { /* ignore malformed */ }
			}
			source.onerror = () => {
				disconnect()
				clearTimeout(reconnectTimer)
				reconnectTimer = setTimeout(connect, 15000)
			}
		} catch { /* SSE unavailable */ }
	}

	function disconnect() {
		if (source) { source.close(); source = null }
	}

	function handlePush(data) {
		if (!data || !data.changed) return
		// Stalwart push format: {"@type":"StateChange","changed":{"<accountId>":{"Email":"<newState>"}}}
		const changed = data.changed
		Object.keys(changed).forEach(accountId => {
			const account = changed[accountId]
			if (account.Email) {
				callbacks.newMail.forEach(fn => fn(accountId))
			}
			if (account.Quota) {
				callbacks.quotaChanged.forEach(fn => fn(accountId))
			}
		})
	}

	function on(event, fn) {
		if (callbacks[event]) callbacks[event].push(fn)
	}

	function off(event, fn) {
		if (callbacks[event]) callbacks[event] = callbacks[event].filter(f => f !== fn)
	}

	function cleanup() {
		disconnect()
		clearTimeout(reconnectTimer)
		callbacks.newMail.length = 0
		callbacks.flagChanged.length = 0
		callbacks.quotaChanged.length = 0
	}

	return { connect, disconnect, on, off, cleanup }
}
