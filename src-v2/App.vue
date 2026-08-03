<template>
	<NcContent app-name="souvera_mail">
		<NcAppNavigation>
			<NcButton variant="primary" class="compose-btn" @click="$router.push({name:'compose'})">
				<template #icon><Pencil :size="20" /></template>
				{{ t('souvera_mail', 'New message') }}
			</NcButton>

			<template #list>
				<NcAppNavigationCaption :name="t('souvera_mail', 'Mailboxes')" />

				<MailboxItem v-for="mb in systemFolders" :key="'s-'+mb.id"
					:mailbox="mb" :all-mailboxes="mailboxes" :selected="selectedMailbox" :depth="0"
					@select="onMailboxSelect" />

				<template v-if="sharedAbove && sharedFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Shared with me')" />
					<MailboxItem v-for="mp in sharedMailboxRoots" :key="'sh-'+mp.id"
						:mailbox="mp" :all-mailboxes="sharedMailboxes" :selected="selectedMailbox" :depth="0"
						@select="onSharedSelect" />
				</template>

				<template v-if="userFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Folders')" />
					<MailboxItem v-for="mb in userFolderRoots" :key="'u-'+mb.id"
						:mailbox="mb" :all-mailboxes="mailboxes" :selected="selectedMailbox" :depth="0"
						@select="onMailboxSelect" />
				</template>

				<template v-if="!sharedAbove && sharedFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Shared with me')" />
					<MailboxItem v-for="mp in sharedMailboxRoots" :key="'sh2-'+mp.id"
						:mailbox="mp" :all-mailboxes="sharedMailboxes" :selected="selectedMailbox" :depth="0"
						@select="onSharedSelect" />
				</template>
			</template>

			<template #footer>
				<QuotaDonut v-if="quotaUsed > 0 || quotaUnlimited" :used="quotaUsed" :total="quotaTotal" :unlimited="quotaUnlimited" />
				<NcAppNavigationItem :name="t('souvera_mail', 'Mail archive')"
					@click="openArchive">
					<template #icon><Archive :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('souvera_mail', 'Settings')"
					:active="currentRoute === 'settings'"
					@click="$router.push({name:'settings'})">
					<template #icon><Cog :size="20" /></template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<router-view v-slot="{ Component }">
				<component :is="Component" v-bind="routeProps" />
			</router-view>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Share from 'vue-material-design-icons/Share.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import MailboxItem from './components/MailboxItem.vue'
import QuotaDonut from './components/QuotaDonut.vue'
import { useJmapClient } from './composables/useJmapClient.js'
import { useHotkeys } from './composables/useHotkeys.js'

const { fetchMailboxes } = useJmapClient()
const SYSTEM_ROLES = ['inbox', 'sent', 'drafts', 'archive', 'junk', 'trash']
const ROLE_ORDER = { inbox:0, drafts:1, sent:2, archive:3, junk:4, trash:5 }

export default {
	name: 'MailV2App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcButton, Pencil, Cog, Share, Archive, MailboxItem, QuotaDonut },
	data() {
		return { mailboxes: [], selectedMailbox: '', sharedFolders: [], sharedMailboxes: [], sharedAbove: true, quotaUsed: 0, quotaTotal: 0, quotaUnlimited: false }
	},
	computed: {
		currentRoute() { return this.$route.name || 'inbox' },
		systemFolders() { return this.mailboxes.filter(m => SYSTEM_ROLES.includes(m.role)).sort((a,b) => (ROLE_ORDER[a.role]??99) - (ROLE_ORDER[b.role]??99)) },
		routeProps() {
			if (this.$route.name === 'inbox') {
				return { selectedMailbox: this.selectedMailbox, allMailboxes: [...this.mailboxes, ...this.sharedMailboxes] }
			}
			return {}
		},
		userFolders() { return this.mailboxes.filter(m => !SYSTEM_ROLES.includes(m.role)) },
		userFolderRoots() { return this.userFolders.filter(m => !m.parentId || !this.userFolders.find(p => p.id === m.parentId)) },
		sharedMailboxRoots() { return this.sharedMailboxes.filter(m => !m.parentId || !this.sharedMailboxes.find(p => p.id === m.parentId)) },
	},
	async mounted() {
		try {
			this.mailboxes = await fetchMailboxes()
			const inbox = this.mailboxes.find(m => m.role === 'inbox') || this.mailboxes[0]
			if (inbox) this.selectedMailbox = inbox.id
		} catch(e) { console.error(e) }
		await Promise.all([this.loadQuota(), this.loadShared()])
		this._hotkeys = useHotkeys({
			c: () => { if (this.$route.name !== 'compose') this.$router.push({ name: 'compose' }) },
			'G': () => { this.$router.push({ name: 'inbox' }) },
		})
	},
	beforeUnmount() {
		this._hotkeys?.destroy()
	},
	methods: {
		onMailboxSelect(id) { this.selectedMailbox = id; this.$router.push({name:'inbox'}) },
		onSharedSelect(id) {
			this.selectedMailbox = id
			this.$router.push({ name: 'inbox' })
		},
		openArchive() {
			window.location.href = this.OC?.generateUrl?.('/apps/souvera_mailarchiv') || '/index.php/apps/souvera_mailarchiv'
		},
		async loadQuota() {
			try {
				const { default: axios } = await import('@nextcloud/axios')
				const { generateUrl } = await import('@nextcloud/router')
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/quota'))
				this.quotaUsed = data.used ?? 0; this.quotaTotal = data.total ?? 0; this.quotaUnlimited = data.unlimited ?? false
			} catch (e) { console.error('Failed to load quota', e) }
		},
		async loadShared() {
			try {
				const { default: axios } = await import('@nextcloud/axios')
				const { generateUrl } = await import('@nextcloud/router')
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/shared'))
				this.sharedFolders = data.shared || []
				this.sharedAbove = data.position === 'above'
				// Fetch mailboxes for each shared account
				if (this.sharedFolders.length > 0) {
					const allSharedMboxes = []
					for (const sh of this.sharedFolders) {
						try {
							const mboxes = await fetchMailboxes(sh.id)
							allSharedMboxes.push(...mboxes)
						} catch (e) { console.error('Failed to load shared mailboxes for', sh.id, e) }
					}
					this.sharedMailboxes = allSharedMboxes
				}
			} catch (e) { console.error('Failed to load shared', e) }
		},
	},
}
</script>

<style scoped>
.compose-btn { margin: 8px; width: calc(100% - 16px); }
</style>
