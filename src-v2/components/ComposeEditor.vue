<template>
	<NcModal v-model:show="visible" size="large" @close="onClose">
		<div class="compose-layout">
			<div class="compose-layout__header">
				<h3>{{ composeTitle }}</h3>
			</div>

			<div v-if="identities.length > 1" class="compose-field">
				<label class="compose-field__label">{{ t('souvera_mail', 'From') }}</label>
				<NcSelect v-model="fromIdentity" :options="identities" label="label" />
			</div>

			<div class="compose-field">
				<label class="compose-field__label">{{ t('souvera_mail', 'To') }}</label>
				<RecipientField v-model="to" />
				<div class="compose-toggle-row">
					<NcButton variant="tertiary" size="small" @click="showCc = !showCc">{{ t('souvera_mail', 'Cc') }}</NcButton>
					<NcButton variant="tertiary" size="small" @click="showBcc = !showBcc">{{ t('souvera_mail', 'Bcc') }}</NcButton>
				</div>
				<RecipientField v-if="showCc || cc.length > 0" v-model="cc" />
				<RecipientField v-if="showBcc || bcc.length > 0" v-model="bcc" />
			</div>

			<div class="compose-field">
				<label class="compose-field__label">{{ t('souvera_mail', 'Subject') }}</label>
				<NcTextField v-model="subject"
					:placeholder="t('souvera_mail', 'Subject') + '…'" />
			</div>

			<div class="compose-field compose-field--body">
				<RichTextEditor ref="editor" v-model="bodyHtml"
					:placeholder="t('souvera_mail', 'Write your message…')" />
			</div>

			<AttachmentList v-if="attachments.length > 0"
				:attachments="attachments" @remove="attachments.splice($event, 1)" />

			<div class="compose-layout__footer">
				<div class="compose-layout__actions">
					<NcButton variant="primary" :disabled="!canSend || sending" @click="doSend">
						<template #icon><Send :size="20" /></template>
						{{ sending ? t('souvera_mail', 'Sending…') : t('souvera_mail', 'Send') }}
					</NcButton>
					<NcButton variant="tertiary" @click="pickAttachment">
						<template #icon><Paperclip :size="20" /></template>
						{{ t('souvera_mail', 'Attach') }}
					</NcButton>
				</div>
				<div class="compose-layout__status">
					<span v-if="savedDraftId" class="draft-saved">
						{{ t('souvera_mail', 'Draft saved') }}
					</span>
				</div>
				<NcButton variant="tertiary" @click="onDiscard">
					<template #icon><TrashCan :size="20" /></template>
					{{ t('souvera_mail', 'Discard') }}
				</NcButton>
			</div>
		</div>
		<input ref="fileInput" type="file" multiple class="hidden-file-input" @change="onFilesSelected" />
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField, NcSelect } from '@nextcloud/vue'
import Send from 'vue-material-design-icons/Send.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import RecipientField from './composer/RecipientField.vue'
import RichTextEditor from './composer/RichTextEditor.vue'
import AttachmentList from './composer/AttachmentList.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { sanitizeMailHtml } from '../utils/mailSanitizer.js'
import { buildReplyQuote, buildForwardBody } from '../utils/quoteBuilder.js'

let draftTimer = null

