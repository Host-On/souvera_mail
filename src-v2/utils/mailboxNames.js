/**
 * Localizes standard (role-based) mailbox names.
 *
 * The JMAP server ships system mailboxes with English names ("Inbox",
 * "Drafts", …). Clients translate them via the mailbox ROLE — this helper
 * maps known roles to translated display names, falling back to the
 * server-provided name for custom folders.
 *
 * Uses the v2 translation catalog injected into window (same source the
 * template t() uses), so it works in components and plain utils alike.
 */

const ROLE_BASE_NAMES = {
	inbox: 'Inbox',
	drafts: 'Drafts',
	sent: 'Sent',
	archive: 'Archive',
	junk: 'Junk',
	trash: 'Trash',
}

export function mailboxDisplayName(mailbox) {
	if (!mailbox) return ''
	const base = ROLE_BASE_NAMES[mailbox.role]
	if (base) {
		const translations = (typeof window !== 'undefined' && window._souvera_mail_translations) || {}
		return translations[base] || base
	}
	return mailbox.name || ''
}
