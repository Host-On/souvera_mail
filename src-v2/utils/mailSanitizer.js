import DOMPurify from 'dompurify'
import { generateUrl } from '@nextcloud/router'

export function buildBlobUrl(blobId, name) {
	return generateUrl('/apps/souvera_mail/api/v2/blobs/{id}/{name}', {
		id: blobId,
		name: encodeURIComponent(name),
	})
}

const BLANK_GIF = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'

/**
 * cid-Rewrite: img[src^="cid:"] → match attachment cid → blob URL.
 * Runs BEFORE DOMPurify so that cid: URIs are resolved before sanitization.
 */
function rewriteCidUrls(html, attachments) {
	const parser = new DOMParser()
	const doc = parser.parseFromString(html, 'text/html')

	const cidMap = new Map()
	for (const att of attachments) {
		if (att.cid) {
			const clean = att.cid.replace(/^<|>$/g, '')
			cidMap.set(clean, att)
			cidMap.set('cid:' + clean, att)
		}
	}

	if (cidMap.size === 0) return html

	const imgs = doc.querySelectorAll('img[src^="cid:"]')
	for (const img of imgs) {
		const raw = img.getAttribute('src') || ''
		const att = cidMap.get(raw) || cidMap.get(raw.replace(/^cid:/, ''))
		if (att) {
			img.setAttribute('src', buildBlobUrl(att.blobId, att.name))
		} else {
			img.removeAttribute('src')
		}
	}

	// Also rewrite background attributes on td/table/body
	const bgElements = doc.querySelectorAll('td[background^="cid:"], table[background^="cid:"], body[background^="cid:"]')
	for (const el of bgElements) {
		const raw = el.getAttribute('background') || ''
		const att = cidMap.get(raw) || cidMap.get(raw.replace(/^cid:/, ''))
		if (att) {
			el.setAttribute('background', buildBlobUrl(att.blobId, att.name))
		} else {
			el.removeAttribute('background')
		}
	}

	return doc.documentElement.outerHTML
}

const ALLOWED_TAGS = [
	'b', 'i', 'em', 'strong', 'a', 'p', 'br', 'ul', 'ol', 'li',
	'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
	'blockquote', 'pre', 'code', 'img', 'table', 'thead', 'tbody',
	'tr', 'td', 'th', 'div', 'span', 'font',
	'hr', 'center', 'small', 'u', 's', 'caption', 'col', 'colgroup',
	'dd', 'dl', 'dt', 'figure', 'address', 'sub', 'sup',
]

const ALLOWED_ATTR = [
	'href', 'src', 'alt', 'title', 'width', 'height', 'style', 'class',
	'target', 'rel', 'align', 'valign', 'border', 'cellpadding',
	'cellspacing', 'bgcolor', 'background', 'color', 'face', 'size', 'dir',
	'colspan', 'rowspan',
]

/**
 * Sanitize mail HTML with cid-rewrite and remote-image blocking.
 *
 * @param {string} html - Raw HTML from backend
 * @param {object} opts
 * @param {Array}  opts.attachments  - [{blobId,name,cid,...}]
 * @param {boolean} opts.blockRemote - block http/https images (default true)
 * @returns {{ html: string, blockedCount: number }}
 */
export function sanitizeMailHtml(html, { attachments = [], blockRemote = true } = {}) {
	if (!html) return { html: '', blockedCount: 0 }

	let processed = rewriteCidUrls(html, attachments)

	let blockedCount = 0

	if (blockRemote) {
		const hook = function (node) {
			if (!node) return
		if (node.tagName === 'IMG' || node.tagName === 'SOURCE') {
			const src = node.getAttribute('src') || ''
			if (/^https?:\/\//i.test(src)) {
				node.setAttribute('data-blocked-src', src)
				node.setAttribute('src', BLANK_GIF)
				node.removeAttribute('width')
				node.removeAttribute('height')
				if (node.hasAttribute('style')) {
					node.setAttribute('style', node.getAttribute('style').replace(/(width|height)\s*:\s*[^;]+;?/gi, ''))
				}
				blockedCount++
			}
		}
			if (node.tagName === 'TD' || node.tagName === 'TABLE' || node.tagName === 'BODY') {
				const bg = node.getAttribute('background') || ''
				if (/^https?:\/\//i.test(bg)) {
					node.setAttribute('data-blocked-bg', bg)
					node.removeAttribute('background')
					blockedCount++
				}
			}
			// Remove url(http...) from style attributes
			if (node.hasAttribute && node.hasAttribute('style')) {
				const style = node.getAttribute('style')
				if (style && /url\s*\(\s*https?:\/\//i.test(style)) {
					node.setAttribute('style', style.replace(/url\s*\(\s*https?:\/\/[^)]+\)/gi, ''))
				}
			}
		}
		DOMPurify.addHook('afterSanitizeElements', hook)
	}

	const clean = DOMPurify.sanitize(processed, {
		ALLOWED_TAGS,
		ALLOWED_ATTR,
	})

	if (blockRemote) {
		DOMPurify.removeHook('afterSanitizeElements')
	}

	return { html: clean, blockedCount }
}

/**
 * Re-run sanitizer with blockRemote:false to load blocked images.
 * Rewrites data-blocked-src back to src.
 */
export function unblockRemoteImages(html, attachments) {
	if (!html) return ''
	const doc = new DOMParser().parseFromString(html, 'text/html')
	const imgs = doc.querySelectorAll('[data-blocked-src]')
	for (const img of imgs) {
		const blocked = img.getAttribute('data-blocked-src')
		if (blocked) {
			img.setAttribute('src', blocked)
			img.removeAttribute('data-blocked-src')
		}
	}
	return DOMPurify.sanitize(doc.documentElement.outerHTML, {
		ALLOWED_TAGS,
		ALLOWED_ATTR,
	})
}
