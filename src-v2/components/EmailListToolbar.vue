<template>
	<div class="email-list-toolbar">
		<NcButton variant="tertiary" @click="$emit('refresh')">
			<template #icon><Refresh :size="20" /></template>
		</NcButton>
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
export default {
	name: 'EmailListToolbar',
	components: { NcButton, Refresh, Pencil },
	props: { selectedCount: { type: Number, default: 0 } },
	emits: ['refresh', 'compose'],
}
</script>

<style scoped>
.email-list-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
</style>
