<template>
	<NcModal v-model:show="visible" size="large" @close="$emit('cancel')">
		<div class="compose-container">
			<div class="compose-header">
				<NcButton variant="primary" :disabled="!canSend || sending" @click="doSend">
					<template #icon><Send :size="20" /></template>
					{{ sending ? t('souvera_mail', 'Sending...') : t('souvera_mail', 'Send') }}
				</NcButton>
			</div>
			<div class="compose-fields">
				<div class="compose-field">
					<label>{{ t('souvera_mail', 'To') }}</label>
					<div class="compose-autocomplete-wrapper">
						<NcTextField class="compose-input"
							v-model="toStr"
							:placeholder="t('souvera_mail', 'recipient@example.com')"
							@input="onToInput" />
						<ul v-if="contactSuggestions.length > 0" class="compose-suggestions">
							<li v-for="c in contactSuggestions" :key="c.email" class="suggestion-item" @mousedown.prevent="selectContact(c)">
								<span class="suggestion-name">{{ c.name }}</span>
								<span class="suggestion-email">{{ c.email }}</span>
							</li>
						</ul>
					</div>
				</div>
				<div class="compose-field">
					<label>{{ t('souvera_mail', 'Subject') }}</label>
					<NcTextField class="compose-input" v-model="subject" :placeholder="t('souvera_mail', 'Subject...')" />
				</div>
			</div>
			<div class="compose-tabs">
				<button class="compose-tab" :class="{ active: tab === 'body' }" @click="tab = 'body'">{{ t('souvera_mail', 'Message') }}</button>
				<button class="compose-tab" :class="{ active: tab === 'attachments' }" @click="tab = 'attachments'">
					{{ t('souvera_mail', 'Attachments') }}
					<span v-if="attachments.length > 0" class="compose-tab-badge">{{ attachments.length }}</span>
				</button>
			</div>
			<div v-show="tab === 'body'" class="compose-body-pane">
				<textarea class="compose-body-textarea" v-model="bodyText" :placeholder="t('souvera_mail', 'Write your message...')"></textarea>
			</div>
			<div v-show="tab === 'attachments'" class="compose-attach-pane">
				<NcButton variant="primary" @click="pickAttachment">
					<template #icon><Paperclip :size="20" /></template>
					{{ t('souvera_mail', 'Add attachment') }}
				</NcButton>
				<input ref="fileInput" type="file" multiple class="hidden-file-input" @change="onFilesSelected" />
				<div v-if="attachments.length > 0" class="attach-list">
					<div v-for="(att, idx) in attachments" :key="idx" class="attach-item">
						<span>{{ att.name }}</span>
						<span class="attach-size">{{ formatSize(Math.round(att.data.length * 0.75)) }}</span>
						<NcButton variant="tertiary" size="small" :aria-label="t('souvera_mail', 'Remove attachment')" @click="attachments.splice(idx, 1)">
							<template #icon><Close :size="14" /></template>
						</NcButton>
					</div>
				</div>
				<NcEmptyContent v-else :title="t('souvera_mail', 'No attachments')" />
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField, NcEmptyContent } from '@nextcloud/vue'
import Send from 'vue-material-design-icons/Send.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

let searchTimer = null

export default {
	name: 'ComposeEditor',
	components: { NcModal, NcButton, NcTextField, NcEmptyContent, Send, Close, Paperclip },
	props: { replyTo: { type: Object, default: null }, forwardOf: { type: Object, default: null } },
	emits: ['cancel', 'sent'],
	data() {
		return {
			visible: true, tab: 'body',
			toStr: this.replyTo?.fromAddress || '',
			subject: this.replyTo?.subject ? 'Re: ' + this.replyTo.subject : '',
			bodyText: '', fromAddr: '',
			attachments: [], sending: false, contactSuggestions: [],
		}
	},
	computed: { canSend() { return this.toStr.trim() !== '' && !this.sending } },
	methods: {
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const u = ['B', 'KB', 'MB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s) + ' ' + u[i]
		},
		onToInput() {
			this.contactSuggestions = []
			clearTimeout(searchTimer)
			const q = this.toStr.trim()
			if (q.length < 2) return
			searchTimer = setTimeout(async () => {
				try {
					const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/contacts/search'), { params: { q, limit: 8 } })
					this.contactSuggestions = data.contacts || []
				} catch { this.contactSuggestions = [] }
			}, 300)
		},
		selectContact(contact) {
			const parts = this.toStr.split(',').map(s => s.trim()).filter(Boolean)
			parts.pop()
			parts.push(contact.name ? `"${contact.name}" <${contact.email}>` : contact.email)
			this.toStr = parts.join(', ') + ', '
			this.contactSuggestions = []
		},
		pickAttachment() { this.$refs.fileInput?.click() },
		onFilesSelected(e) {
			for (const file of Array.from(e.target.files || [])) {
				const reader = new FileReader()
				reader.onload = () => { this.attachments.push({ name: file.name, type: file.type || 'application/octet-stream', data: reader.result.split(',')[1] || reader.result }) }
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
				this.$emit('sent')
			} catch { } finally { this.sending = false }
		},
	},
	beforeUnmount() { clearTimeout(searchTimer) },
}
</script>

<style scoped>
.compose-container { display: flex; flex-direction: column; max-height: 85vh; }
.compose-header { display: flex; justify-content: flex-end; padding: 12px 16px; border-bottom: 1px solid var(--color-border); }
.compose-fields { padding: 16px; }
.compose-field { margin-bottom: 10px; }
.compose-field label { display: block; font-size: 11px; color: var(--color-text-maxcontrast); margin-bottom: 3px; }
.compose-input { width: 100%; }
.compose-autocomplete-wrapper { position: relative; }
.compose-suggestions { position: absolute; top: 100%; left: 0; right: 0; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: 6px; max-height: 200px; overflow-y: auto; z-index: 10; list-style: none; margin: 2px 0 0; padding: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
.suggestion-item { display: flex; justify-content: space-between; padding: 8px 12px; cursor: pointer; }
.suggestion-item:hover { background: var(--color-background-hover); }
.suggestion-name { font-weight: 600; }
.suggestion-email { font-size: 12px; color: var(--color-text-maxcontrast); }
.compose-tabs { display: flex; border-bottom: 1px solid var(--color-border); padding: 0 16px; }
.compose-tab { padding: 8px 16px; border: none; background: none; cursor: pointer; font: inherit; color: var(--color-text-maxcontrast); border-bottom: 2px solid transparent; }
.compose-tab.active { color: var(--color-main-text); border-bottom-color: var(--color-primary-element); }
.compose-tab-badge { background: var(--color-primary-element); color: var(--color-primary-text); border-radius: 10px; padding: 0 6px; font-size: 11px; margin-left: 4px; }
.compose-body-pane { flex: 1; min-height: 200px; display: flex; }
.compose-body-textarea { flex: 1; border: none; padding: 16px; font: inherit; resize: vertical; min-height: 200px; background: var(--color-main-background); color: var(--color-main-text); }
.compose-body-textarea:focus { outline: none; }
.compose-attach-pane { padding: 16px; overflow-y: auto; min-height: 200px; }
.attach-list { margin-top: 12px; }
.attach-item { display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: var(--color-background-dark); border-radius: 6px; margin-bottom: 6px; }
.attach-size { font-size: 12px; color: var(--color-text-maxcontrast); }
.hidden-file-input { display: none; }
</style>
