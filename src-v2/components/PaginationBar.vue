<template>
	<div class="pagination-bar">
		<NcButton variant="tertiary" :disabled="offset <= 0" @click="$emit('prev')">
			<template #icon><ChevronLeft :size="18" /></template>
			{{ t('souvera_mail', 'Newer') }}
		</NcButton>
		<span class="pagination-bar__info">{{ offset + 1 }}–{{ Math.min(offset + limit, total) }} / {{ total }}</span>
		<NcButton variant="tertiary" :disabled="offset + limit >= total" @click="$emit('next')">
			{{ t('souvera_mail', 'Older') }}
			<template #icon><ChevronRight :size="18" /></template>
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
export default {
	name: 'PaginationBar',
	components: { NcButton, ChevronLeft, ChevronRight },
	props: {
		offset: { type: Number, default: 0 },
		limit: { type: Number, default: 50 },
		total: { type: Number, default: 0 },
	},
	emits: ['prev', 'next'],
}
</script>

<style scoped>
.pagination-bar { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 10px 12px; border-top: 1px solid var(--color-border); }
.pagination-bar__info { font-size: 12px; color: var(--color-text-maxcontrast); }
</style>
