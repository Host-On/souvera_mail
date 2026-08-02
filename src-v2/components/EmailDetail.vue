<template>
	<div class="email-detail" v-if="email">
		<div class="email-detail__toolbar">
			<NcButton type="tertiary" @click="$emit('close')">
				<template #icon><ArrowLeft :size="20" /></template>
				{{ t('souvera_mail', 'Back') }}
			</NcButton>
			<div class="email-detail__actions">
				<NcButton type="tertiary" @click="$emit('reply')">
					<template #icon><Reply :size="20" /></template>
				</NcButton>
				<NcButton type="tertiary" @click="$emit('forward')">
					<template #icon><Forward :size="20" /></template>
				</NcButton>
				<NcButton type="tertiary" @click="$emit('flag')">
					<template #icon><Star :size="20" :fill="email.isFlagged ? 'var(--color-warning)' : 'none'" /></template>
				</NcButton>
				<NcButton type="tertiary" @click="$emit('delete')">
					<template #icon><TrashCan :size="20" /></template>
				</NcButton>
			</div>
		</div>
			<div class="email-detail__meta">
				<div class="email-detail__sender">
					<strong>{{ email.fromName || email.fromAddress }}</strong>
					<span class="email-detail__address">&lt;{{ email.fromAddress }}&gt;</span>
		</div>

		<div class="email-detail__header">
			<h2 class="email-detail__subject">{{ email.subject || t('souvera_mail', '(no subject)') }}</h2>
				<div class="email-detail__date">{{ formatDate(email.receivedAt) }}</div>
			</div>
			<div class="email-detail__to" v-if="email.toAddresses">
				{{ t('souvera_mail', 'To:') }} {{ email.toAddresses }}
			</div>
		</div>

		<div v-if="email.attachments && email.attachments.length > 0" class="email-detail__attachments">
			<h4>{{ t('souvera_mail', 'Attachments') }}</h4>
			<div class="attachment-list">
				<NcButton v-for="att in email.attachments" :key="att.blobId"
					type="tertiary" @click="$emit('openAttachment', att)">
					<template #icon><Paperclip :size="16" /></template>
					{{ att.name }} ({{ formatSize(att.size) }})
				</NcButton>
			</div>
		</div>

		<div class="email-detail__body">
			<div v-if="htmlBody" class="email-body-html" v-html="sanitizedHtml" />
			<div v-else class="email-body-text">{{ plainBody }}</div>
		</div>

		<NcEmptyContent v-if="!bodyLoaded && loading"
			:title="t('souvera_mail', 'Loading message...')" />
	</div>
	<NcEmptyContent v-else-if="!loading"
		:title="t('souvera_mail', 'Select a message')">
		<template #icon><EmailOutline :size="64" /></template>
	</NcEmptyContent>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Forward from 'vue-material-design-icons/Forward.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'

function sanitizeHtml(html) {
	if (!html) return ''
	return html
		.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '')
		.replace(/on\w+="[^"]*"/gi, '')
		.replace(/<iframe[^>]*>[\s\S]*?<\/iframe>/gi, '')
}

export default {
	name: 'EmailDetail',
	components: { NcButton, NcEmptyContent, ArrowLeft, Reply, Forward, TrashCan, Star, Paperclip, EmailOutline },
	props: {
		email: { type: Object, default: null },
		htmlBody: { type: String, default: '' },
		plainBody: { type: String, default: '' },
		loading: { type: Boolean, default: false },
	},
	emits: ['reply', 'forward', 'delete', 'flag', 'openAttachment'],
	computed: {
		bodyLoaded() { return this.htmlBody || this.plainBody },
		sanitizedHtml() { return sanitizeHtml(this.htmlBody) },
	},
	methods: {
		formatDate(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleString()
		},
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const units = ['B', 'KB', 'MB']
			let i = 0
			let size = bytes
			while (size >= 1024 && i < units.length - 1) { size /= 1024; i++ }
			return Math.round(size) + ' ' + units[i]
		},
	},
}
</script>

<style scoped>
.email-detail { padding: 20px; max-width: 860px; margin: 0 auto; }
.email-detail__toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.email-detail__actions { display: flex; gap: 4px; }
.email-detail__header { margin-bottom: 12px; }
.email-detail__subject { margin: 0 0 8px; font-size: 20px; }
.email-detail__meta { display: flex; justify-content: space-between; color: var(--color-text-maxcontrast); font-size: 13px; }
.email-detail__address { color: var(--color-text-maxcontrast); margin-left: 4px; }
.email-detail__to { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 4px; }
.email-detail__attachments { margin-bottom: 16px; }
.attachment-list { display: flex; flex-wrap: wrap; gap: 8px; }
.email-detail__body { line-height: 1.6; word-break: break-word; }
.email-body-html :deep(img) { max-width: 100%; height: auto; }
.email-body-text { white-space: pre-wrap; }
</style>
