<template>
	<NcContent app-name="souvera_mail">
		<NcAppNavigation>
			<NcButton variant="primary" class="compose-btn" @click="$router.push({name:'compose'})">
				<template #icon><Pencil :size="20" /></template>
				{{ t('souvera_mail', 'New message') }}
			</NcButton>

			<template #list>
				<NcAppNavigationCaption :name="t('souvera_mail', 'Mailboxes')" />

				<MailboxItem
					v-for="mb in systemFolders"
					:key="mb.id"
					:mailbox="mb"
					:all-mailboxes="mailboxes"
					:selected="selectedMailbox"
					:depth="0"
					@select="onMailboxSelect" />

				<template v-if="userFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Folders')" />
					<MailboxItem
						v-for="mb in userFolderRoots"
						:key="mb.id"
						:mailbox="mb"
						:all-mailboxes="mailboxes"
						:selected="selectedMailbox"
						:depth="0"
						@select="onMailboxSelect" />
				</template>
			</template>

			<template #footer>
				<QuotaDonut v-if="quotaTotal > 0" :used="quotaUsed" :total="quotaTotal" />
				<NcAppNavigationItem
					:name="t('souvera_mail', 'Settings')"
					:active="currentRoute === 'settings'"
					@click="$router.push({name:'settings'})">
					<template #icon><Cog :size="20" /></template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<router-view v-slot="{ Component }">
				<component :is="Component" :selected-mailbox="selectedMailbox" />
			</router-view>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import MailboxItem from './components/MailboxItem.vue'
import QuotaDonut from './components/QuotaDonut.vue'
import { useJmapClient } from './composables/useJmapClient.js'

const { fetchMailboxes } = useJmapClient()

const SYSTEM_ROLES = ['inbox', 'sent', 'drafts', 'archive', 'junk', 'trash']
const ROLE_ORDER = { inbox:0, drafts:1, sent:2, archive:3, junk:4, trash:5 }

export default {
	name: 'MailV2App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcButton, Pencil, Cog, MailboxItem, QuotaDonut },
	data() {
		return { mailboxes: [], selectedMailbox: '', quotaUsed: 0, quotaTotal: 0 }
	},
	computed: {
		currentRoute() { return this.$route.name || 'inbox' },
		systemFolders() {
			return this.mailboxes.filter(m => SYSTEM_ROLES.includes(m.role))
				.sort((a,b) => (ROLE_ORDER[a.role]??99) - (ROLE_ORDER[b.role]??99))
		},
		userFolders() { return this.mailboxes.filter(m => !SYSTEM_ROLES.includes(m.role)) },
		userFolderRoots() {
			return this.userFolders.filter(m => !m.parentId || !this.userFolders.find(p => p.id === m.parentId))
		},
	},
	async mounted() {
		try {
			this.mailboxes = await fetchMailboxes()
			const inbox = this.mailboxes.find(m => m.role === 'inbox') || this.mailboxes[0]
			if (inbox) this.selectedMailbox = inbox.id
		} catch(e) { console.error(e) }
		await this.loadQuota()
	},
	methods: {
		onMailboxSelect(id) { this.selectedMailbox = id; this.$router.push({name:'inbox'}) },
		async loadQuota() {
			try {
				const { default: axios } = await import('@nextcloud/axios')
				const { generateUrl } = await import('@nextcloud/router')
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/quota'))
				this.quotaUsed = data.used ?? 0
				this.quotaTotal = data.total ?? 0
			} catch {}
		},
	},
}
</script>

<style scoped>
.compose-btn { margin: 8px; width: calc(100% - 16px); }
</style>
