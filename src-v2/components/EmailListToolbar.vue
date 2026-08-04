<template>
	<div class="email-list-toolbar" :class="{ 'email-list-toolbar--tworow': twoRow }">
		<div class="email-list-toolbar__left">
			<NcCheckboxRadioSwitch class="email-list-toolbar__check" :model-value="selectAllState"
				:indeterminate="selectAllState === 'indeterminate'"
				@update:modelValue="$emit('toggleSelectAll')" />
			<NcActions v-if="selectedCount === 0" class="email-list-toolbar__quick-actions"
				:aria-label="t('souvera_mail', 'More actions')">
				<template #icon><DotsHorizontal :size="18" /></template>
				<NcActionButton :name="t('souvera_mail', 'Select all')" @click="$emit('selectAll')">
					<template #icon><CheckAll :size="16" /></template>
				</NcActionButton>
				<NcActionButton :name="t('souvera_mail', 'Mark all as read')" @click="$emit('markAllRead')">
					<template #icon><EmailOpen :size="16" /></template>
				</NcActionButton>
				<NcActionButton :name="t('souvera_mail', 'Mark all as unread')" @click="$emit('markAllUnread')">
					<template #icon><EmailOutline :size="16" /></template>
				</NcActionButton>
			</NcActions>
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
						{{ mailboxDisplayName(mb) }}
					</NcActionButton>
				</NcActions>
				<NcButton variant="tertiary" @click="$emit('bulkDelete')">
					<template #icon><TrashCan :size="20" /></template>
				</NcButton>
			</template>
			<template v-else>
				<NcTextField class="email-list-toolbar__search"
					:model-value="searchQuery"
					:placeholder="t('souvera_mail', 'Search in mailbox…')"
					:show-trailing-button="searchQuery !== ''"
					trailing-button-icon="close"
					:trailing-button-label="t('souvera_mail', 'Clear search')"
					@update:modelValue="$emit('update:search', $event)"
					@trailing-button-click="$emit('update:search', '')" />
				<NcActions class="email-list-toolbar__filter"
					:menu-name="activeFilterMenuName"
					:primary="filter !== 'all'"
					:force-name="true">
					<template #icon><Filter :size="18" /></template>
					<NcActionButton v-for="f in filterOptions" :key="f.value"
						type="radio"
						:name="f.label"
						:model-value="filter"
						:value="f.value"
						@click="$emit('update:filter', f.value)">
						<template #icon><component :is="f.icon" :size="16" /></template>
					</NcActionButton>
				</NcActions>
			</template>
		</div>
		<NcButton variant="primary" class="email-list-toolbar__compose" @click="$emit('compose')">
			<template #icon><Pencil :size="20" /></template>
			{{ t('souvera_mail', 'New') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import EmailOpen from 'vue-material-design-icons/EmailOpen.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Filter from 'vue-material-design-icons/Filter.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import { mailboxDisplayName } from '../utils/mailboxNames.js'

export default {
	name: 'EmailListToolbar',
	components: { NcButton, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcTextField, Refresh, Pencil, EmailOpen, EmailOutline, TrashCan, FolderMove, Folder, Filter, Star, Paperclip, DotsHorizontal, CheckAll },
	props: {
		selectedCount: { type: Number, default: 0 },
		selectAllState: { type: [Boolean, String], default: false },
		targetMailboxes: { type: Array, default: () => [] },
		searchQuery: { type: String, default: '' },
		filter: { type: String, default: 'all' },
		// twoRow: force a two-line toolbar (checkbox+search / filter+compose)
		// for narrow side-by-side list panels.
		twoRow: { type: Boolean, default: false },
	},
	emits: ['refresh', 'compose', 'markRead', 'markUnread', 'bulkDelete', 'moveTo', 'toggleSelectAll', 'update:search', 'update:filter', 'selectAll', 'markAllRead', 'markAllUnread'],
	computed: {
		activeFilterMenuName() {
			const active = this.filterOptions.find(f => f.value === this.filter)
			return this.t('souvera_mail', 'Filter: {name}', { name: active ? active.label : this.t('souvera_mail', 'All') })
		},
		filterOptions() {
			return [
				{ value: 'all', label: this.t('souvera_mail', 'All'), icon: EmailOutline },
				{ value: 'unread', label: this.t('souvera_mail', 'Unread'), icon: EmailOpen },
				{ value: 'flagged', label: this.t('souvera_mail', 'Flagged'), icon: Star },
				{ value: 'attachments', label: this.t('souvera_mail', 'With attachments'), icon: Paperclip },
			]
		},
	},
}
</script>

<style scoped>
.email-list-toolbar {
	display: flex; justify-content: space-between; align-items: center;
	flex-wrap: wrap; row-gap: 4px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
}
.email-list-toolbar__left { display: flex; align-items: center; flex-wrap: wrap; column-gap: 2px; row-gap: 4px; flex: 1; min-width: 0; }
.email-list-toolbar__search { flex: 1; max-width: 300px; min-width: 100px; margin: 0 8px; }
.selected-count { font-size: 13px; color: var(--color-primary-element); font-weight: 500; margin: 0 4px; }

/* Two-row mode (side-by-side list panel) — EXACTLY two lines with
   CSS grid, no flex-wrap guesswork. */
.email-list-toolbar--tworow { position: relative; }
.email-list-toolbar--tworow .email-list-toolbar__left {
	flex: 1 1 100%;
	display: grid;
	grid-template-columns: auto auto 1fr;
	grid-template-rows: auto auto;
	align-items: center;
	column-gap: 4px; row-gap: 6px;
}
/* Row 1: checkbox, quick-actions, search */
.email-list-toolbar--tworow .email-list-toolbar__check { grid-column: 1; grid-row: 1; }
.email-list-toolbar--tworow .email-list-toolbar__quick-actions { grid-column: 2; grid-row: 1; }
.email-list-toolbar--tworow .email-list-toolbar__search {
	grid-column: 3; grid-row: 1;
	max-width: none; min-width: 0;
	margin: 0;
}
/* Row 2: filter (spans all three columns) */
.email-list-toolbar--tworow .email-list-toolbar__filter {
	grid-column: 1 / 4; grid-row: 2;
	margin-right: 100px;  /* room for the absolutely-placed compose button */
}
.email-list-toolbar--tworow .email-list-toolbar__compose {
	position: absolute;
	right: 12px;
	bottom: 6px;
}
</style>
