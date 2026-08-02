import { translate as t } from '@nextcloud/l10n'

function escapeHtml(str) {
	if (!str) return ''
	return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

function bodyOrDefault(email) {
	if (email.htmlBody) return email.htmlBody
	if (email.plainBody) return escapeHtml(email.plainBody).replace(/\n/g, '<br>')
	return ''
}

export function buildReplyQuote(email) {
	const date = email.receivedAt ? new Date(email.receivedAt).toLocaleString() : ''
	const from = email.fromName ? `${email.fromName} <${email.fromAddress}>` : (email.fromAddress || '')
	const body = bodyOrDefault(email)

	return `<p></p><p></p>`
		+ `<div style="margin-top:12px">${t('souvera_mail', 'On {date}, {from} wrote:', { date, from })}</div>`
		+ `<blockquote>${body}</blockquote>`
}

export function buildForwardBody(email) {
	const date = email.receivedAt ? new Date(email.receivedAt).toLocaleString() : ''
	const from = email.fromName ? `${email.fromName} <${email.fromAddress}>` : (email.fromAddress || '')
	const to = email.toAddresses || ''
	const subject = email.subject || ''
	const body = bodyOrDefault(email)

	return `<div style="margin-bottom:12px">---------- ${t('souvera_mail', 'Forwarded message')} ----------</div>`
		+ `<table style="font-size:13px;margin-bottom:12px">`
		+ `<tr><td style="color:#888;padding-right:8px">${t('souvera_mail', 'From')}:</td><td>${escapeHtml(from)}</td></tr>`
		+ `<tr><td style="color:#888;padding-right:8px">${t('souvera_mail', 'Date')}:</td><td>${date}</td></tr>`
		+ `<tr><td style="color:#888;padding-right:8px">${t('souvera_mail', 'Subject')}:</td><td>${escapeHtml(subject)}</td></tr>`
		+ `<tr><td style="color:#888;padding-right:8px">${t('souvera_mail', 'To')}:</td><td>${escapeHtml(to)}</td></tr>`
		+ `</table>${body}`
}
