<template>
	<div class="email-detail" v-if="email">
		<div class="email-detail__toolbar">
			<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Back')" @click="$emit('close')">
				<template #icon><ArrowLeft :size="20" /></template>
			</NcButton>
			<div class="email-detail__actions">
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Reply')" @click="$emit('reply')">
					<template #icon><Reply :size="20" /></template>
				</NcButton>
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Reply all')" @click="$emit('replyAll')">
					<template #icon><ReplyAll :size="20" /></template>
				</NcButton>
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Forward')" @click="$emit('forward')">
					<template #icon><Forward :size="20" /></template>
				</NcButton>
				<NcActions :aria-label="t('souvera_mail', 'More actions')">
					<template #icon><FolderMove :size="20" /></template>
					<NcActionButton v-for="mb in moveMailboxes" :key="mb.id"
						:name="mb.name"
						@click="moveTo(mb.id)">
						<template #icon><Folder :size="20" /></template>
					</NcActionButton>
				</NcActions>
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Delete')" @click="$emit('delete')">
					<template #icon><TrashCan :size="20" /></template>
				</NcButton>
			</div>
		</div>

		<div class="email-detail__header">
			<h2>{{ email.subject || t('souvera_mail', '(no subject)') }}</h2>
			<div class="email-detail__from">
				<BimiLogo :email="email.fromAddress" />
				<strong>{{ email.fromName || email.fromAddress }}</strong>
				<span class="email-detail__addr">&lt;{{ email.fromAddress }}&gt;</span>
			</div>
			<div class="email-detail__meta">
				<span v-if="email.toAddresses">{{ t('souvera_mail', 'To:') }} {{ email.toAddresses }}</span>
				<span>{{ formatDateTime(email.receivedAt) }}</span>
			</div>
		</div>

		<div v-if="email.attachments && email.attachments.length > 0" class="email-detail__attachments">
			<h4>{{ t('souvera_mail', 'Attachments') }} ({{ email.attachments.length }})</h4>
			<div class="attachment-chips">
				<a v-for="att in email.attachments" :key="att.blobId"
					:href="buildBlobUrl(att.blobId, att.name)"
					class="attachment-link" download>
					<NcButton variant="tertiary">
						<template #icon><Paperclip :size="16" /></template>
						{{ att.name }} ({{ formatSize(att.size) }})
					</NcButton>
				</a>
			</div>
		</div>

		<div class="email-detail__body">
			<HtmlMailFrame v-if="htmlBody"
				:html="htmlBody"
				:attachments="email.attachments || []"
				@mailto="$emit('mailto', $event)" />
			<div v-else-if="plainBody" class="email-body-text">{{ plainBody }}</div>
			<div v-else-if="loading" class="email-detail__loading">
				<span class="icon-loading" />
			</div>
			<p v-else class="email-detail__empty">
				{{ t('souvera_mail', 'This message has no content or could not be loaded.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton, NcActions, NcActionButton } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import ReplyAll from 'vue-material-design-icons/ReplyAll.vue'
import Forward from 'vue-material-design-icons/Forward.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import HtmlMailFrame from './HtmlMailFrame.vue'
import BimiLogo from './BimiLogo.vue'
import { buildBlobUrl } from '../utils/mailSanitizer.js'

export default {
	name: 'EmailDetail',
	components: { NcButton, NcActions, NcActionButton, ArrowLeft, Reply, ReplyAll, Forward, TrashCan, Paperclip, FolderMove, Folder, HtmlMailFrame, BimiLogo },
	props: {
		email: { type: Object, default: null },
		htmlBody: { type: String, default: '' },
		plainBody: { type: String, default: '' },
		loading: { type: Boolean, default: false },
		mailboxes: { type: Array, default: () => [] },
	},
	emits: ['close', 'reply', 'replyAll', 'forward', 'delete', 'move', 'mailto'],
	data() { return {} },
	computed: {
		moveMailboxes() {
			return this.mailboxes.filter(m => m.role !== 'trash' && m.role !== 'junk')
		},
	},
	methods: {
		buildBlobUrl,
		formatDateTime(iso) { try { return new Date(iso).toLocaleString() } catch { return iso } },
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const u = ['B', 'KB', 'MB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s) + ' ' + u[i]
		},
		moveTo(mailboxId) {
			this.$emit('move', mailboxId)
		},
	},
}
</script>

<style scoped>
.email-detail { padding: 20px; }
.email-detail__toolbar {
	display: flex; justify-content: space-between; align-items: center;
	padding: 8px 12px; margin: -20px -20px 16px -20px;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}
.email-detail__actions { display: flex; gap: 4px; }
.email-detail__header { margin-bottom: 20px; }
.email-detail__header h2 { margin: 0 0 8px; font-size: 20px; font-weight: 600; }
.email-detail__from { margin-bottom: 4px; }
.email-detail__addr { color: var(--color-text-maxcontrast); margin-left: 6px; font-weight: 400; }
.email-detail__meta { display: flex; justify-content: space-between; font-size: 12px; color: var(--color-text-maxcontrast); }
.email-detail__attachments { margin-bottom: 20px; }
.email-detail__attachments h4 { margin: 0 0 8px; font-size: 13px; color: var(--color-text-maxcontrast); }
.attachment-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.email-detail__body { line-height: 1.7; word-break: break-word; }
.email-body-text { white-space: pre-wrap; }
.email-detail__loading { display: flex; justify-content: center; padding: 48px; }
.email-detail__empty { color: var(--color-text-maxcontrast); text-align: center; padding: 48px; }
</style>
