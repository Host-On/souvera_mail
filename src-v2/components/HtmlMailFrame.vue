<template>
	<div class="html-mail-frame">
		<iframe
			:key="frameKey"
			ref="frame"
			:srcdoc="srcdoc"
			class="html-mail-frame__iframe"
			:style="{ height: frameHeight + 'px' }"
			:sandbox="'allow-same-origin allow-popups allow-popups-to-escape-sandbox'"
			@load="onFrameLoad"
		/>
	</div>
</template>

<script>
import { sanitizeMailHtml } from '../utils/mailSanitizer.js'

const BASE_CSS = `:root{color-scheme:light}html,body{margin:0;padding:0}body{padding:16px;background:#fff;color:#222;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;word-break:break-word;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 8px;padding-left:12px;border-left:2px solid #c9c9c9;color:#555}a{color:#0b6cbd}td[height],table[height]{height:auto!important}`

export default {
	name: 'HtmlMailFrame',
	components: {},
	props: {
		html: { type: String, required: true },
		attachments: { type: Array, default: () => [] },
		defaultAllowRemote: { type: Boolean, default: false },
	},
	emits: ['mailto', 'blocked'],
	data() {
		return {
			remoteAllowed: this.defaultAllowRemote,
			displayHtml: '',
			blockedCount: 0,
			frameHeight: 0,
			frameKey: 0,
			resizeObserver: null,
		}
	},
	watch: {
		html: { immediate: true, handler: 'rebuildContent' },
		attachments: { handler: 'rebuildContent' },
	},
	methods: {
		rebuildContent() {
			const { html, blockedCount } = sanitizeMailHtml(this.html, {
				attachments: this.attachments,
				blockRemote: !this.remoteAllowed,
			})
			this.blockedCount = blockedCount
			this.displayHtml = html
			this.frameKey++
			if (blockedCount > 0 && !this.remoteAllowed) {
				this.$emit('blocked', blockedCount)
			}
		},
		loadRemoteImages() {
			this.remoteAllowed = true
			this.blockedCount = 0
			const doc = this.$refs.frame?.contentDocument
			if (!doc) { this.frameKey++; return }
			const imgs = doc.querySelectorAll('[data-blocked-src]')
			imgs.forEach(img => {
				const src = img.getAttribute('data-blocked-src')
				if (src) {
					img.setAttribute('src', src)
					img.removeAttribute('data-blocked-src')
					img.removeAttribute('width')
					img.removeAttribute('height')
					if (img.hasAttribute('style')) {
						img.setAttribute('style', img.getAttribute('style').replace(/(width|height)\s*:\s*[^;]+;?/gi, ''))
					}
				}
			})
			const bgs = doc.querySelectorAll('[data-blocked-bg]')
			bgs.forEach(el => {
				const bg = el.getAttribute('data-blocked-bg')
				if (bg) {
					el.setAttribute('background', bg)
					el.removeAttribute('data-blocked-bg')
				}
			})
		},
		onFrameLoad() {
			const doc = this.$refs.frame?.contentDocument
			if (!doc) return

			this.resizeObserver?.disconnect()
			this.resizeObserver = new ResizeObserver(() => {
				const el = doc.documentElement
				const body = doc.body
				if (el && body) {
					this.frameHeight = Math.max(el.scrollHeight, body.scrollHeight, 200)
				}
			})
			this.resizeObserver.observe(doc.documentElement)
			if (doc.body) this.resizeObserver.observe(doc.body)

			const el = doc.documentElement
			const body = doc.body
			if (el && body) {
				this.frameHeight = Math.max(el.scrollHeight, body.scrollHeight, 200)
			}

			doc.addEventListener('click', (e) => {
				const a = e.target.closest('a')
				if (a) {
					const href = a.getAttribute('href') || ''
					if (href.startsWith('mailto:')) {
						e.preventDefault()
						const to = href.slice(7).split('?')[0]
						this.$emit('mailto', { to })
					}
				}
			})
		},
	},
	computed: {
		srcdoc() {
			return `<!doctype html><head><meta charset="utf-8"><base target="_blank"><style>${BASE_CSS}</style></head><body>${this.displayHtml}</body>`
		},
	},
	beforeUnmount() {
		this.resizeObserver?.disconnect()
	},
}
</script>

<style scoped>
.html-mail-frame__iframe {
	width: 100%;
	border: 0;
	display: block;
	background: #fff;
}
</style>
