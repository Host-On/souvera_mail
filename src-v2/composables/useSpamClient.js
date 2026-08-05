import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const BASE = '/apps/souvera_mail/api/v2/spam'

/**
 * Composable for the Spam folder — merges JMAP junk + Shield/PMG quarantine.
 */
export function useSpamClient() {
	return {
		async fetchSpamItems(limit = 50, offset = 0) {
			const { data } = await axios.get(generateUrl(BASE + '/list'), {
				params: { limit, offset },
			})
			return data
		},

		async viewSpamItem(id, source) {
			const { data } = await axios.get(generateUrl(BASE + '/view'), {
				params: { id, source },
			})
			return data
		},

		async releaseSpamItems(ids, source) {
			const { data } = await axios.post(generateUrl(BASE + '/release'), { ids, source })
			return data
		},

		async deleteSpamItems(ids, source) {
			const { data } = await axios.post(generateUrl(BASE + '/delete'), { ids, source })
			return data
		},
	}
}
