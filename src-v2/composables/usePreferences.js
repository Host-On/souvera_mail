import { reactive } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const state = reactive({
	signatureHtml: '',
	signatureEnabled: false,
	messagesPerPage: 50,
	readingPane: true,
	remoteImages: 'never',
	account: { email: '', server: '' },
	loaded: false,
	loading: false,
	error: null,
})

export function usePreferences() {
	async function load() {
		state.loading = true
		state.error = null
		try {
			const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'))
			Object.assign(state, data, { loaded: true })
		} catch (e) {
			console.error('Failed to load preferences', e)
			state.error = e.response?.data?.error || 'Failed to load preferences'
		} finally {
			state.loading = false
		}
	}

	async function save(partial) {
		try {
			await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), partial)
			Object.assign(state, partial)
			return true
		} catch (e) {
			console.error('Failed to save preferences', e)
			return false
		}
	}

	return { state, load, save }
}
