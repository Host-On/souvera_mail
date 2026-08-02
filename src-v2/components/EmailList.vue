<template>
	<div class="email-list">
		<div class="email-list__head">
			<span class="email-list__count">{{ offset + 1 }}-{{ Math.min(offset + emails.length, total) }} / {{ total }}</span>
			<div class="email-list__nav">
				<NcButton type="tertiary" :disabled="offset <= 0" @click="$emit('prev')">
					<template #icon><ChevronLeft :size="20" /></template>
				</NcButton>
				<NcButton type="tertiary" :disabled="offset + limit >= total" @click="$emit('next')">
					<template #icon><ChevronRight :size="20" /></template>
				</NcButton>
			</div>
		</div>

		<ul class="email-list__items">
			<li v-for="email in emails" :key="email.id" class="email-row" :class="{ 'email-row--unread': !email.isRead }" @click="$emit('open', email)">
				<div class="email-row__sender">
					<span class="email-row__name">{{ email.fromName || email.fromAddress }}</span>
					<span v-if="!email.isRead" class="email-row__dot" />
				</div>
				<div class="email-row__subject">{{ email.subject || t('souvera_mail', '(no subject)') }}</div>
				<div class="email-row__meta">
					<span class="email-row__date">{{ formatDate(email.receivedAt) }}</span>
					<span v-if="email.hasAttachment" class="email-row__attach">📎</span>
				</div>
				<div v-if="email.preview" class="email-row__preview">{{ email.preview }}</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

export default {
	name: 'EmailList',
	components: { NcButton, ChevronLeft, ChevronRight },
	props: {
		emails: { type: Array, default: () => [] },
		total: { type: Number, default: 0 },
		offset: { type: Number, default: 0 },
		limit: { type: Number, default: 50 },
	},
	emits: ['prev', 'next', 'open'],
	methods: {
		formatDate(iso) {
			if (!iso) return ''
			try {
				const d = new Date(iso)
				const now = new Date()
				if (d.toDateString() === now.toDateString()) {
					return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
				}
				return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
			} catch { return iso }
		},
	},
}
</script>

<style scoped>
.email-list__head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.email-list__count { font-size: 12px; color: var(--color-text-maxcontrast); }
.email-list__nav { display: flex; gap: 4px; }
.email-list__items { list-style: none; margin: 0; padding: 0; }
.email-row { padding: 10px 12px; border-bottom: 1px solid var(--color-border); cursor: pointer; }
.email-row:hover { background: var(--color-background-hover); }
.email-row--unread { font-weight: 600; }
.email-row__sender { display: flex; align-items: center; gap: 6px; }
.email-row__dot { width: 8px; height: 8px; border-radius: 50%; background: var(--color-primary); }
.email-row__subject { margin: 2px 0; font-size: 13px; }
.email-row__meta { display: flex; justify-content: space-between; font-size: 11px; color: var(--color-text-maxcontrast); }
.email-row__preview { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
