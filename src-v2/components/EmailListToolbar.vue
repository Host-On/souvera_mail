<template>
	<div class="email-list-toolbar">
		<div class="email-list-toolbar__left">
			<NcCheckboxRadioSwitch :model-value="selectAllState"
				:indeterminate="selectAllState === 'indeterminate'"
				@update:modelValue="$emit('toggleSelectAll')" />
			<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Refresh')" @click="$emit('refresh')">
				<template #icon><Refresh :size="20" /></template>
			</NcButton>
			<template v-if="selectedCount > 0">
				<span class="selected-count">{{ selectedCount }} {{ t('souvera_mail', 'selected') }}</span>
				<NcButton variant="tertiary" @click="$emit('markRead')">
					<template #icon><EmailOpen :size="20" /></template>
				</NcButton>
				<NcButton variant="tertiary" @click="$emit('markUnread')">
					<template #icon><EmailOutline :size="20" /></template>
				</NcButton>
				<NcActions>
					<template #icon><FolderMove :size="20" /></template>
					<NcActionButton v-for="mb in targetMailboxes" :key="mb.id"
						@click="$emit('moveTo', mb.id)">
						<template #icon><Folder :size="20" /></template>
						{{ mb.name }}
					</NcActionButton>
				</NcActions>
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
import { NcButton, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import EmailOpen from 'vue-material-design-icons/EmailOpen.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import Folder from 'vue-material-design-icons/Folder.vue'

export default {
	name: 'EmailListToolbar',
	components: { NcButton, NcActions, NcActionButton, NcCheckboxRadioSwitch, Refresh, Pencil, EmailOpen, EmailOutline, TrashCan, FolderMove, Folder },
	props: {
		selectedCount: { type: Number, default: 0 },
		selectAllState: { type: [Boolean, String], default: false },
		targetMailboxes: { type: Array, default: () => [] },
	},
	emits: ['refresh', 'compose', 'markRead', 'markUnread', 'bulkDelete', 'moveTo', 'toggleSelectAll'],
}
</script>

<style scoped>
.email-list-toolbar {
	display: flex; justify-content: space-between; align-items: center;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
}
.email-list-toolbar__left { display: flex; align-items: center; gap: 2px; }
.selected-count { font-size: 13px; color: var(--color-primary-element); font-weight: 500; margin: 0 4px; }
</style>
