/**
 * Custom Tiptap node that renders the signature ISOLATED in a sandboxed
 * iframe — 1:1 so wie der Empfänger sie sieht:
 *
 *  - KEIN DOMPurify/Eingriff: der zentrale Admin-Content (vertrauenswürdig)
 *    wird roh gerendert — MSO/Outlook-Strukturen überleben.
 *  - KEIN Editor-CSS-Interference: Tabellen-/Bild-Layout der Signatur
 *    bleibt exakt so (width:460px bleibt 460px, keine 100%-Aufweitung).
 *  - Die Bildreferenzen sind bereits von ComposeEditor::signatureBlock()
 *    auf die ladbaren Vorschau-URLs umgeschrieben (displaySignature).
 *  - Sandbox: allow-same-origin (Bilder + Höhenmessung), KEINE Scripts.
 *
 * Serialization: getHTML() emits `<div data-signature=""></div>` — the
 * ComposeEditor replaces that marker with the sanitized signature HTML
 * at send/draft time (die cid:-Variante für den Versand).
 *
 * Höhe: ResizeObserver auf dem iframe-body (Bilder laden nach — die Höhe
 * wächst automatisch mit).
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
			// Wrapper: hält den iframe und markiert den nicht-editierbaren Block
			const dom = document.createElement('div')
			dom.dataset.signature = ''
			dom.contentEditable = 'false'
			dom.style.userSelect = 'none'
			dom.classList.add('signature-node')
			// ProseMirror/Editor-Kontexte können NodeViews einengen — die
			// Signatur braucht die volle Breite (die Tabelle darin ist 460px).
			dom.style.display = 'block'
			dom.style.width = '100%'
			dom.style.overflowX = 'auto'

			const iframe = document.createElement('iframe')
			iframe.className = 'signature-node__frame'
			iframe.setAttribute('sandbox', 'allow-same-origin')
			iframe.setAttribute('title', 'Signature')
			iframe.style.width = '100%'
			iframe.style.minWidth = '460px'
			iframe.style.border = 'none'
			iframe.style.display = 'block'
			iframe.style.background = 'transparent'
			iframe.style.overflow = 'hidden'
			dom.appendChild(iframe)

			const html = node.attrs.html || ''

			// srcdoc: transparenter Body (der Editor-Hintergrund scheint durch),
			// keine Ränder — die Signatur sitzt visuell im Fluss des Editors.
			iframe.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
				+ 'html,body{margin:0;padding:0;background:transparent;color:inherit;'
				+ 'font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45;}'
				+ 'img{max-width:100%;height:auto;border:0;}'
				+ 'table{border-collapse:collapse;}'
				+ 'a{color:#0b6cbd;}'
				+ 'p{margin:0 0 10px;}'
				+ '</style></head><body>' + html + '</body></html>'

			// Höhe an den Inhalt anpassen — inkl. nachgeladener Bilder.
			const fit = () => {
				try {
					const doc = iframe.contentDocument
					if (doc && doc.body) {
						const h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight)
						if (h > 0) iframe.style.height = (h + 2) + 'px'
					}
				} catch (e) {
					// sandbox — Fallback: CSS-Mindesthöhe
				}
			}
			iframe.addEventListener('load', () => {
				fit()
				try {
					const doc = iframe.contentDocument
					if (doc && doc.body && typeof ResizeObserver !== 'undefined') {
						const ro = new ResizeObserver(fit)
						ro.observe(doc.body)
						iframe.addEventListener('unload', () => ro.disconnect(), { once: true })
					}
					// Bilder laden asynchron — nochmal nach dem ersten Paint messen
					doc?.querySelectorAll('img').forEach((img) => {
						if (!img.complete) img.addEventListener('load', fit, { once: true })
						img.addEventListener('error', fit, { once: true })
					})
				} catch (e) { /* ignore */ }
			})

			return { dom }
		}
	},
})