export default {
	name: 'ComposeEditor',
	components: { NcModal, NcButton, NcTextField, NcSelect, Send, Paperclip, TrashCan, RecipientField, RichTextEditor, AttachmentList },
	props: {
		replyTo: { type: Object, default: null },
		forwardOf: { type: Object, default: null },
		mode: { type: String, default: 'new' },
		originalEmail: { type: Object, default: null },
	},
	emits: ['cancel', 'sent'],
	data() {
		const idPrefill = []
		if (this.replyTo?.fromAddress) {
			idPrefill.push({ name: this.replyTo.fromName || '', email: this.replyTo.fromAddress })
		}
		if (this.forwardOf?.fromAddress) {
			idPrefill.push({ name: this.forwardOf.fromName || '', email: this.forwardOf.fromAddress })
		}
		// Deduplicate for replyAll scenario
		const seen = new Set()
		const toPrefill = idPrefill.filter(r => { const k = r.email.toLowerCase(); if (seen.has(k)) return false; seen.add(k); return true })

		let ccPrefill = []
		if (this.mode === 'replyAll' && this.originalEmail) {
			const ownAddr = '' // filled by identities later
			const toList = this.originalEmail.toList || []
			const ccList = this.originalEmail.ccList || []
			ccPrefill = [...toList, ...ccList].filter(r => r.email !== ownAddr && r.email !== (this.originalEmail.fromAddress || ''))
		}

		return {
			visible: true,
			fromIdentity: { id: null, name: '', email: '' },
			identities: [],
			to: toPrefill,
			cc: ccPrefill,
			bcc: [],
			showCc: ccPrefill.length > 0,
			showBcc: false,
			subject: this.prefillSubject(),
			bodyHtml: '',
			attachments: [],
			forwardAttachments: [],
			sending: false,
			dirty: false,
			savedDraftId: null,
			discardingDraftId: null,
		}
	},
	computed: {
		composeTitle() {
			if (this.mode === 'reply') return t('souvera_mail', 'Reply')
			if (this.mode === 'replyAll') return t('souvera_mail', 'Reply all')
			if (this.mode === 'forward') return t('souvera_mail', 'Forward')
			return t('souvera_mail', 'New message')
		},
		canSend() {
			return (this.to.length > 0 || this.cc.length > 0 || this.bcc.length > 0) && !this.sending
		},
	},
	watch: {
		to: { deep: true, handler() { this.markDirty() } },
		cc: { deep: true, handler() { this.markDirty() } },
		bcc: { deep: true, handler() { this.markDirty() } },
		subject() { this.markDirty() },
		bodyHtml() { this.markDirty() },
	},
	async mounted() {
		await this.loadIdentities()
		if (this.mode === 'reply' || this.mode === 'replyAll') {
			this.buildReplyContent()
		} else if (this.mode === 'forward') {
			this.buildForwardContent()
		}
	},
	beforeUnmount() { clearTimeout(draftTimer) },
	methods: {
		prefillSubject() {
			if (this.replyTo?.subject) {
				const s = this.replyTo.subject
				return s.match(/^(Re|Fwd):\s*/i) ? s : `Re: ${s}`
			}
			if (this.forwardOf?.subject) {
				const s = this.forwardOf.subject
				return s.match(/^(Fwd):\s*/i) ? s : `Fwd: ${s}`
			}
			return ''
		},
		async loadIdentities() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/identities'))
				const list = (data.identities || []).map(i => ({ id: i.id, label: `${i.name || ''} <${i.email}>`, name: i.name, email: i.email }))
				this.identities = list
				if (list.length > 0) this.fromIdentity = list[0]
			} catch (e) {
				console.error('Failed to load identities', e)
			}
		},
		buildReplyContent() {
			const email = this.originalEmail
			if (!email) return
			const body = email.htmlBody || email.plainBody || ''
			const { html } = sanitizeMailHtml(body, { attachments: email.attachments || [], blockRemote: false })
			const quote = buildReplyQuote(email, html)
			this.$nextTick(() => {
				this.$refs.editor?.insertHtml(quote)
				this.$refs.editor?.focus()
			})
		},
		buildForwardContent() {
			const email = this.originalEmail
			if (!email) return
			const body = email.htmlBody || email.plainBody || ''
			const { html } = sanitizeMailHtml(body, { attachments: email.attachments || [], blockRemote: false })
			const quote = buildForwardBody(email, html)
			this.forwardAttachments = (email.attachments || []).map(a => ({
				blobId: a.blobId, name: a.name, type: a.type, size: a.size,
			}))
			this.$nextTick(() => {
				this.$refs.editor?.insertHtml(quote)
			})
		},
		markDirty() {
			this.dirty = true
			clearTimeout(draftTimer)
			draftTimer = setTimeout(() => this.saveDraft(), 3000)
		},
		async saveDraft() {
			try {
				const payload = this.buildPayload()
				if (this.savedDraftId) {
					await axios.put(generateUrl('/apps/souvera_mail/api/v2/drafts/' + this.savedDraftId), payload)
				} else {
					const { data } = await axios.post(generateUrl('/apps/souvera_mail/api/v2/drafts'), payload)
					this.savedDraftId = data.draftId
				}
			} catch (e) {
				console.error('Draft save failed', e)
			}
		},
		buildPayload() {
			return {
				identityId: this.fromIdentity.id,
				to: this.to.map(r => r.email),
				cc: this.cc.map(r => r.email),
				bcc: this.bcc.map(r => r.email),
				subject: this.subject,
				bodyHtml: this.bodyHtml,
				bodyPlain: this.bodyHtml.replace(/<[^>]+>/g, ''),
				attachments: this.attachments.map(a => ({
					name: a.name, type: a.type || 'application/octet-stream',
					data: a.data || null, blobId: a.blobId || null,
				})),
				inReplyTo: this.replyTo?.messageId || null,
				references: this.replyTo?.references || null,
				draftId: this.savedDraftId,
			}
		},
		async doSend() {
			if (!this.canSend) return
			this.sending = true
			try {
				const payload = this.buildPayload()
				if (this.forwardAttachments.length > 0) {
					payload.attachments.push(...this.forwardAttachments)
				}
				await axios.post(generateUrl('/apps/souvera_mail/api/v2/send'), payload)
				if (this.savedDraftId) {
					try { await axios.delete(generateUrl('/apps/souvera_mail/api/v2/drafts/' + this.savedDraftId)) } catch {}
				}
				this.$emit('sent')
			} catch (e) {
				console.error('Send failed', e)
				alert(e.response?.data?.error || t('souvera_mail', 'Failed to send message'))
			} finally {
				this.sending = false
			}
		},
		pickAttachment() { this.$refs.fileInput?.click() },
		onFilesSelected(e) {
			for (const file of Array.from(e.target.files || [])) {
				const reader = new FileReader()
				reader.onload = () => {
					this.attachments.push({
						name: file.name,
						type: file.type || 'application/octet-stream',
						size: file.size,
						data: reader.result.split(',')[1] || reader.result,
					})
				}
				reader.readAsDataURL(file)
			}
			e.target.value = ''
		},
		onClose() {
			if (this.dirty) {
				if (!confirm(t('souvera_mail', 'Discard unsaved changes?'))) return
			}
			this.$emit('cancel')
		},
		onDiscard() {
			if (confirm(t('souvera_mail', 'Discard this message?'))) {
				if (this.savedDraftId) {
					this.discardingDraftId = this.savedDraftId
					axios.delete(generateUrl('/apps/souvera_mail/api/v2/drafts/' + this.savedDraftId)).catch(() => {})
				}
				this.$emit('cancel')
			}
		},
	},
}
</script>

