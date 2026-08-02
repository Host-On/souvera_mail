/**
 * JMAP client composable for the Vue-3 v2 frontend.
 * Talks to the PHP proxy at /apps/souvera_mail/api/v2/*.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export function useJmapClient() {
	const base = generateUrl('/apps/souvera_mail')

	async function fetchMailboxes() {
		const { data } = await axios.get(base + '/api/v2/mailboxes')
		return data.mailboxes ?? []
	}

	async function fetchEmails(mailboxId, limit = 50, offset = 0) {
		const params = { limit, offset }
		if (mailboxId) params.mailbox = mailboxId
		const { data } = await axios.get(base + '/api/v2/emails', { params })
		return { emails: data.emails ?? [], total: data.total ?? 0 }
	}

	async function fetchEmailBody(id) {
		const { data } = await axios.get(base + '/api/v2/emails/' + id)
		return data.email ?? {}
	}

	async function markEmailRead(id, isRead = true) {
		await axios.post(base + '/api/v2/emails/' + id + '/read', { isRead: isRead ? 1 : 0 })
	}

	async function toggleEmailFlag(id, isFlagged) {
		await axios.post(base + '/api/v2/emails/' + id + '/flag', { isFlagged })
	}

	async function deleteEmailApi(id) {
		await axios.delete(base + '/api/v2/emails/' + id)
	}

	return { fetchMailboxes, fetchEmails, fetchEmailBody, markEmailRead, toggleEmailFlag, deleteEmailApi }
}
