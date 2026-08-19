/**
 * Custom Tiptap node that preserves RAW HTML verbatim (signatures with
 * tables, images, inline styles). The raw HTML is stored in the `html`
 * attribute and rendered via a non-editable NodeView, so the editor
 * displays it faithfully without normalising it.
 *
 * Serialization: getHTML() emits `<div data-signature=""></div>` — the
 * ComposeEditor replaces that marker with the sanitized signature HTML
 * at send/draft time.
 */
import { Node } from '@tiptap/core'

export const Signature = Node.create({
	name: 'signature',
	group: 'block',
	atom: true,

	addAttributes() {
		return {
			html: { default: '' },
		}
	},

	parseHTML() {
		return [
			{
				tag: 'div[data-signature]',
				getAttrs: (el) => ({ html: el.innerHTML }),
			},
		]
	},

	renderHTML() {
		return ['div', { 'data-signature': '' }]
	},

	addNodeView() {
		return ({ node }) => {
			const dom = document.createElement('div')
			dom.dataset.signature = ''
			dom.innerHTML = node.attrs.html || ''
			dom.contentEditable = 'false'
			dom.style.userSelect = 'none'
			dom.classList.add('signature-node')
			return { dom }
		}
	},
})
