<template>
	<NcModal v-model:show="visible" :size="fullscreen ? '' : 'large'" @close="onClose"
		:class="['compose-modal', { 'compose-modal--fullscreen': fullscreen }]">
		<div class="compose-layout">
			<div class="compose-layout__header">
				<h3>{{ composeTitle }}</h3>
				<NcButton variant="tertiary" size="small"
					:aria-label="fullscreen ? t('souvera_mail', 'Exit fullscreen') : t('souvera_mail', 'Fullscreen')"
					@click="fullscreen = !fullscreen">
					<template #icon>
						<ArrowExpand v-if="!fullscreen" :size="18" />
						<ArrowCollapse v-else :size="18" />
					</template>
				</NcButton>
			</div>

			<div v-if="identities.length > 1" class="compose-row">
				<span class="compose-row__label">{{ t('souvera_mail', 'From') }}</span>
				<div class="compose-row__select-wrap">
					<select v-model="fromIdentityId" class="native-select">
						<option v-for="identity in identities" :key="identity.id" :value="identity.id">
							{{ identity.label }}
						</option>
					</select>
					<ChevronDown :size="16" class="compose-row__select-icon" />
				</div>
			</div>
			<div v-else-if="identities.length === 1" class="compose-row">
				<span class="compose-row__label">{{ t('souvera_mail', 'From') }}</span>
				<div class="compose-row__select-wrap">
					<div class="compose-row__static-text">{{ identities[0].label }}</div>
				</div>
			</div>

			<div class="compose-row">
				<span class="compose-row__label">{{ t('souvera_mail', 'To') }}</span>
				<div class="compose-row__input-wrap">
					<RecipientField v-model="to" :placeholder="t('souvera_mail', 'To') + '…'" />
				</div>
				<div class="compose-row__toggles">
					<button class="toggle-btn" :class="{ 'toggle-btn--active': showCc }" @click="showCc = !showCc">{{ t('souvera_mail', 'Cc') }}</button>
					<button class="toggle-btn" :class="{ 'toggle-btn--active': showBcc }" @click="showBcc = !showBcc">{{ t('souvera_mail', 'Bcc') }}</button>
				</div>
			</div>
			<div class="compose-row" v-if="showCc || cc.length > 0">
				<span class="compose-row__label">{{ t('souvera_mail', 'Cc') }}</span>
				<div class="compose-row__input-wrap">
					<RecipientField v-model="cc" :placeholder="t('souvera_mail', 'Cc') + '…'" />
				</div>
			</div>
			<div class="compose-row" v-if="showBcc || bcc.length > 0">
				<span class="compose-row__label">{{ t('souvera_mail', 'Bcc') }}</span>
				<div class="compose-row__input-wrap">
					<RecipientField v-model="bcc" :placeholder="t('souvera_mail', 'Bcc') + '…'" />
				</div>
			</div>

			<div class="compose-row">
				<span class="compose-row__label">{{ t('souvera_mail', 'Subject') }}</span>
				<div class="compose-row__input-wrap">
					<NcTextField v-model="subject"
						:placeholder="t('souvera_mail', 'Subject') + '…'" />
				</div>
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
				<NcButton variant="tertiary" @click="showCloudPicker = true">
					<template #icon><Cloud :size="20" /></template>
					{{ t('souvera_mail', 'From Cloud') }}
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
		<CloudFilePicker v-if="showCloudPicker" @close="showCloudPicker = false" @attach="onCloudFileAttached" />
	</NcModal>

	<NcDialog v-if="showCloseDialog" :name="t('souvera_mail', 'Draft')"
		:open.sync="true"
		size="normal"
		@update:open="showCloseDialog = $event">
		<div class="compose-close-dialog">
			<div class="compose-close-dialog__body">
				<FileEditOutline :size="42" class="compose-close-dialog__icon" />
				<p class="compose-close-dialog__text">{{ t('souvera_mail', 'Do you want to keep this draft?') }}</p>
			</div>
			<div class="compose-close-dialog__actions">
				<NcButton variant="tertiary" @click="showCloseDialog = false">{{ t('souvera_mail', 'Cancel') }}</NcButton>
				<NcButton variant="error" @click="discardDraftAndClose">
					<template #icon><TrashCan :size="18" /></template>
					{{ t('souvera_mail', 'Discard') }}
				</NcButton>
				<NcButton variant="primary" @click="keepDraftAndClose">
					<template #icon><ContentSave :size="18" /></template>
					{{ t('souvera_mail', 'Keep draft') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcModal, NcDialog, NcButton, NcTextField } from '@nextcloud/vue'
import Send from 'vue-material-design-icons/Send.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import FileEditOutline from 'vue-material-design-icons/FileEditOutline.vue'
import Cloud from 'vue-material-design-icons/Cloud.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import Fullscreen from 'vue-material-design-icons/Fullscreen.vue'
import FullscreenExit from 'vue-material-design-icons/FullscreenExit.vue'
import RecipientField from './composer/RecipientField.vue'
import RichTextEditor from './composer/RichTextEditor.vue'
import AttachmentList from './composer/AttachmentList.vue'
import CloudFilePicker from './CloudFilePicker.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import DOMPurify from 'dompurify'
import { sanitizeMailHtml } from '../utils/mailSanitizer.js'
import { buildReplyQuote, buildForwardBody } from '../utils/quoteBuilder.js'

let draftTimer = null

export default {
	name: 'ComposeEditor',
	components: { NcModal, NcDialog, NcButton, NcTextField, Send, Paperclip, TrashCan, ChevronDown, Fullscreen, FullscreenExit, Cloud, RecipientField, RichTextEditor, AttachmentList, CloudFilePicker },
	props: {
		replyTo: { type: Object, default: null },
		forwardOf: { type: Object, default: null },
		mode: { type: String, default: 'new' },
		originalEmail: { type: Object, default: null },
		initialExtIdentity: { type: String, default: null },
	},
	emits: ['cancel', 'sent'],
	data() {
		const idPrefill = []
		if (this.replyTo?.fromAddress) {
			idPrefill.push({ name: this.replyTo.fromName || '', email: this.replyTo.fromAddress })
		}
		if (this.forwardOf?.fromAddress && !this.forwardOf.noPrefillRecipients) {
			idPrefill.push({ name: this.forwardOf.fromName || '', email: this.forwardOf.fromAddress })
		}
		// Deduplicate for replyAll scenario
		const seen = new Set()
		const toPrefill = idPrefill.filter(r => { const k = r.email.toLowerCase(); if (seen.has(k)) return false; seen.add(k); return true })

		let ccPrefill = []
		// replyAll recipients are filled from originalEmail in the watcher —
		// originalEmail is always null at data()-time on the router path.

		return {
			visible: true,
			fullscreen: false,
			fromIdentityId: null,
			defaultIdentityId: '',
			identities: [],
			to: toPrefill,
			cc: ccPrefill,
			bcc: [],
			showCc: false,
			showBcc: false,
			subject: this.prefillSubject(),
			bodyHtml: '',
			attachments: [],
			forwardAttachments: [],
			sending: false,
			dirty: false,
			showCloudPicker: false,
			savedDraftId: null,
			discardingDraftId: null,
			showCloseDialog: false,
			signatureHtml: '',
			signatureEnabled: false,
			identitySignatures: {},
			replyPosition: 'above',
			signaturePosition: 'above',
		}
	},
	computed: {
		composeTitle() {
			if (this.mode === 'reply') return this.t('souvera_mail', 'Reply')
			if (this.mode === 'replyAll') return this.t('souvera_mail', 'Reply all')
			if (this.mode === 'forward') return this.t('souvera_mail', 'Forward')
			return this.t('souvera_mail', 'New message')
		},
		canSend() {
			return (this.to.length > 0 || this.cc.length > 0 || this.bcc.length > 0) && !this.sending
		},
		// The signature that applies for the currently selected sender.
		// Precedence: per-identity entry (enabled) → per-identity entry
		// (disabled = explicit opt-out) → legacy GLOBAL signature as
		// fallback for identities the user never customized.
		effectiveSignature() {
			const entry = this.identitySignatures[this.fromIdentityId]
			if (entry) {
				if (entry.enabled && entry.html) {
					return { html: entry.html, enabled: true }
				}
				return { html: '', enabled: false }
			}
			return { html: this.signatureHtml, enabled: this.signatureEnabled }
		},
		// Position settings are per identity; identities without a stored
		// entry fall back to the (legacy) global preferences.
		effectiveSignaturePosition() {
			const entry = this.identitySignatures[this.fromIdentityId]
			return entry && entry.signaturePosition ? entry.signaturePosition : this.signaturePosition
		},
		effectiveReplyPosition() {
			const entry = this.identitySignatures[this.fromIdentityId]
			return entry && entry.replyPosition ? entry.replyPosition : this.replyPosition
		},
	},
	watch: {
		to: { deep: true, handler() { this.markDirty() } },
		cc: { deep: true, handler() { this.markDirty() } },
		bcc: { deep: true, handler() { this.markDirty() } },
		subject() { this.markDirty() },
		// Switching the sender swaps the signature in the editor to the one
		// configured for the newly selected identity (per-identity entry,
		// or the legacy global signature when the identity has no entry).
		fromIdentityId(newId, oldId) {
			if (oldId !== null && newId !== oldId) this.swapSignature()
		},
		bodyHtml() { if (!this._suppressDirty) this.markDirty() },
		// originalEmail arrives asynchronously (ComposeView fetches the body
		// after mount) — build the reply/forward content, prefill recipients
		// and the subject as soon as it becomes available. The build waits
		// for the preferences (signature, positions) to be loaded.
		originalEmail: {
			immediate: true,
			handler() {
				if (this.originalEmail) {
					// Recipients need the own addresses from the identities,
					// which may arrive after originalEmail.
					if (this._identitiesLoaded) this.prefillRecipients()
					else this._pendingRecipients = true
					if (this._prefsLoaded) this.buildReplyOrForward()
					if (this.subject === '' && (this.mode === 'reply' || this.mode === 'replyAll' || this.mode === 'forward')) {
						this.subject = this.prefillSubject()
					}
				}
			},
		},
	},
	async mounted() {
		// Preferences FIRST: loadIdentities() picks the default identity
		// (star) from defaultIdentityId, so it must already be loaded.
		await this.loadPreferences()
		await this.loadIdentities()
		this._prefsLoaded = true
		if (this.mode === 'reply' || this.mode === 'replyAll' || this.mode === 'forward') {
			this.buildReplyOrForward()
		} else {
			if (this.effectiveSignature.enabled) {
				this.initContent(`<p></p>${this.signatureBlock()}`, 'empty')
			}
		}
	},
	beforeUnmount() {
		clearTimeout(draftTimer)
		// Never delete silently: the autosaved draft STAYS in the Drafts
		// folder (crash-safe, like Thunderbird). Untrack it so no cleanup
		// ever removes it behind the user's back — drafts are managed in
		// the Drafts folder.
		if (this.savedDraftId) this.trackDraft(null, this.savedDraftId)
	},
	methods: {
		prefillSubject() {
			const s = this.replyTo?.subject || this.originalEmail?.subject || this.forwardOf?.subject || ''
			if (!s) return ''
			if (this.mode === 'forward') {
				return s.match(/^Fwd:\s*/i) ? s : `Fwd: ${s}`
			}
			return s.match(/^(Re|Fwd):\s*/i) ? s : `Re: ${s}`
		},
		async loadPreferences() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'))
				this.signatureHtml = data.signatureHtml || ''
				this.signatureEnabled = !!data.signatureEnabled
				this.identitySignatures = data.identitySignatures || {}
				this.replyPosition = data.replyPosition === 'below' ? 'below' : 'above'
				this.signaturePosition = data.signaturePosition === 'below' ? 'below' : 'above'
				this.defaultIdentityId = data.defaultIdentityId || ''
			} catch (e) {
				console.error('Failed to load preferences', e)
			}
		},
		sanitizedSignature(html) {
			if (!html) return ''
			return DOMPurify.sanitize(html, { USE_PROFILES: { html: true } })
		},
		// Thunderbird-style signature block: RFC 3676 separator "--" line
		// followed by the signature. The signature HTML is wrapped in a
		// <div data-signature> so the editor renders it RAW (tables, images,
		// inline styles) via the custom SignatureNode without normalising it.
		signatureBlock() {
			const eff = this.effectiveSignature
			if (!eff.enabled) return ''
			const sig = this.sanitizedSignature(eff.html)
			if (!sig) return ''
			return `<p>--</p><div data-signature="">${sig}</div>`
		},
		// Serialize the editor body for sending: getHTML emits an empty
		// <div data-signature=""></div> marker for each signature node —
		// replace it with the sanitized raw HTML (or strip when disabled).
		serializeBody() {
			const eff = this.effectiveSignature
			const raw = eff.enabled ? this.sanitizedSignature(eff.html) : ''
			let html = this.bodyHtml
			// Tolerant marker match: attribute may or may not carry =""
			const markerRe = /<div data-signature(?:="")?><\/div>/
			if (raw) {
				html = html.replace(markerRe, `<div data-signature="">${raw}</div>`)
			} else {
				html = html.replace(markerRe, '')
				// Also drop a leftover standalone "--" separator line
				html = html.replace(/<p>\s*--\s*<\/p>/, '')
			}
			return html
		},
		// Rebuild the signature node in the editor when the sender identity
		// changes. The body is serialized (marker node only), so the swap is
		// a marker replacement — the same format serializeBody() uses.
		// A missing marker (e.g. deleted signature) gets the new block
		// appended at the end; never touch a typed "--" line when no marker
		// was found.
		swapSignature() {
			if (!this.$refs.editor) return
			const eff = this.effectiveSignature
			const raw = eff.enabled ? this.sanitizedSignature(eff.html) : ''
			let html = this.bodyHtml || ''
			const markerRe = /<div data-signature(?:="")?><\/div>/
			const hasMarker = markerRe.test(html)

			if (raw) {
				if (hasMarker) {
					// Move the tiny marker to the position required by the
					// per-identity layout settings, then fill its content.
					html = this.moveMarker(html, markerRe)
				} else {
					// No signature node yet — append the block at the end.
					html = (html.trim() === '' ? '' : html + '<p>--</p>') + `<div data-signature="">${raw}</div>`
				}
				html = html.replace(markerRe, `<div data-signature="">${raw}</div>`)
			} else if (hasMarker) {
				html = html.replace(markerRe, '')
				// The "--" separator belongs to the signature block —
				// only remove it together with a found marker.
				html = html.replace(/<p>\s*--\s*<\/p>/, '')
			}
			if (html !== this.bodyHtml) this.$refs.editor?.setContent(html)
		},
		// Move the signature block (marker + its "--" separator) relative to
		// the quoted text so the per-identity position settings apply after
		// a sender switch. Only makes sense for replies with a <blockquote>.
		moveMarker(html, markerRe) {
			if (this.mode !== 'reply' && this.mode !== 'replyAll') return html
			const marker = html.match(markerRe)[0]
			const markerIdx = html.indexOf(marker)
			if (markerIdx === -1) return html

			// The "--" separator (if present immediately before the marker)
			// travels with the signature block.
			const prefix = html.slice(0, markerIdx)
			const sepMatch = prefix.match(/<p>\s*--\s*<\/p>\s*$/)
			const unit = (sepMatch ? sepMatch[0] : '') + marker
			const unitStart = markerIdx - (sepMatch ? sepMatch[0].length : 0)
			const rest = html.slice(0, unitStart) + html.slice(unitStart + unit.length)

			const quoteIdx = rest.indexOf('<blockquote')
			if (quoteIdx === -1) return html

			if (this.effectiveReplyPosition === 'below') {
				// Quote on top → signature always at the end.
				return rest + unit
			}
			if (this.effectiveSignaturePosition === 'below') {
				// Signature below the quote — after the MATCHING closing
				// tag (nested blockquotes are counted).
				const end = this.findBlockquoteEnd(rest, quoteIdx)
				if (end === -1) return html
				return rest.slice(0, end) + unit + rest.slice(end)
			}
			// Signature above the quote.
			return rest.slice(0, quoteIdx) + unit + rest.slice(quoteIdx)
		},
		// Index after the closing tag that matches the blockquote opening
		// at startIdx (depth-aware for nested quotes).
		findBlockquoteEnd(html, startIdx) {
			const re = /<blockquote[^>]*>|<\/blockquote>/g
			re.lastIndex = startIdx
			let depth = 0
			let m
			while ((m = re.exec(html)) !== null) {
				if (m[0].startsWith('</')) {
					depth--
					if (depth === 0) return m.index + m[0].length
				} else {
					depth++
				}
			}
			return -1
		},
		// Replaces the editor content only while it is still untouched
		// (re-checked at execution time), and suppresses the dirty flag for
		// this programmatic initialisation. The flag is only set inside the
		// timer, so user input before it is never hidden from dirty tracking.
		initContent(html, cursor) {
			this.$nextTick(() => {
				setTimeout(() => {
					if (this.bodyHtml !== '') return
					this._suppressDirty = true
					try {
						this.$refs.editor?.setContent(html)
						if (cursor === 'end') this.$refs.editor?.setCursorAtEnd()
						else if (cursor === 'start') this.$refs.editor?.setCursorAtStart()
						else if (cursor === 'empty') this.$refs.editor?.setCursorInLastEmptyParagraph()
						this.$refs.editor?.focus()
					} finally {
						// Reset after the pre-flush watcher has run, so the
						// programmatic body change is not marked dirty.
						this.$nextTick(() => { this._suppressDirty = false })
					}
				}, 100)
			})
		},
		buildReplyOrForward() {
			if (this.mode === 'forward') {
				this.buildForwardContent()
			} else if (this.mode === 'reply' || this.mode === 'replyAll') {
				this.buildReplyContent()
			}
		},
		// Recipients come from the asynchronously loaded originalEmail (the
		// router path never passes reply data in the query). Own addresses
		// are excluded from replyAll CC lists.
		prefillRecipients() {
			if (!this.originalEmail) return
			if (this.mode === 'replyAll') {
				if (this.to.length === 0 && this.originalEmail.fromAddress) {
					this.to = [{ name: this.originalEmail.fromName || '', email: this.originalEmail.fromAddress }]
				}
				if (this.cc.length === 0) {
					const own = new Set((this.identities || []).map(i => (i.email || '').toLowerCase()))
					const skip = new Set([this.originalEmail.fromAddress, ...own].map(a => (a || '').toLowerCase()))
					const toList = this.originalEmail.toList || []
					const ccList = this.originalEmail.ccList || []
					this.cc = [...toList, ...ccList].filter(r => r.email && !skip.has(r.email.toLowerCase()))
					if (this.cc.length > 0) this.showCc = true
				}
			} else if (this.mode === 'reply' && this.to.length === 0 && this.originalEmail.fromAddress) {
				this.to = [{ name: this.originalEmail.fromName || '', email: this.originalEmail.fromAddress }]
			}
			// forward deliberately leaves To empty — the user picks new recipients
		},
		async loadIdentities() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/identities'))
				const list = (data.identities || []).map(i => ({ id: i.id, label: i.name ? `${i.name} <${i.email}>` : i.email, name: i.name, email: i.email }))
				this.identities = list
				if (list.length > 0) {
					const forced = this.initialExtIdentity ? list.find(i => i.id === this.initialExtIdentity) : null
					const pref = !forced && this.defaultIdentityId ? list.find(i => i.id === this.defaultIdentityId) : null
					this.fromIdentityId = forced ? forced.id : (pref ? pref.id : list[0].id)
				}
			} catch (e) {
				console.error('Failed to load identities', e)
			} finally {
				this._identitiesLoaded = true
				if (this._pendingRecipients) {
					this._pendingRecipients = false
					this.prefillRecipients()
				}
			}
		},
		buildReplyContent() {
			const email = this.originalEmail
			if (!email) return
			const body = email.htmlBody || email.plainBody || ''
			const { html } = sanitizeMailHtml(body, { attachments: email.attachments || [], blockRemote: false })
			const quote = buildReplyQuote(email, html)
			const answer = '<p></p>'
			// Signature is inserted DIRECTLY into the editor so the user sees
			// exactly what will be sent. The empty answer paragraph is the
			// cursor target.
			const sig = this.signatureBlock()
			let content
			if (this.effectiveReplyPosition === 'below') {
				// Quote on top, answer + signature at the end.
				content = `${quote}${answer}${sig}`
			} else if (this.effectiveSignaturePosition === 'below') {
				// Answer on top, signature at the very bottom (below quote).
				content = `${answer}${quote}${sig}`
			} else {
				// Answer, then signature, then quote.
				content = `${answer}${sig}${quote}`
			}
			this.initContent(content, 'empty')
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
			const sig = this.signatureBlock()
			this.initContent(`<p></p>${sig}${quote}`, 'empty')
		},
		markDirty() {
			this.dirty = true
			clearTimeout(draftTimer)
			draftTimer = setTimeout(() => this.saveDraft(), 3000)
		},
		async saveDraft() {
			// Guard against overlapping autosaves (slow create + fast typing)
			// which could otherwise create a second draft.
			if (this._savingDraft) return
			this._savingDraft = true
			try {
				const payload = this.buildPayload()
				if (this.savedDraftId) {
					const { data } = await axios.put(generateUrl('/apps/souvera_mail/api/v2/drafts/' + this.savedDraftId), payload)
					// Keep the id in sync (e.g. after a vanished-draft fallback create).
					if (data?.draftId) this.savedDraftId = data.draftId
				} else {
					const { data } = await axios.post(generateUrl('/apps/souvera_mail/api/v2/drafts'), payload)
					this.savedDraftId = data.draftId
					this.trackDraft(data.draftId)
				}
			} catch (e) {
				console.error('Draft save failed', e)
			} finally {
				this._savingDraft = false
			}
		},
		buildPayload() {
			// The signature lives in the editor as a marker node — serialize
			// it back to raw HTML before sending/drafting.
			const bodyHtml = this.serializeBody()
			return {
				identityId: this.fromIdentityId,
				to: this.to.map(r => r.email),
				cc: this.cc.map(r => r.email),
				bcc: this.bcc.map(r => r.email),
				subject: this.subject,
				bodyHtml,
				bodyPlain: bodyHtml.replace(/<[^>]+>/g, ''),
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
					this.trackDraft(null, this.savedDraftId)
					this.savedDraftId = null
				}
				showSuccess(this.t('souvera_mail', 'Message sent'))
				this.$emit('sent')
			} catch (e) {
				console.error('Send failed', e)
				showError(e.response?.data?.error || this.t('souvera_mail', 'Failed to send message'))
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
		onCloudFileAttached(att) {
			this.attachments.push({
				blobId: att.blobId,
				name: att.name,
				type: att.type,
				size: att.size,
				fromCloud: true,
			})
		},
		onClose() {
			// Ask BEFORE anything is dropped: keep / discard / stay.
			if (this.dirty || this.savedDraftId) {
				this.showCloseDialog = true
				return
			}
			this.$emit('cancel')
		},
		async keepDraftAndClose() {
			// Flush the latest content into the SAME draft (no new id),
			// then hand it over to the Drafts folder.
			try { await this.saveDraft() } catch {}
			this.trackDraft(null, this.savedDraftId)
			this.savedDraftId = null
			this.showCloseDialog = false
			this.$emit('cancel')
		},
		discardDraftAndClose() {
			this.deleteSavedDraft()
			this.showCloseDialog = false
			this.$emit('cancel')
		},
		onDiscard() {
			if (confirm(this.t('souvera_mail', 'Discard this message?'))) {
				this.deleteSavedDraft()
				this.$emit('cancel')
			}
		},
		deleteSavedDraft() {
			if (this.savedDraftId) {
				this.discardingDraftId = this.savedDraftId
				axios.delete(generateUrl('/apps/souvera_mail/api/v2/drafts/' + this.savedDraftId)).catch(() => {})
				this.trackDraft(null, this.savedDraftId)
				this.savedDraftId = null
			}
		},
		// Track draft ids THIS app created so abandoned sessions can be
		// cleaned up without touching drafts from other clients (IMAP).
		trackDraft(addId, removeId = null) {
			try {
				const KEY = 'souvera_mail.tracked_drafts'
				let ids = JSON.parse(localStorage.getItem(KEY) || '[]')
				ids = ids.filter(id => id !== removeId)
				if (addId) ids.push(addId)
				// Cap the list — only the most recent 20 drafts matter.
				ids = ids.slice(-20)
				localStorage.setItem(KEY, JSON.stringify(ids))
			} catch {}
		},
	},
}
</script>

