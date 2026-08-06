<template>
	<div class="email-list-toolbar" :class="{ 'email-list-toolbar--tworow': twoRow, 'email-list-toolbar--search-open': showSearch }">
		<div class="email-list-toolbar__row">
		<div class="email-list-toolbar__left">
			<NcCheckboxRadioSwitch class="email-list-toolbar__check" :model-value="selectAllState"
				:indeterminate="selectAllState === 'indeterminate'"
				@update:modelValue="$emit('toggleSelectAll')" />

			<NcActions v-if="selectedCount === 0" class="email-list-toolbar__quick-actions"
				:aria-label="t('souvera_mail', 'More actions')"
				:disabled="loadingBulk">
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

			<NcButton v-if="isTrash && selectedCount === 0" variant="tertiary"
				@click="$emit('emptyTrash')">
				<template #icon><TrashCan :size="18" /></template>
				{{ t('souvera_mail', 'Empty trash') }}
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
						{{ mailboxDisplayName(mb) }}
					</NcActionButton>
				</NcActions>
				<NcButton variant="tertiary" @click="$emit('bulkDelete')">
					<template #icon><TrashCan :size="20" /></template>
				</NcButton>
			</template>

			<template v-else>
				<NcButton variant="tertiary" class="email-list-toolbar__search-toggle"
					:aria-label="t('souvera_mail', 'Search')"
					:title="t('souvera_mail', 'Search')"
					@click="showSearch = !showSearch">
					<template #icon><Magnify :size="18" /></template>
				</NcButton>

				<NcActions class="email-list-toolbar__filter"
					:menu-name="activeFilterMenuName"
					:primary="filter !== 'all'"
					:force-name="true">
					<template #icon><Filter :size="18" /></template>
					<NcActionButton v-for="f in filterOptions" :key="f.value"
						:aria-label="f.label"
						@click="$emit('update:filter', f.value)">
						<template #icon>
							<Check v-if="filter === f.value" :size="16" />
							<component :is="f.icon" v-else :size="16" />
						</template>
						{{ f.label }}
					</NcActionButton>
				</NcActions>
			</template>
		</div>

		<div class="email-list-toolbar__right">
			<NcLoadingIcon v-if="loadingBulk" :size="18" class="email-list-toolbar__bulk-spinner" />
			<div class="toolbar__refresh-donut"
				@click="$emit('refresh')"
				:title="t('souvera_mail', 'Refresh ({n}s)', { n: refreshCountdown })">
				<svg viewBox="0 0 36 36" class="donut-svg">
					<circle cx="18" cy="18" r="15" fill="none"
						stroke="var(--color-border)" stroke-width="3" />
					<circle cx="18" cy="18" r="15" fill="none"
						stroke="var(--color-primary)" stroke-width="3"
						:stroke-dasharray="circumference"
						:stroke-dashoffset="donutOffset"
						stroke-linecap="round"
						class="donut-fill" />
				</svg>
				<Refresh :size="14" class="donut-icon" />
			</div>
			<NcButton variant="primary" class="email-list-toolbar__compose" @click="$emit('compose')">
				<template #icon><Pencil :size="20" /></template>
				{{ t('souvera_mail', 'New') }}
			</NcButton>
		</div> <!-- end __right -->
		</div> <!-- end __row -->

		<div v-show="showSearch" class="email-list-toolbar__search-row">
			<NcTextField
				ref="searchField"
				class="email-list-toolbar__search-full"
				:model-value="searchQuery"
				:placeholder="t('souvera_mail', 'Search in mailbox…')"
				:show-trailing-button="searchQuery !== ''"
				trailing-button-icon="close"
				:trailing-button-label="t('souvera_mail', 'Clear search')"
				@update:modelValue="$emit('update:search', $event)"
				@trailing-button-click="$emit('update:search', '')" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcTextField, NcLoadingIcon } from '@nextcloud/vue'
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
import Check from 'vue-material-design-icons/Check.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { mailboxDisplayName } from '../utils/mailboxNames.js'

