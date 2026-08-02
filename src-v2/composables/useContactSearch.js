import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

let searchTimer = null

export function useContactSearch() {
	const suggestions = ref([])
	const searching = ref(false)

	function search(q) {
		suggestions.value = []
		clearTimeout(searchTimer)
		if (!q || q.trim().length < 2) return
		const term = q.trim()
		searchTimer = setTimeout(async () => {
			searching.value = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/contacts/search'), {
					params: { q: term, limit: 8 },
				})
				suggestions.value = data.contacts || []
			} catch (e) {
				console.error('Contact search failed', e)
				suggestions.value = []
			} finally {
				searching.value = false
			}
		}, 300)
	}

	function clear() {
		suggestions.value = []
		clearTimeout(searchTimer)
	}

	return { suggestions, searching, search, clear }
}
