/**
 * JMAP EventSource (SSE) push composable.
 *
 * Stalwart SSE push requires authentication that the browser cannot
 * provide directly. The recommended path is:
 *   Stalwart → souvera_mail webhook → host-on.souvera.work/push → FCM
 *
 * This composable is a placeholder for the FCM-on-message bridge that
 * the app can subscribe to once the push architecture is live.
 */

const callbacks = { newMail: [], flagChanged: [], quotaChanged: [] }

export function usePush() {
	let pollTimer = null

	function connect() {
		// FCM/SSE connection not yet implemented — polling falls back
		// silently. Users reload the mailbox sidebar manually for now.
	}

	function disconnect() {
		clearInterval(pollTimer)
		pollTimer = null
	}

	function on(event, fn) {
		if (callbacks[event]) callbacks[event].push(fn)
	}

	function off(event, fn) {
		if (callbacks[event]) callbacks[event] = callbacks[event].filter(f => f !== fn)
	}

	function cleanup() {
		disconnect()
		callbacks.newMail.length = 0
		callbacks.flagChanged.length = 0
		callbacks.quotaChanged.length = 0
	}

	return { connect, disconnect, on, off, cleanup }
}