<style scoped>
.compose-layout { display: flex; flex-direction: column; height: 85vh; max-height: 85vh; overflow: hidden; }
.compose-close-dialog { display: flex; flex-direction: column; gap: 18px; padding: 6px 2px 0; min-width: 380px; }
.compose-close-dialog__body { display: flex; align-items: flex-start; gap: 14px; }
.compose-close-dialog__icon { color: var(--color-text-maxcontrast); flex-shrink: 0; margin-top: 2px; }
.compose-close-dialog__text { margin: 0; font-size: 14px; line-height: 1.55; padding-top: 8px; }
.compose-close-dialog__actions { display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--color-border); padding-top: 14px; }

.compose-layout__header { padding: 10px 16px; border-bottom: 1px solid var(--color-border); flex-shrink: 0; }
.compose-layout__header h3 { margin: 0; font-size: 15px; font-weight: 600; }
.compose-modal--fullscreen :deep(.modal-container) { max-width: calc(100vw - 32px) !important; max-height: calc(100vh - 32px) !important; width: calc(100vw - 32px) !important; }
/* Generous compose window — wide enough for comfortable writing. */
.compose-modal :deep(.modal-container) { max-width: 920px !important; width: calc(100vw - 32px) !important; }

/* Compact single-line rows for From/To/Cc/Bcc/Subject — Thunderbird style */
.compose-row {
	display: flex; align-items: center; gap: 8px;
	padding: 4px 16px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
	min-height: 36px;
}
.compose-row__label {
	flex-shrink: 0;
	width: 48px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}
