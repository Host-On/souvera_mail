<template>
	<div class="mailbox-sidebar">
		<div v-if="loading" class="sidebar-loading">
			<span class="icon-loading" />
		</div>
		<template v-else>
			<NcListItem
				v-for="mb in mailboxes"
				:key="mb.id"
				:name="mb.name"
				:bold="mb.unread > 0"
				:active="selected === mb.id"
				@click="$emit('select', mb.id)">
				<template #icon>
					<component :is="mailboxIcon(mb.role)" :size="20" />
				</template>
				<template #extra>
					<span v-if="mb.unread > 0" class="unread-badge">{{ mb.unread }}</span>
				</template>
			</NcListItem>
		</template>
	</div>
</template>

<script>
import { NcListItem } from '@nextcloud/vue'
import Inbox from 'vue-material-design-icons/Inbox.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

export default {
	name: 'MailboxSidebar',
	components: { NcListItem },
	props: {
		mailboxes: { type: Array, default: () => [] },
		selected: { type: String, default: '' },
		loading: { type: Boolean, default: false },
	},
	emits: ['select'],
	methods: {
		mailboxIcon(role) {
			switch (role) {
				case 'inbox': return Inbox
				case 'sent': return Send
				case 'drafts': return Pencil
				case 'trash': return TrashCan
				case 'junk': return AlertCircle
				case 'archive': return Archive
				default: return Folder
			}
		},
	},
}
</script>

<style scoped>
.mailbox-sidebar { width: 220px; border-right: 1px solid var(--color-border); overflow-y: auto; flex-shrink: 0; }
.sidebar-loading { display: flex; justify-content: center; padding: 24px; }
.unread-badge { background: var(--color-primary); color: var(--color-primary-text); border-radius: 10px; padding: 1px 6px; font-size: 11px; font-weight: 600; }
</style>
