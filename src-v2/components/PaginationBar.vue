<template>
	<div class="pagination-bar">
		<button class="pagination-btn" :disabled="offset <= 0" @click="onPrev">
			<ChevronLeft :size="18" />
			{{ t('souvera_mail', 'Newer') }}
		</button>
		<span class="pagination-bar__info">{{ offset + 1 }}–{{ Math.min(offset + limit, total) }} / {{ total }}</span>
		<button class="pagination-btn" :disabled="offset + limit >= total" @click="onNext">
			{{ t('souvera_mail', 'Older') }}
			<ChevronRight :size="18" />
		</button>
	</div>
</template>

<script>
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

export default {
	name: 'PaginationBar',
	components: { ChevronLeft, ChevronRight },
	props: {
		offset: { type: Number, default: 0 },
		limit: { type: Number, default: 50 },
		total: { type: Number, default: 0 },
	},
	emits: ['prev', 'next'],
	methods: {
		onPrev() { this.$emit('prev') },
		onNext() { this.$emit('next') },
	},
}
</script>

<style scoped>
.pagination-bar {
	display: flex; justify-content: space-between; align-items: center;
	gap: 8px; padding: 10px 16px;
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
}
.pagination-btn {
	display: inline-flex; align-items: center; gap: 4px;
	padding: 6px 12px;
	border: none; border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-main-text);
	font: inherit; font-size: 13px;
	cursor: pointer;
}
.pagination-btn:hover:not(:disabled) { background: var(--color-background-hover); }
.pagination-btn:disabled { color: var(--color-text-maxcontrast); cursor: default; }
.pagination-bar__info { font-size: 13px; color: var(--color-text-maxcontrast); white-space: nowrap; }
</style>