.compose-row__select-wrap { position: relative; flex: 1; max-width: 420px; }
.compose-row__select-icon { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--color-text-maxcontrast); }
.compose-row__static-text { padding: 5px 10px; font-size: 13px; color: var(--color-main-text); }
.compose-row__input-wrap { flex: 1; min-width: 0; }
.compose-row__toggles { display: flex; gap: 4px; flex-shrink: 0; }

.compose-row :deep(.native-select) {
	width: 100%;
	border: none;
	background: transparent;
	font-size: 13px;
	padding: 5px 24px 5px 10px;
	appearance: none;
	cursor: pointer;
	color: var(--color-main-text);
	border-radius: var(--border-radius);
}
.compose-row :deep(.native-select:hover) { background: var(--color-background-hover); }
.compose-row :deep(.native-select:focus) { outline: 2px solid var(--color-primary-element); outline-offset: -2px; }

.compose-row :deep(.recipient-field__input) {
	border: none !important;
	background: transparent !important;
	width: 100% !important;
	min-width: 60px !important;
	min-height: 0 !important;
	padding: 5px 0 !important;
	font-size: 13px !important;
	flex: 1 !important;
}

.compose-row :deep(.input-field) {
	width: 100% !important;
	--input-border-color: transparent;
	margin: 0 !important;
}
.compose-row :deep(.input-field__input) {
	padding: 5px 0 !important;
	font-size: 13px !important;
	height: auto !important;
}