export default {
	name: 'EmailListToolbar',
	components: { NcButton, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcTextField, NcLoadingIcon, Refresh, Pencil, EmailOpen, EmailOutline, TrashCan, FolderMove, Folder, Filter, Star, Paperclip, DotsHorizontal, CheckAll, Check, Magnify },
	props: {
		selectedCount: { type: Number, default: 0 },
		selectAllState: { type: [Boolean, String], default: false },
		targetMailboxes: { type: Array, default: () => [] },
		searchQuery: { type: String, default: '' },
		filter: { type: String, default: 'all' },
		twoRow: { type: Boolean, default: false },
		isTrash: { type: Boolean, default: false },
		refreshCountdown: { type: Number, default: 0 },
		refreshTotal: { type: Number, default: 60 },
		loadingBulk: { type: Boolean, default: false },
	},
	emits: ['refresh', 'compose', 'markRead', 'markUnread', 'bulkDelete', 'moveTo', 'toggleSelectAll', 'update:search', 'update:filter', 'selectAll', 'markAllRead', 'markAllUnread', 'emptyTrash'],
	data() {
		return { showSearch: false, localSearch: '' }
	},
	computed: {
		circumference() { return 2 * Math.PI * 15 },
		donutOffset() {
			const fraction = this.refreshTotal > 0 ? this.refreshCountdown / this.refreshTotal : 0
			return this.circumference * (1 - fraction)
		},
		activeFilterMenuName() {
			const active = this.filterOptions.find(f => f.value === this.filter)
			return this.t('souvera_mail', 'Filter: {name}', { name: active ? active.label : this.t('souvera_mail', 'All') })
		},
		filterOptions() { return [
			{ value: 'all', label: this.t('souvera_mail', 'All'), icon: EmailOutline },
			{ value: 'unread', label: this.t('souvera_mail', 'Unread'), icon: EmailOpen },
			{ value: 'flagged', label: this.t('souvera_mail', 'Flagged'), icon: Star },
			{ value: 'attachments', label: this.t('souvera_mail', 'With attachments'), icon: Paperclip },
		]},
	},
	watch: {
		searchQuery: {
			immediate: true,
			handler(n) { if (n) this.showSearch = true },
		},
	},
	async mounted() {
		if (!this.twoRow) this.showSearch = true
	},
}
</script>

<style scoped>
.email-list-toolbar {
	display: flex; flex-direction: column;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
}
.email-list-toolbar__row { display: flex; justify-content: space-between; align-items: center; }
.email-list-toolbar__left { display: flex; align-items: center; gap: 4px; flex: 1; min-width: 0; }
.email-list-toolbar__right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.email-list-toolbar__search-row { padding-top: 6px; }
.email-list-toolbar__search-full { width: 100%; }
.email-list-toolbar__search-toggle { flex-shrink: 0; }
.selected-count { font-size: 13px; color: var(--color-primary-element); font-weight: 500; margin: 0 4px; }

/* Two-row mode — everything in one compact row */
.email-list-toolbar--tworow { padding: 8px 8px; }
.email-list-toolbar--tworow .email-list-toolbar__left {
	display: flex; align-items: center; gap: 3px; flex: 1; min-width: 0;
}

/* Refresh donut */
.toolbar__refresh-donut {
	position: relative; width: 28px; height: 28px;
	display: flex; align-items: center; justify-content: center;
	cursor: pointer; flex-shrink: 0; border-radius: 50%;
}
.toolbar__refresh-donut:hover { background: var(--color-background-hover); }
.donut-svg { position: absolute; width: 26px; height: 26px; top: 1px; left: 1px; }
.donut-fill { transform: rotate(-90deg); transform-origin: 50% 50%; transition: stroke-dashoffset 0.3s linear; }
.donut-icon { position: relative; z-index: 1; opacity: 0.7; }
</style>
