<template>
	<div class="compose-editor">
		<header class="compose-header">
			<h2>{{ isReply ? t('souvera_mail', 'Reply') : isForward ? t('souvera_mail', 'Forward') : t('souvera_mail', 'New message') }}</h2>
			<NcButton type="tertiary" @click="$emit('cancel')">
				<template #icon><Close :size="20" /></template>
			</NcButton>
		</header>

		<div class="compose-field">
			<label>{{ t('souvera_mail', 'To') }}</label>
			<NcTextField :value.sync="toStr" :placeholder="t('souvera_mail', 'recipient@example.com')"
				:disabled="sending" />
		</div>
		<div class="compose-field">
			<label>{{ t('souvera_mail', 'Subject') }}</label>
			<NcTextField :value.sync="subject" :placeholder="t('souvera_mail', 'Subject...')"
				:disabled="sending" />
		</div>

		<div class="compose-field">
			<label>{{ t('souvera_mail', 'Message') }}</label>
			<textarea ref="bodyEl" class="compose-body" :value="bodyText"
				:disabled="sending" @input="bodyText = $event.target.value"
				:placeholder="t('souvera_mail', 'Write your message...')" rows="12" />
		</div>

		<div v-if="attachments.length > 0" class="compose-attachments">
			<div v-for="(att, idx) in attachments" :key="idx" class="compose-attach-chip">
				<span>{{ att.name }} ({{ formatSize(att.data.length * 0.75) }})</span>
				<NcButton type="tertiary" size="small" @click="attachments.splice(idx, 1)" :disabled="sending">
					<template #icon><Close :size="14" /></template>
				</NcButton>
			</div>
		</div>

		<footer class="compose-footer">
			<NcButton type="tertiary" @click="pickAttachment" :disabled="sending">
				<template #icon><Paperclip :size="20" /></template>
			</NcButton>
			<input ref="fileInput" type="file" multiple class="compose-file-input"
				@change="onFilesSelected" />
			<NcButton type="primary" @click="doSend" :disabled="sending || !canSend">
				<template #icon v-if="!sending"><Send :size="20" /></template>
				{{ sending ? t('souvera_mail', 'Sending...') : t('souvera_mail', 'Send') }}
			</NcButton>
		</footer>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ComposeEditor',
	components: { NcButton, NcTextField, Close, Send, Paperclip },
	props: {
		replyTo: { type: Object, default: null },
		forwardOf: { type: Object, default: null },
		initialTo: { type: String, default: '' },
		initialSubject: { type: String, default: '' },
		initialBody: { type: String, default: '' },
	},
	emits: ['cancel', 'sent'],
	data() {
		return {
			toStr: this.initialTo || (this.replyTo ? this.replyTo.fromAddress : ''),
			subject: this.initialSubject || (this.replyTo && !this.replyTo.subject?.startsWith('Re:')
				? 'Re: ' + this.replyTo.subject
				: this.forwardOf ? 'Fwd: ' + this.forwardOf.subject : ''),
			bodyText: this.initialBody || '',
			attachments: [],
			sending: false,
		}
	},
	computed: {
		isReply() { return !!this.replyTo },
		isForward() { return !!this.forwardOf },
		canSend() { return this.toStr.trim() !== '' && !this.sending },
	},
	methods: {
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const u = ['B', 'KB', 'MB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s) + ' ' + u[i]
		},
		pickAttachment() { this.$refs.fileInput.click() },
		onFilesSelected(e) {
			const files = Array.from(e.target.files || [])
			for (const file of files) {
				const reader = new FileReader()
				reader.onload = () => {
					this.attachments.push({
						name: file.name,
						type: file.type || 'application/octet-stream',
						data: reader.result.split(',')[1] || reader.result,
					})
				}
				reader.readAsDataURL(file)
			}
			e.target.value = ''
		},
		async doSend() {
			this.sending = true
			try {
				const payload = {
					to: this.toStr.split(',').map(s => s.trim()).filter(Boolean),
					subject: this.subject,
					bodyPlain: this.bodyText,
					attachments: this.attachments,
					inReplyTo: this.replyTo?.messageId || null,
				}
				await axios.post(generateUrl('/apps/souvera_mail/api/v2/send'), payload)
				this.$emit('sent')
			} catch (e) {
				console.error('Send failed', e)
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.compose-editor { padding: 16px; max-width: 640px; margin: 0 auto; }
.compose-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.compose-header h2 { margin: 0; font-size: 16px; }
.compose-field { margin-bottom: 10px; }
.compose-field label { display: block; font-size: 11px; color: var(--color-text-maxcontrast); margin-bottom: 2px; }
.compose-body { width: 100%; resize: vertical; font: inherit; padding: 8px; border: 1px solid var(--color-border); border-radius: 4px; background: var(--color-main-background); color: var(--color-main-text); }
.compose-attachments { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.compose-attach-chip { display: flex; align-items: center; gap: 4px; background: var(--color-background-dark); border-radius: 12px; padding: 2px 8px; font-size: 12px; }
.compose-footer { display: flex; justify-content: space-between; align-items: center; }
.compose-file-input { display: none; }
</style>
