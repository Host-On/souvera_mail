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

const BASE_CSS_LIGHT = `:root{color-scheme:light}html,body{margin:0;padding:0}body{background:#fff;color:#222;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;word-break:break-word;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 8px;padding-left:12px;border-left:2px solid #c9c9c9;color:#555}a{color:#0b6cbd}td[height],table[height]{height:auto!important}`

const BASE_CSS_DARK = `:root{color-scheme:dark}html,body{margin:0;padding:0}body{background:#1e2227;color:#d8dee4;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;word-break:break-word;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 8px;padding-left:12px;border-left:2px solid #4a5058;color:#9aa5b1}a{color:#4da3ff}td[height],table[height]{height:auto!important}`

export default {
	name: 'HtmlMailFrame',
	components: {},
	props: {
		html: { type: String, required: true },
		attachments: { type: Array, default: () => [] },
		defaultAllowRemote: { type: Boolean, default: false },
		remoteAllowed: { type: Boolean, default: false },
		// Thunderbird-style content background toggle: false = light,
		// true = dark (for emails that are unreadable in the current mode).
		darkMode: { type: Boolean, default: false },
	},
	emits: ['mailto', 'blocked'],
	data() {
		return {
			// Do NOT shadow the remoteAllowed prop — the parent controls it
			// and the watcher (below) fires when the parent toggles it.
			_displayHtml: '',
			_blockedCount: 0,
			frameHeight: 0,
			frameKey: 0,
			resizeObserver: null,
		}
	},
	watch: {
		html: { immediate: true, handler: 'rebuildContent' },
		attachments: { handler: 'rebuildContent' },
		remoteAllowed: { immediate: true, handler: 'loadRemoteImages' },
		darkMode: { handler: 'refreshTheme' },
	},
	methods: {
		rebuildContent() {
			const { html, blockedCount } = sanitizeMailHtml(this.html, {
				attachments: this.attachments,
				blockRemote: !this.remoteAllowed,
			})
			this._blockedCount = blockedCount
			this._displayHtml = html
			this.frameKey++
			if (blockedCount > 0 && !this.remoteAllowed) {
				this.$emit('blocked', blockedCount)
			}
		},
		loadRemoteImages() {
			if (!this.remoteAllowed) return
			this.rebuildContent()
		},
		refreshTheme() {
			// Rebuild the frame with the other theme CSS.
			this.frameKey++
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
			const css = this.darkMode ? BASE_CSS_DARK : BASE_CSS_LIGHT
			return `<!doctype html><head><meta charset="utf-8"><base target="_blank"><style>${css}</style></head><body>${this._displayHtml}</body>`
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
