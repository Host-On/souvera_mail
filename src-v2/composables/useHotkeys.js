export function useHotkeys(map) {
	function handler(e) {
		if (e.ctrlKey || e.metaKey || e.altKey) return
		const tag = document.activeElement?.tagName?.toLowerCase()
		if (tag === 'input' || tag === 'textarea' || tag === 'select') return
		if (document.activeElement?.isContentEditable) return

		const fn = map[e.key.toLowerCase()]
		if (fn) {
			e.preventDefault()
			fn()
		}
	}

	window.addEventListener('keydown', handler)

	function destroy() {
		window.removeEventListener('keydown', handler)
	}

	return { destroy }
}
