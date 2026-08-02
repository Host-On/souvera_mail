<template>
	<div class="email-list-toolbar">
		<div class="email-list-toolbar__left">
			<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Refresh')" @click="$emit('refresh')">
				<template #icon><Refresh :size="20" /></template>
			</NcButton>
			<template v-if="selectedCount > 0">
				<span class="selected-count">{{ selectedCount }} {{ t('souvera_mail', 'selected') }}</span>
				<NcButton variant="tertiary" @click="$emit('markRead')">
					<template #icon><EmailOpen :size="20" /></template>
				</NcButton>
				<NcButton variant="tertiary" @click="$emit('bulkDelete')">
					<template #icon><TrashCan :size="20" /></template>
				</NcButton>
			</template>
		</div>
		<NcButton variant="primary" @click="$emit('compose')">
			<template #icon><Pencil :size="20" /></template>
			{{ t('souvera_mail', 'New') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import EmailOpen from 'vue-material-design-icons/EmailOpen.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
export default {
	name: 'EmailListToolbar',
	components: { NcButton, Refresh, Pencil, EmailOpen, TrashCan },
	props: { selectedCount: { type: Number, default: 0 } },
	emits: ['refresh', 'compose', 'markRead', 'bulkDelete'],
}
</script>

<style scoped>
.email-list-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
.email-list-toolbar__left { display: flex; align-items: center; gap: 4px; }
.selected-count { font-size: 13px; color: var(--color-primary-element); font-weight: 500; }
</style>
