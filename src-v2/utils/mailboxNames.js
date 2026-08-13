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

/**
 * Localizes external IMAP folder names. IMAP servers ship raw names
 * ("INBOX", "Sent", "Trash", … or localized variants). The leaf segment
 * of the path is mapped to a known ROLE; custom folders keep their name.
 */
const EXT_FOLDER_ROLE_BY_LEAF = {
	inbox: 'inbox',
	posteingang: 'inbox',
	drafts: 'drafts',
	entwürfe: 'drafts',
	entwuerfe: 'drafts',
	sent: 'sent',
	'sent items': 'sent',
	'sent messages': 'sent',
	gesendet: 'sent',
	archive: 'archive',
	archiv: 'archive',
	junk: 'junk',
	spam: 'junk',
	trash: 'trash',
	papierkorb: 'trash',
	'deleted items': 'trash',
	'deleted messages': 'trash',
	gelöscht: 'trash',
	geloescht: 'trash',
}

export function extFolderDisplayName(folder) {
	if (!folder) return ''
	const path = (folder.path || folder.name || '').trim()
	const leaf = path.toLowerCase().split('.').pop()
	const role = EXT_FOLDER_ROLE_BY_LEAF[leaf]
	if (role) {
		return mailboxDisplayName({ role, name: folder.name })
	}
	return folder.name || path
}
