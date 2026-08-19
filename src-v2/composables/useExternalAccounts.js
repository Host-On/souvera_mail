import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const BASE = '/apps/souvera_mail/api/v2/external/accounts'

export function useExternalAccounts() {
	return {
		async list() {
			const { data } = await axios.get(generateUrl(BASE))
			return data.accounts || []
		},
		async create(account) {
			const { data } = await axios.post(generateUrl(BASE), account)
			return data.account
		},
		async remove(id) {
			await axios.delete(generateUrl(BASE + '/' + id))
		},
		async test(id) {
			const { data } = await axios.post(generateUrl(BASE + '/' + id + '/test'))
			return data
		},
		// Test credentials BEFORE saving (form data, no account id).
		async testConnection(account) {
			const { data } = await axios.post(generateUrl(BASE + '/test-connection'), account)
			return data
		},
		// Provider presets (existing endpoint)
		async providers() {
			const { data } = await axios.get(generateUrl('/apps/souvera_mail/external/providers'))
			return data.providers || {}
		},
		async preset(email) {
			const { data } = await axios.get(generateUrl('/apps/souvera_mail/external/preset'), { params: { email } })
			return data.preset
		},
	}
}
