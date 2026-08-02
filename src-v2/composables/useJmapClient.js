/**
 * JMAP client composable for the Vue-3 v2 frontend.
 * Talks to the PHP proxy at /apps/souvera_mail/api/v2/*.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export function useJmapClient() {
	const base = generateUrl('/apps/souvera_mail')

	/**
	 * Fetch mailboxes from Stalwart (via PHP proxy).
	 * @returns {Promise<Array>} mailbox list
	 */
	async function fetchMailboxes() {
		const { data } = await axios.get(base + '/api/v2/mailboxes')
		return data.mailboxes ?? []
	}

	/**
	 * Fetch emails from a mailbox.
	 * @param {string} mailboxId - JMAP mailbox ID (or empty for all)
	 * @param {number} limit
	 * @param {number} offset
	 * @returns {Promise<{emails: Array, total: number}>}
	 */
	async function fetchEmails(mailboxId, limit = 50, offset = 0) {
		const params = { limit, offset }
		if (mailboxId) params.mailbox = mailboxId
		const { data } = await axios.get(base + '/api/v2/emails', { params })
		return { emails: data.emails ?? [], total: data.total ?? 0 }
	}

	return { fetchMailboxes, fetchEmails }
}