.compose-field {
	padding: 10px 20px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

/* #1: From field — max-width */
.compose-field--from :deep(.vs__dropdown-toggle) { max-width: 400px; }
.compose-field--from :deep(.vs__search),
.compose-field--from :deep(.vs__selected-options input) {
	width: 0 !important; flex-basis: 0 !important; padding: 0 !important; margin: 0 !important;
	border: 0 !important; min-width: 0 !important;
}

/* Shared form element style — only for elements without Nextcloud's own design */
.compose-field :deep(.vs__dropdown-toggle),
.compose-field :deep(.v-select .vs__dropdown-toggle),
.compose-field :deep(.native-select) {
	border: 1px solid var(--color-border) !important;
	border-radius: var(--border-radius-large) !important;
	background: var(--color-main-background);
	min-height: 40px;
	padding: 6px 12px;
	width: 100% !important;
	box-sizing: border-box !important;
	font-size: 14px;
}

/* NcTextField (Subject) — already has Nextcloud styling, just ensure full width */
.compose-field :deep(.input-field) {
	width: 100% !important;
	--input-border-color: var(--color-border);
}

.compose-field :deep(.recipient-field__input) {
	border: none !important;
	background: transparent !important;
	width: auto !important;
	min-width: 60px !important;
	min-height: 0 !important;
	padding: 4px 0 !important;
	flex: 1 !important;
}

/* #5: Editor fills full modal size */
.compose-field--body {
	padding: 0; margin: 0;
	border: none; border-bottom: none;
	flex: 1 1 auto;
	min-height: 250px;
	overflow: hidden;
	display: flex; flex-direction: column;
	min-width: 0;
}
.compose-field--body :deep(.richtext-editor) {
	flex: 1 1 auto;
	height: auto;
	min-height: 0 !important;
	border: none !important;
}
.compose-field--body :deep(.richtext-editor__content) {
	border: none !important;
	min-height: 0 !important;
}
.compose-field--body :deep(.ProseMirror) {
	border: none !important;
	min-height: 0 !important;
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

.compose-field__select-wrap {
	position: relative;
}
.compose-field__select-icon {
	position: absolute;
	right: 12px;
	top: 50%;
	transform: translateY(-50%);
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}
.native-select {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-height: 40px;
	padding: 6px 36px 6px 12px;
	width: 100%;
	box-sizing: border-box;
	font-size: 14px;
	font: inherit;
	appearance: none;
	-webkit-appearance: none;
	-moz-appearance: none;
}
.compose-field__static-text {
	padding: 6px 36px 6px 0;
	font-size: 14px;
	color: var(--color-main-text);
}

/* #3: Cc/Bcc pill buttons */
.compose-toggle-row { display: flex; gap: 6px; margin-top: 8px; }
.toggle-btn {
	padding: 3px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	font: inherit; font-size: 12px;
	cursor: pointer;
}
.toggle-btn:hover { background: var(--color-background-hover); }
.toggle-btn--active {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}

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
