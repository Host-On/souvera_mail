<template>
	<NcAppNavigationItem
		:name="mailbox.name"
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
				@select="$emit('select', $event)" />
		</template>
	</NcAppNavigationItem>
</template>

<script>
import { NcAppNavigationItem, NcCounterBubble } from '@nextcloud/vue'
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
	emits: ['select'],
	data() { return { open: false } },
	computed: {
		active() { return this.selected === this.mailbox.id },
		icon() { return ROLE_ICONS[this.mailbox.role] || Folder },
		children() {
			return this.allMailboxes.filter(m => m.parentId === this.mailbox.id)
		},
	},
}
</script>
