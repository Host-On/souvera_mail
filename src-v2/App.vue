<template>
	<NcContent app-name="souvera_mail">
		<NcAppNavigation>
			<div class="compose-row">
				<NcButton variant="primary" class="compose-btn" @click="$router.push({name:'compose'})">
					<template #icon><Pencil :size="20" /></template>
					{{ t('souvera_mail', 'New message') }}
				</NcButton>
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Contacts')"
					@click="showContactPicker = true">
					<template #icon><Contacts :size="20" /></template>
				</NcButton>
			</div>

			<template #list>
				<NcAppNavigationCaption :name="t('souvera_mail', 'Mailboxes')" />

				<MailboxItem v-for="mb in systemFolders" :key="'s-'+mb.id"
					:mailbox="mb" :all-mailboxes="mailboxes" :selected="selectedMailbox" :depth="0"
					@select="onMailboxSelect" />

				<template v-if="sharedAbove && sharedFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Shared with me')" />
					<template v-for="group in sharedAccountGroups" :key="group.accountId">
						<NcAppNavigationCaption class="shared-group-caption" :name="group.accountName" />
						<MailboxItem v-for="mp in group.roots" :key="mp.id"
							:mailbox="mp" :all-mailboxes="sharedMailboxes" :selected="sharedSelected(mp)" :depth="0"
							@select="onSharedSelect(mp._accountId, $event)" />
					</template>
				</template>

				<template v-if="userFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Folders')" />
					<MailboxItem v-for="mb in userFolderRoots" :key="'u-'+mb.id"
						:mailbox="mb" :all-mailboxes="mailboxes" :selected="selectedMailbox" :depth="0"
						@select="onMailboxSelect" />
				</template>

				<template v-if="!sharedAbove && sharedFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Shared with me')" />
					<template v-for="group in sharedAccountGroups" :key="'low-'+group.accountId">
						<NcAppNavigationCaption class="shared-group-caption" :name="group.accountName" />
						<MailboxItem v-for="mp in group.roots" :key="mp.id"
							:mailbox="mp" :all-mailboxes="sharedMailboxes" :selected="sharedSelected(mp)" :depth="0"
							@select="onSharedSelect(mp._accountId, $event)" />
					</template>
				</template>
			</template>

			<template #footer>
				<div class="app-footer">
					<QuotaDonut v-if="quotaTotal > 0 || quotaUnlimited" :inline="true" :size="22" :used="quotaUsed" :total="quotaTotal" :unlimited="quotaUnlimited" />
					<NcAppNavigationItem :name="t('souvera_mail', 'Mail archive')"
						@click="openArchive">
						<template #icon><Archive :size="20" /></template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('souvera_mail', 'Settings')"
						:active="showSettings"
						@click="showSettings = true; $router.push({name:'inbox'})">
						<template #icon><Cog :size="20" /></template>
					</NcAppNavigationItem>
				</div>
			</template>
		</NcAppNavigation>

	<NcAppContent>
		<SettingsView v-if="showSettings" />
		<router-view v-else v-slot="{ Component }">
			<component :is="Component" :key="$route.fullPath" v-bind="routeProps" />
		</router-view>
	</NcAppContent>
	</NcContent>
	<ContactPicker v-if="showContactPicker" @close="showContactPicker = false" @select="onContactsSelected" @compose="onContactsCompose" />
</template>

<script>
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Share from 'vue-material-design-icons/Share.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import Contacts from 'vue-material-design-icons/Contacts.vue'
import MailboxItem from './components/MailboxItem.vue'
import QuotaDonut from './components/QuotaDonut.vue'
import SettingsView from './views/SettingsView.vue'
import ContactPicker from './components/ContactPicker.vue'
import { useJmapClient } from './composables/useJmapClient.js'
import { useHotkeys } from './composables/useHotkeys.js'

const { fetchMailboxes } = useJmapClient()
const SYSTEM_ROLES = ['inbox', 'sent', 'drafts', 'archive', 'junk', 'trash']
const ROLE_ORDER = { inbox:0, drafts:1, sent:2, archive:3, junk:4, trash:5 }