<style scoped>
.compose-layout { display: flex; flex-direction: column; max-height: 85vh; overflow: hidden; }

.compose-layout__header { padding: 12px 20px; border-bottom: 1px solid var(--color-border); flex-shrink: 0; }
.compose-layout__header h3 { margin: 0; font-size: 16px; font-weight: 600; }

.compose-field {
	padding: 12px 20px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

/* Match all form inputs to RecipientField chips: same border, radius, background */
.compose-field :deep(.v-select .vs__dropdown-toggle),
.compose-field :deep(.native-select),
.compose-field :deep(input:not([type=file])) {
	border: 1px solid var(--color-border) !important;
	border-radius: var(--border-radius-large) !important;
	background: var(--color-main-background);
	min-height: 40px;
	padding: 6px 12px;
	width: 100%; box-sizing: border-box;
	font-size: 14px;
}

.compose-field--body {
	padding: 0;
	flex: 1; min-height: 250px;
	overflow: hidden;
	display: flex; flex-direction: column;
}
.compose-field--body :deep(.richtext-editor) {
	border: 0 !important;
	border-radius: 0 !important;
	flex: 1; height: auto;
}
.compose-field--body :deep(.richtext-editor__toolbar) {
	border-bottom: 1px solid var(--color-border);
	padding: 6px 20px;
	flex-shrink: 0;
}
.compose-field--body :deep(.richtext-editor__content) {
	flex: 1; min-height: 200px;
	padding: 12px 20px;
	overflow-y: auto;
}

.compose-field__label {
	display: block;
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-bottom: 6px;
}

.compose-toggle-row { display: flex; gap: 4px; margin-top: 8px; }

.compose-layout__footer {
	display: flex; align-items: center; justify-content: space-between;
	padding: 10px 20px; border-top: 1px solid var(--color-border);
	gap: 8px; flex-shrink: 0;
}
.compose-layout__actions { display: flex; gap: 8px; }
.compose-layout__status { flex: 1; text-align: center; }
.draft-saved { font-size: 12px; color: var(--color-text-maxcontrast); }
.hidden-file-input { display: none; }
</style>
