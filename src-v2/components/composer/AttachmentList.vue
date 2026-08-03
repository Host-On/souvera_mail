<template>
	<div class="mail-attachment-list">
		<div v-for="(att, idx) in attachments" :key="idx" class="mail-attachment-list__item">
			<Paperclip :size="16" />
			<Cloud v-if="att.fromCloud" :size="14" />
			<span class="mail-attachment-list__name">{{ att.name }}</span>
			<span class="mail-attachment-list__size">{{ formatSize(att.size || Math.round((att.data?.length || 0) * 0.75)) }}</span>
			<NcButton variant="tertiary" size="small"
				:aria-label="t('souvera_mail', 'Remove attachment')"
				@click="$emit('remove', idx)">
				<template #icon><Close :size="14" /></template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Cloud from 'vue-material-design-icons/Cloud.vue'

export default {
	name: 'AttachmentList',
	components: { NcButton, Paperclip, Close, Cloud },
	props: { attachments: { type: Array, default: () => [] } },
	emits: ['remove'],
	methods: {
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const u = ['B', 'KB', 'MB', 'GB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s * 10) / 10 + ' ' + u[i]
		},
	},
}
</script>

<style scoped>
.mail-attachment-list { display: flex; flex-direction: column; gap: 4px; }
.mail-attachment-list__item {
	display: flex; align-items: center; gap: 8px;
	padding: 4px 8px; background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
}
.mail-attachment-list__name { font-weight: 500; }
.mail-attachment-list__size { font-size: 12px; color: var(--color-text-maxcontrast); margin-left: auto; }
</style>
