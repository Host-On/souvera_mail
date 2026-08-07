<template>
	<div
		@dragover.prevent="onDragOver"
		@dragleave="onDragLeave"
		@drop.prevent="onDrop">
	<NcAppNavigationItem
		:name="displayName"
		:active="active"
		:allow-collapse="children.length > 0"
		:open="open"
		@click="$emit('select', mailbox.id)"
		@update:open="open = $event">
		<template #icon>
			<component :is="icon" :size="20" />
		</template>
		<template #counter v-if="mailbox.unread > 0">
			<NcCounterBubble :count="mailbox.unread" />
		</template>

		<template v-if="open && children.length > 0">
			<MailboxItem
				v-for="child in children"
				:key="child.id"
				:mailbox="child"
				:all-mailboxes="allMailboxes"
				:selected="selected"
				:depth="depth + 1"
				@select="$emit('select', $event)"
				@drop-email="$emit('dropEmail', $event)" />
		</template>
	</NcAppNavigationItem>
	</div>
</template>

<script>
import { NcAppNavigationItem, NcCounterBubble } from '@nextcloud/vue'
import { mailboxDisplayName } from '../utils/mailboxNames.js'
import Inbox from 'vue-material-design-icons/Inbox.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Folder from 'vue-material-design-icons/Folder.vue'

const ROLE_ICONS = {
	inbox: Inbox, sent: Send, drafts: Pencil, archive: Archive,
	junk: AlertCircle, trash: TrashCan, flagged: Star,
}

export default {
	name: 'MailboxItem',
	components: { NcAppNavigationItem, NcCounterBubble },
	props: {
		mailbox: { type: Object, required: true },
		allMailboxes: { type: Array, default: () => [] },
		selected: { type: String, default: '' },
		depth: { type: Number, default: 0 },
	},
	emits: ['select', 'dropEmail'],
	data() { return { open: false, dragOver: false } },
	computed: {
		displayName() { return mailboxDisplayName(this.mailbox) },
		active() {
			if (!this.selected) return false
			return this.selected === this.mailbox.id
				|| this.selected === (this.mailbox._accountId + '|' + this.mailbox.id)
		},
		icon() { return ROLE_ICONS[this.mailbox.role] || Folder },
		children() {
			return this.allMailboxes.filter(m => m.parentId === this.mailbox.id)
		},
	},
	methods: {
		onDragOver() { this.dragOver = true },
		onDragLeave() { this.dragOver = false },
		onDrop(e) {
			this.dragOver = false
			const emailId = window.__souveraDragEmail
			if (emailId) {
				window.__souveraDragEmail = null
				this.$emit('dropEmail', { emailId, mailboxId: this.mailbox.id, mailbox: this.mailbox })
			}
		},
	},
}
</script>

<style scoped>
/* The active indicator (left stripe) of NcAppNavigationItem is an
   absolutely-positioned ::before that needs a positioned ancestor — make
   every entry its own positioning context so the stripe reliably appears
   for main AND shared mailboxes. */
:deep(.app-navigation-entry) { position: relative; }
:deep(.app-navigation-entry.active)::before {
	content: '';
	position: absolute;
	inset-block: calc(var(--default-grid-baseline, 4px) * 2);
	inset-inline-start: 0;
	width: 3px;
	background-color: var(--color-primary-element);
	border-radius: 999px;
	z-index: 1;
}
</style>
