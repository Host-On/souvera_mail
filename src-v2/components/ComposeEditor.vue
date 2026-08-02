<template>
	<div class="compose-overlay">
		<div class="compose-modal">
			<div class="compose-header">
				<NcButton type="primary" :disabled="!canSend || sending" @click="doSend">
					<template #icon><Send :size="20" /></template>
					{{ sending ? t('souvera_mail', 'Sending...') : t('souvera_mail', 'Send') }}
				</NcButton>
				<div class="compose-header-right">
					<NcButton type="tertiary" @click="$emit('cancel')">
						<template #icon><Close :size="20" /></template>
					</NcButton>
				</div>
			</div>

			<div class="compose-fields">
				<div class="compose-field">
					<label>{{ t('souvera_mail', 'From') }}</label>
					<input class="compose-input" :value="fromAddr" disabled />
				</div>
				<div class="compose-field">
					<label>{{ t('souvera_mail', 'To') }}</label>
					<NcTextField class="compose-input" v-model:value="toStr" :placeholder="t('souvera_mail', 'recipient@example.com')" />
				</div>
				<div class="compose-field">
					<label>{{ t('souvera_mail', 'Subject') }}</label>
					<NcTextField class="compose-input" v-model:value="subject" :placeholder="t('souvera_mail', 'Subject...')" />
				</div>
			</div>

			<div class="compose-tabs">
				<button class="compose-tab" :class="{ active: tab === 'body' }" @click="tab = 'body'">
					{{ t('souvera_mail', 'Message') }}
				</button>
				<button class="compose-tab" :class="{ active: tab === 'attachments' }" @click="tab = 'attachments'">
					{{ t('souvera_mail', 'Attachments') }}
					<span v-if="attachments.length > 0" class="compose-tab-badge">{{ attachments.length }}</span>
				</button>
			</div>

			<div v-show="tab === 'body'" class="compose-body-pane">
				<textarea ref="bodyEl" class="compose-body-textarea" :value="bodyText"
					@input="bodyText = $event.target.value"
					:placeholder="t('souvera_mail', 'Write your message...')" />
			</div>

			<div v-show="tab === 'attachments'" class="compose-attach-pane">
				<NcButton type="tertiary" @click="pickAttachment">
					<template #icon><Paperclip :size="20" /></template>
					{{ t('souvera_mail', 'Add attachment') }}
				</NcButton>
				<input ref="fileInput" type="file" multiple class="hidden-file-input" @change="onFilesSelected" />
				<div v-if="attachments.length > 0" class="attach-list">
					<div v-for="(att, idx) in attachments" :key="idx" class="attach-item">
						<span>{{ att.name }}</span>
						<span class="attach-size">{{ formatSize(att.data.length * 0.75) }}</span>
						<NcButton type="tertiary" size="small" @click="attachments.splice(idx, 1)">
							<template #icon><Close :size="14" /></template>
						</NcButton>
					</div>
				</div>
				<NcEmptyContent v-else :title="t('souvera_mail', 'No attachments')" />
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcEmptyContent } from '@nextcloud/vue'
import Send from 'vue-material-design-icons/Send.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ComposeEditor',
	components: { NcButton, NcTextField, NcEmptyContent, Send, Close, Paperclip },
	props: {
		replyTo: { type: Object, default: null },
		forwardOf: { type: Object, default: null },
	},
	emits: ['cancel', 'sent'],
	data() {
		return {
			visible: true,
			tab: 'body',
			toStr: this.replyTo?.fromAddress || '',
			subject: this.replyTo?.subject ? 'Re: ' + this.replyTo.subject : '',
			bodyText: '',
			fromAddr: '',
			attachments: [],
			sending: false,
		}
	},
	computed: {
		canSend() { return this.toStr.trim() !== '' && !this.sending },
	},
	methods: {
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const u = ['B', 'KB', 'MB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s) + ' ' + u[i]
		},
		pickAttachment() { this.$refs.fileInput?.click() },
		onFilesSelected(e) {
			for (const file of Array.from(e.target.files || [])) {
				const reader = new FileReader()
				reader.onload = () => {
					this.attachments.push({ name: file.name, type: file.type || 'application/octet-stream', data: reader.result.split(',')[1] || reader.result })
				}
				reader.readAsDataURL(file)
			}
			e.target.value = ''
		},
		async doSend() {
			if (!this.canSend) return
			this.sending = true
			try {
				await axios.post(generateUrl('/apps/souvera_mail/api/v2/send'), {
					to: this.toStr.split(',').map(s => s.trim()).filter(Boolean),
					subject: this.subject,
					bodyPlain: this.bodyText,
					attachments: this.attachments,
					inReplyTo: this.replyTo?.messageId || null,
				})
				this.visible = false
				this.$emit('sent')
			} catch(e) { console.error('Send failed', e) } finally { this.sending = false }
		},
	},
}
</script>

<style scoped>
.compose-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 1000; background: var(--color-main-background); display: flex; flex-direction: column; }
.compose-modal { display: flex; flex-direction: column; height: 100%; }
.compose-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--color-background-dark); border-bottom: 1px solid var(--color-border); }
.compose-header-right { display: flex; gap: 8px; }
.compose-fields { padding: 16px; }
.compose-field { margin-bottom: 10px; }
.compose-field label { display: block; font-size: 11px; color: var(--color-text-maxcontrast); margin-bottom: 3px; }
.compose-input { width: 100%; }
.compose-tabs { display: flex; border-bottom: 1px solid var(--color-border); padding: 0 16px; }
.compose-tab { padding: 8px 16px; border: none; background: none; cursor: pointer; font: inherit; color: var(--color-text-maxcontrast); border-bottom: 2px solid transparent; }
.compose-tab.active { color: var(--color-main-text); border-bottom-color: var(--color-primary-element); }
.compose-tab-badge { background: var(--color-primary-element); color: var(--color-primary-text); border-radius: 10px; padding: 0 6px; font-size: 11px; margin-left: 4px; }
.compose-body-pane { flex: 1; overflow: hidden; display: flex; }
.compose-body-textarea { flex: 1; border: none; padding: 16px; font: inherit; resize: none; background: var(--color-main-background); color: var(--color-main-text); }
.compose-body-textarea:focus { outline: none; }
.compose-attach-pane { flex: 1; padding: 16px; overflow-y: auto; }
.attach-list { margin-top: 12px; }
.attach-item { display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: var(--color-background-dark); border-radius: 6px; margin-bottom: 6px; }
.attach-size { font-size: 12px; color: var(--color-text-maxcontrast); }
.hidden-file-input { display: none; }
</style>
