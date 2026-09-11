import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// fire-and-forget: PMG-Meldungen dürfen UI-Flows nie blockieren oder
// scheitern lassen — Fehler nur loggen, niemals awaiten/werfen.
function fireAndForget(promise) {
	promise.catch((e) => console.error('PMG report failed', e))
}

/**
 * Composable für PMG-Spam/Ham-Learning (Rücknahme vs. False-Positive).
 */
export function usePmgClient() {
	return {
		reportSpam(accountId, emailId) {
			const body = accountId ? { accountId, emailId } : { emailId }
			fireAndForget(axios.post(generateUrl('/apps/souvera_mail/api/v2/pmg/report/spam'), body))
		},
		reportHam(accountId, emailId) {
			const body = accountId ? { accountId, emailId } : { emailId }
			fireAndForget(axios.post(generateUrl('/apps/souvera_mail/api/v2/pmg/report/ham'), body))
		},
		reportShieldHam(id) {
			fireAndForget(axios.post(generateUrl('/apps/souvera_mail/api/v2/pmg/report/ham-shield'), { id }))
		},
	}
}