export default {
	name: 'MailV2App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcButton, Pencil, Cog, Share, Archive, Contacts, MailboxItem, QuotaDonut, SettingsView, ContactPicker },
	data() {
		return { mailboxes: [], selectedMailbox: '', sharedFolders: [], sharedMailboxes: [], sharedAbove: true, quotaUsed: 0, quotaTotal: 0, quotaUnlimited: false, showSettings: false, isVertical: false, showContactPicker: false }
	},
	computed: {
		currentRoute() { return this.$route.name || 'inbox' },
		systemFolders() { return this.mailboxes.filter(m => SYSTEM_ROLES.includes(m.role)).sort((a,b) => (ROLE_ORDER[a.role]??99) - (ROLE_ORDER[b.role]??99)) },
		routeProps() {
			if (this.$route.name === 'inbox') {
				return { selectedMailbox: this.selectedMailbox, allMailboxes: [...this.mailboxes, ...this.sharedMailboxes], verticalLayout: this.isVertical }
			}
			return {}
		},
		userFolders() { return this.mailboxes.filter(m => !SYSTEM_ROLES.includes(m.role)) },
		userFolderRoots() { return this.userFolders.filter(m => !m.parentId || !this.userFolders.find(p => p.id === m.parentId)) },
		sharedMailboxRoots() { return this.sharedMailboxes.filter(m => !m.parentId || !this.sharedMailboxes.find(p => p.id === m.parentId)) },
		sharedAccountGroups() {
			const map = new Map()
			for (const m of this.sharedMailboxes) {
				const aid = m._accountId || ''
				if (!map.has(aid)) {
					const acc = this.sharedFolders.find(f => f.id === aid)
					map.set(aid, { accountId: aid, accountName: acc?.name || aid, roots: [] })
				}
			}
			for (const m of this.sharedMailboxRoots) {
				const aid = m._accountId || ''
				if (map.has(aid)) map.get(aid).roots.push(m)
			}
			return [...map.values()]
		},
	},
	async mounted() {
		try {
			this.mailboxes = await fetchMailboxes()
			const inbox = this.mailboxes.find(m => m.role === 'inbox') || this.mailboxes[0]
			if (inbox) this.selectedMailbox = inbox.id
		} catch(e) { console.error(e) }
		await Promise.all([this.loadQuota(), this.loadShared(), this.loadLayout()])
		this._hotkeys = useHotkeys({
			c: () => { if (this.$route.name !== 'compose') this.$router.push({ name: 'compose' }) },
			'G': () => { this.$router.push({ name: 'inbox' }) },
		})
	},
	beforeUnmount() {
		this._hotkeys?.destroy()
	},
	errorCaptured(err, instance, info) {
		console.error('App: child component error', err, info)
		return false
	},
	methods: {
		onMailboxSelect(id) { this.selectedMailbox = id; this.showSettings = false; this.$router.push({name:'inbox'}) },
		onSharedSelect(accountId, mailboxId) {
			this.selectedMailbox = accountId + '|' + mailboxId
			this.showSettings = false
			if (this.$route.name === 'inbox') {
				this.$router.replace({ name: 'inbox', query: { t: String(Date.now()) } })
			} else {
				this.$router.push({ name: 'inbox' })
			}
		},
		sharedSelected(mp) {
			return this.selectedMailbox === (mp._accountId + '|' + mp.id)
		},
		openArchive() {
			window.location.href = this.OC?.generateUrl?.('/apps/souvera_mailarchiv') || '/index.php/apps/souvera_mailarchiv'
		},
		onContactsSelected(recipients) {
			const q = recipients.map(r => r.name ? `"${r.name}" <${r.email}>` : r.email).join(',')
			this.$router.push({ name: 'compose', query: { to: q } })
		},
		onContactsCompose(recipients) {
			const to = recipients.map(r => r.email).join(',')
			const names = recipients.map(r => r.name ? `${r.name} <${r.email}>` : r.email).join(',')
			this.$router.push({ name: 'compose', query: { to: names } })
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
		async loadLayout() {
			try {
				const { default: axios } = await import('@nextcloud/axios')
				const { generateUrl } = await import('@nextcloud/router')
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'))
				this.isVertical = data.verticalLayout || false
			} catch (e) { console.error('Failed to load layout pref', e) }
		},
	},
}
</script>

<style scoped>
.compose-row { display: flex; gap: 4px; margin: 8px; }
.compose-btn { flex: 1; }
.compose-row :deep(button[aria-label="Contacts"]) { min-width: 44px; padding: 0; }

/* Shared account sub-headers — clearly distinct from the top-level   "Mailboxes" / "Shared with me" / "Folders" captions: smaller, lighter,
   indented, with a bullet. Targets the inner __name element because the
   caption component sets font-size/font-weight/color there with higher
   specificity (which is why styling the outer li never showed). */
:deep(.shared-group-caption) {
	padding-left: 30px !important;
	padding-right: var(--app-navigation-padding);
}
:deep(.shared-group-caption .app-navigation-caption__name) {
	font-size: 12px !important;
	font-weight: 400 !important;
	color: var(--color-text-maxcontrast) !important;
	line-height: 28px;
}
:deep(.shared-group-caption .app-navigation-caption__name)::before {
	content: '•';
	display: inline-block;
	margin-right: 7px;
	color: var(--color-border-dark);
	font-size: 10px;
	vertical-align: 1px;
}

/* Nav footer — slightly distinct background, matching the mail detail
   toolbar (color-background-dark + border) to visually separate it. */
.app-footer {
	background: var(--color-background-dark);
	border-top: 1px solid var(--color-border);
}
.app-footer :deep(.app-navigation-entry__link:hover) {
	background: var(--color-background-hover);
}
</style>
