<template>
	<div class="html-mail-frame">
		<NcNoteCard v-if="blockedCount > 0 && !remoteAllowed"
			type="info">
			{{ t('souvera_mail', 'External images blocked ({count})', { count: blockedCount }) }}
			<NcButton class="html-mail-frame__load-btn" @click="loadRemoteImages">
				{{ t('souvera_mail', 'Load images') }}
			</NcButton>
		</NcNoteCard>

		<iframe
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
import { NcNoteCard, NcButton } from '@nextcloud/vue'
import { sanitizeMailHtml, unblockRemoteImages } from '../utils/mailSanitizer.js'

const BASE_CSS = `:root{color-scheme:light}html,body{margin:0;padding:0}body{padding:16px;background:#fff;color:#222;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;word-break:break-word;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 8px;padding-left:12px;border-left:2px solid #c9c9c9;color:#555}a{color:#0b6cbd}`

export default {
	name: 'HtmlMailFrame',
	components: { NcNoteCard, NcButton },
	props: {
		html: { type: String, required: true },
		attachments: { type: Array, default: () => [] },
		defaultAllowRemote: { type: Boolean, default: false },
	},
	emits: ['mailto'],
	data() {
		return {
			remoteAllowed: this.defaultAllowRemote,
			blockedCount: 0,
			frameHeight: 0,
			resizeObserver: null,
		}
	},
	computed: {
		srcdoc() {
			const { html, blockedCount } = sanitizeMailHtml(this.html, {
				attachments: this.attachments,
				blockRemote: !this.remoteAllowed,
			})
			this.blockedCount = blockedCount
			return `<!doctype html><head><meta charset="utf-8"><base target="_blank"><style>${BASE_CSS}</style></head><body>${html}</body>`
		},
	},
	methods: {
		loadRemoteImages() {
			this.remoteAllowed = true
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

			// Initial height
			const el = doc.documentElement
			const body = doc.body
			if (el && body) {
				this.frameHeight = Math.max(el.scrollHeight, body.scrollHeight, 200)
			}

			// Click interception for mailto and anchor links
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
	beforeUnmount() {
		this.resizeObserver?.disconnect()
	},
}
</script>

<style scoped>
.html-mail-frame__load-btn { margin-top: 8px; }
.html-mail-frame__iframe {
	width: 100%;
	border: 0;
	display: block;
	border-radius: 8px;
	background: #fff;
}
</style>
