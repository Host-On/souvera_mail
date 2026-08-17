import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const BASE = '/apps/souvera_mail/api/v2/sieve'

export function useSieveClient() {
	return {
		async fetchScripts() {
			const { data } = await axios.get(generateUrl(BASE))
			return data.scripts || []
		},

		async saveScript(name, body) {
			const { data } = await axios.post(generateUrl(BASE), { name, body })
			return data
		},

		async activateScript(name, active) {
			const { data } = await axios.put(generateUrl(BASE + '/' + encodeURIComponent(name) + '/activate'), { active })
			return data
		},

		async deleteScript(name) {
			const { data } = await axios.delete(generateUrl(BASE + '/' + encodeURIComponent(name)))
			return data
		},

		async validateScript(body) {
			const { data } = await axios.post(generateUrl(BASE + '/validate'), { body })
			return data
		},

		// Rebuild + activate the combined main script. `disabled` is the
		// optional list of filter names the user switched off.
		async rebuild(disabled) {
			const { data } = await axios.post(generateUrl(BASE + '/rebuild'), { disabled: disabled ?? null })
			return data
		},
	}
}
