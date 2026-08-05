<template>
	<div class="spam-detail-overlay" @click.self="$emit('close')">
		<div class="spam-detail">
			<div class="spam-detail__header">
				<div class="spam-detail__header-top">
					<h3>{{ item.subject || t('souvera_mail', '(No subject)') }}</h3>
					<NcButton variant="tertiary" @click="$emit('close')">
						<template #icon><Close :size="20" /></template>
					</NcButton>
				</div>
				<div class="spam-detail__meta">
					<span class="spam-detail__from">{{ item.fromName || item.fromAddress }}</span>
					<span v-if="item._source === 'shield' && item.spamLevel" class="spam-badge" :class="'spam-badge--' + levelClass">
						{{ item.spamLevel }}
					</span>
					<span class="spam-detail__source">{{ item._source === 'shield' ? 'Shield' : 'Junk' }}</span>
					<span class="spam-detail__date">{{ item.receivedAt }}</span>
				</div>
			</div>

			<div class="spam-detail__body" v-if="loading">
				{{ t('souvera_mail', 'Loading…') }}
			</div>
			<div class="spam-detail__body" v-else-if="bodyHtml">
				<iframe sandbox="allow-same-origin" class="spam-body-iframe" :srcdoc="bodyHtml" />
			</div>
			<div class="spam-detail__body" v-else-if="bodyText">
				<pre class="spam-body-text">{{ bodyText }}</pre>
			</div>
			<div class="spam-detail__body" v-else>
				<NcEmptyContent :name="t('souvera_mail', 'Preview not available')" />
			</div>

			<div class="spam-detail__actions">
				<NcButton variant="primary" @click="$emit('release')">
					<template #icon><EmailOutline :size="18" /></template>
					{{ t('souvera_mail', 'Release to inbox') }}
				</NcButton>
				<NcButton variant="error" @click="$emit('delete')">
					<template #icon><TrashCan :size="18" /></template>
					{{ t('souvera_mail', 'Delete') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'

export default {
	name: 'SpamDetail',
	components: { NcButton, NcEmptyContent, Close, EmailOutline, TrashCan },
	props: {
		item: { type: Object, required: true },
		body: { type: [Object, String], default: null },
		loading: { type: Boolean, default: false },
	},
	emits: ['close', 'release', 'delete'],
	computed: {
		bodyHtml() {
			const b = this.body
			if (!b) return ''
			if (typeof b === 'string') return b
			return b.html || b.htmlBody || b.raw || ''
		},
		bodyText() {
			const b = this.body
			if (!b) return ''
			if (typeof b === 'string') return b
			return b.text || b.textBody || b.raw || ''
		},
		levelClass() {
			const l = this.item.spamLevel || 0
			if (l < 3) return 'low'
			if (l < 5) return 'medium'
			return 'high'
		},
	},
}
</script>

<style scoped>
.spam-detail-overlay {
	position: fixed; inset: 0; z-index: 100;
	background: rgba(0,0,0,0.3);
	display: flex; justify-content: center; align-items: center;
}
.spam-detail {
	background: var(--color-main-background);
	border-radius: 8px;
	width: min(640px, 90vw);
	max-height: 80vh;
	display: flex; flex-direction: column;
	overflow: hidden;
}
.spam-detail__header { padding: 12px 16px; border-bottom: 1px solid var(--color-border); }
.spam-detail__header-top { display: flex; justify-content: space-between; align-items: flex-start; }
.spam-detail__header-top h3 { margin: 0; font-size: 15px; }
.spam-detail__meta { display: flex; gap: 8px; align-items: center; margin-top: 4px; font-size: 12px; color: var(--color-text-maxcontrast); }
.spam-detail__from { font-weight: 600; color: var(--color-main-text); }
.spam-detail__date { margin-left: auto; }
.spam-detail__source { font-size: 10px; padding: 0 4px; border-radius: 3px; background: var(--color-primary-element); color: #fff; }
.spam-detail__body { flex: 1; overflow-y: auto; padding: 12px 16px; }
.spam-body-iframe { width: 100%; min-height: 300px; border: none; }
.spam-body-text { white-space: pre-wrap; word-break: break-all; font-size: 13px; }
.spam-detail__actions { display: flex; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--color-border); justify-content: flex-end; }
.spam-badge {
	font-size: 10px; font-weight: 700; padding: 0 4px; border-radius: 3px; color: #fff; line-height: 16px;
}
.spam-badge--low { background: #4caf50; }
.spam-badge--medium { background: #ff9800; }
.spam-badge--high { background: #f44336; }
</style>
