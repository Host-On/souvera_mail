/**
 * JMAP client composable for the Vue-3 v2 frontend.
 * Talks to the PHP proxy at /apps/souvera_mail/api/v2/*.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export function useJmapClient() {
	const base = generateUrl('/apps/souvera_mail')

	async function fetchMailboxes(accountId) {
		const params = {}
		if (accountId) params.accountId = accountId
		const { data } = await axios.get(base + '/api/v2/mailboxes', { params })
		return data.mailboxes ?? []
	}

	async function fetchEmails(mailboxId, limit = 50, offset = 0, accountId, searchQuery, filterType) {
		const params = { limit, offset }
		if (mailboxId) params.mailbox = mailboxId
		if (accountId) params.accountId = accountId
		if (searchQuery) params.q = searchQuery
		if (filterType) params.filter = filterType
		const { data } = await axios.get(base + '/api/v2/emails', { params })
		return { emails: data.emails ?? [], total: data.total ?? 0 }
	}

	async function fetchEmailBody(id, accountId) {
		const params = {}
		if (accountId) params.accountId = accountId
		const { data } = await axios.get(base + '/api/v2/emails/' + id, { params })
		return data.email ?? {}
	}

	async function markEmailRead(id, isRead = true, accountId) {
		await axios.post(base + '/api/v2/emails/' + id + '/read', { isRead: isRead ? 1 : 0 }, { params: accountId ? { accountId } : {} })
	}

	async function toggleEmailFlag(id, isFlagged, accountId) {
		await axios.post(base + '/api/v2/emails/' + id + '/flag', { isFlagged }, { params: accountId ? { accountId } : {} })
	}

	async function moveEmail(id, mailboxId, accountId) {
		await axios.post(base + '/api/v2/emails/' + id + '/move', { mailboxId }, { params: accountId ? { accountId } : {} })
	}

	async function deleteEmailApi(id, accountId) {
		const params = {}
		if (accountId) params.accountId = accountId
		await axios.delete(base + '/api/v2/emails/' + id, { params })
	}

	return { fetchMailboxes, fetchEmails, fetchEmailBody, markEmailRead, toggleEmailFlag, moveEmail, deleteEmailApi }
}
