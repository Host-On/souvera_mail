<template>
	<div class="email-list-item" :class="{ 'email-list-item--unread': !email.isRead, 'email-list-item--active': active }" @click="$emit('click')">
		<div class="email-list-item__main">
			<span class="email-list-item__sender">{{ email.fromName || email.fromAddress }}</span>
			<span class="email-list-item__date">{{ formatDate(email.receivedAt) }}</span>
		</div>
		<div class="email-list-item__subject">
			{{ email.subject || t('souvera_mail', '(no subject)') }}
			<span v-if="email.hasAttachment" class="email-list-item__attach">📎</span>
		</div>
		<div v-if="email.preview" class="email-list-item__preview">{{ email.preview }}</div>
	</div>
</template>

<script>
export default {
	name: 'EmailListItem',
	props: {
		email: { type: Object, required: true },
		active: { type: Boolean, default: false },
	},
	emits: ['click'],
	methods: {
		formatDate(iso) {
			if (!iso) return ''
			const d = new Date(iso)
			const now = new Date()
			if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
			return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
		},
	},
}
</script>

<style scoped>
.email-list-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid var(--color-border); transition: background 0.15s; }
.email-list-item:hover { background: var(--color-background-hover); }
.email-list-item--unread { font-weight: 600; }
.email-list-item--active { background: var(--color-primary-element-light); box-shadow: inset 3px 0 0 var(--color-primary-element); }
.email-list-item__main { display: flex; justify-content: space-between; align-items: baseline; }
.email-list-item__sender { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.email-list-item__date { font-size: 11px; color: var(--color-text-maxcontrast); flex-shrink: 0; margin-left: 8px; }
.email-list-item__subject { font-size: 13px; margin-top: 2px; }
.email-list-item__attach { margin-left: 4px; }
.email-list-item__preview { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
