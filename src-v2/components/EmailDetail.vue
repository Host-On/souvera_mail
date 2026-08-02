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
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Forward')" @click="$emit('forward')">
					<template #icon><Forward :size="20" /></template>
				</NcButton>
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Delete')" @click="$emit('delete')">
					<template #icon><TrashCan :size="20" /></template>
				</NcButton>
			</div>
		</div>

		<div class="email-detail__header">
			<h2>{{ email.subject || t('souvera_mail', '(no subject)') }}</h2>
			<div class="email-detail__from">
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
					:href="blobUrl(att.blobId, att.name)"
					class="attachment-link" download>
					<NcButton variant="tertiary">
						<template #icon><Paperclip :size="16" /></template>
						{{ att.name }} ({{ formatSize(att.size) }})
					</NcButton>
				</a>
			</div>
		</div>

		<div class="email-detail__body" v-if="htmlBody || plainBody">
			<div v-if="htmlBody" class="email-body-html" v-html="sanitizedHtml" />
			<div v-else class="email-body-text">{{ plainBody }}</div>
		</div>

		<div v-if="loading" class="email-detail__loading">
			<span class="icon-loading" />
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Forward from 'vue-material-design-icons/Forward.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'

function sanitizeHtml(html) {
	if (!html) return ''
	return html.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '').replace(/on\w+="[^"]*"/gi, '').replace(/<iframe[^>]*>[\s\S]*?<\/iframe>/gi, '')
}

export default {
	name: 'EmailDetail',
	components: { NcButton, ArrowLeft, Reply, Forward, TrashCan, Paperclip },
	props: {
		email: { type: Object, default: null },
		htmlBody: { type: String, default: '' },
		plainBody: { type: String, default: '' },
		loading: { type: Boolean, default: false },
	},
	emits: ['close', 'reply', 'forward', 'delete'],
	computed: { sanitizedHtml() { return sanitizeHtml(this.htmlBody) } },
	methods: {
		formatDateTime(iso) { try { return new Date(iso).toLocaleString() } catch { return iso } },
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const u = ['B', 'KB', 'MB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s) + ' ' + u[i]
		},
		blobUrl(blobId, name) {
			return OC.generateUrl('/apps/souvera_mail/api/v2/blobs/' + blobId + '/' + name)
		},
	},
}
</script>

<style scoped>
.email-detail { padding: 20px; }
.email-detail__toolbar { display: flex; justify-content: space-between; margin-bottom: 16px; }
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
.email-body-html :deep(img) { max-width: 100%; height: auto; }
.email-body-text { white-space: pre-wrap; }
.email-detail__loading { display: flex; justify-content: center; padding: 48px; }
</style>
