<template>
	<NcContent app-name="souvera_mail">
		<NcAppNavigation>
			<template #search>
				<div class="compose-row">
					<NcButton variant="primary" class="compose-btn" @click="startCompose">
						<template #icon><Pencil :size="20" /></template>
						{{ t('souvera_mail', 'New message') }}
					</NcButton>
					<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Contacts')"
						@click="showContactPicker = true">
						<template #icon><Contacts :size="20" /></template>
					</NcButton>
				</div>
			</template>

			<template #list>
				<NcAppNavigationCaption :name="t('souvera_mail', 'Mailboxes')" />

				<MailboxItem v-for="mb in systemFolders" :key="'s-'+mb.id"
					:mailbox="mb" :all-mailboxes="mailboxes" :selected="selectedMailbox" :depth="0"
					@select="onMailboxSelect"
					@drop-email="onDropEmail" />

				<template v-if="sharedAbove && sharedFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Shared with me')" />
					<template v-for="group in sharedAccountGroups" :key="group.accountId">
						<NcAppNavigationItem class="nav-group-toggle" :name="group.accountName"
							@click="toggleGroup(group.accountId)">
							<template #icon>
								<ChevronDown v-if="!isGroupCollapsed(group.accountId)" :size="16" />
								<ChevronRight v-else :size="16" />
							</template>
							<template #counter v-if="groupUnread(group.accountId) > 0">
								<NcCounterBubble :count="groupUnread(group.accountId)" />
							</template>
						</NcAppNavigationItem>
						<template v-if="!isGroupCollapsed(group.accountId)">
							<MailboxItem v-for="mp in group.roots" :key="mp.id"
								:mailbox="mp" :all-mailboxes="sharedMailboxes" :selected="selectedMailbox" :depth="0"
								account-scoped
								@select="onSharedSelect(mp._accountId, $event)"
								@drop-email="onDropEmail" />
						</template>
					</template>
				</template>

				<template v-if="userFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Folders')" />
					<MailboxItem v-for="mb in userFolderRoots" :key="'u-'+mb.id"
						:mailbox="mb" :all-mailboxes="mailboxes" :selected="selectedMailbox" :depth="0"
						@select="onMailboxSelect"
						@drop-email="onDropEmail" />
				</template>

				<template v-if="externalAccounts.length > 0">
					<li class="app-navigation-caption ext-caption">
						<span class="app-navigation-caption__name">
							<LanConnect :size="14" class="ext-caption__icon" />
							{{ t('souvera_mail', 'External accounts') }}
						</span>
					</li>
					<template v-for="acc in externalAccounts" :key="'ext-'+acc.id">
						<NcAppNavigationItem class="nav-group-toggle" :name="acc.email"
							@click.prevent="toggleExtAccount(acc)">
							<template #icon>
								<ChevronDown v-if="extExpanded[acc.id]" :size="16" />
								<ChevronRight v-else :size="16" />
							</template>
							<template #counter v-if="extUnread(acc.id) > 0">
								<NcCounterBubble :count="extUnread(acc.id)" />
							</template>
						</NcAppNavigationItem>
						<template v-if="extExpanded[acc.id]">
							<NcAppNavigationItem v-if="extFoldersLoading[acc.id]"
								:name="t('souvera_mail', 'Loading…')" />
							<NcAppNavigationItem v-for="f in extFolders[acc.id] || []" :key="'extf-'+acc.id+'-'+f.path"
								:name="extFolderDisplayName(f)"
								:active="$route.name === 'external' && String($route.params.id) === String(acc.id) && $route.query.folder === f.path"
								@click.prevent="openExtFolder(acc, f)">
								<template #counter v-if="f.unread > 0">
									<NcCounterBubble :count="f.unread" />
								</template>
							</NcAppNavigationItem>
							<NcAppNavigationItem v-if="!extFoldersLoading[acc.id] && (extFolders[acc.id] || []).length === 0"
								:name="t('souvera_mail', 'No folders')" />
						</template>
					</template>
				</template>

				<template v-if="!sharedAbove && sharedFolders.length > 0">
					<NcAppNavigationCaption :name="t('souvera_mail', 'Shared with me')" />
					<template v-for="group in sharedAccountGroups" :key="'low-'+group.accountId">
						<NcAppNavigationItem class="nav-group-toggle" :name="group.accountName"
							@click="toggleGroup(group.accountId)">
							<template #icon>
								<ChevronDown v-if="!isGroupCollapsed(group.accountId)" :size="16" />
								<ChevronRight v-else :size="16" />
							</template>
							<template #counter v-if="groupUnread(group.accountId) > 0">
								<NcCounterBubble :count="groupUnread(group.accountId)" />
							</template>
						</NcAppNavigationItem>
						<template v-if="!isGroupCollapsed(group.accountId)">
							<MailboxItem v-for="mp in group.roots" :key="mp.id"
								:mailbox="mp" :all-mailboxes="sharedMailboxes" :selected="selectedMailbox" :depth="0"
								account-scoped
								@select="onSharedSelect(mp._accountId, $event)"
								@drop-email="onDropEmail" />
						</template>
					</template>
				</template>
			</template>

			<template #footer>
				<div class="app-footer">
					<QuotaDonut v-if="quotaTotal > 0 || quotaUnlimited" :inline="true" :light-track="true" :size="22" :used="quotaUsed" :total="quotaTotal" :unlimited="quotaUnlimited" />
					<NcAppNavigationItem v-if="mailArchiveEnabled" :name="t('souvera_mail', 'Mail archive')"
						@click="openArchive">
						<template #icon><Archive :size="20" /></template>
					</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('souvera_mail', 'Settings')"
					:to="{ name: 'settings' }"
					:active="$route.name === 'settings'">
						<template #icon><Cog :size="20" /></template>
					</NcAppNavigationItem>
				</div>
			</template>
		</NcAppNavigation>

	<NcAppContent>
		<router-view v-slot="{ Component }">
			<component :is="Component" :key="$route.fullPath" v-bind="routeProps" />
		</router-view>
	</NcAppContent>
	</NcContent>
	<ContactPicker v-if="showContactPicker" @close="showContactPicker = false" @select="onContactsSelected" @compose="onContactsCompose" />
</template>

<script>
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcButton, NcCounterBubble } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Share from 'vue-material-design-icons/Share.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import Contacts from 'vue-material-design-icons/Contacts.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import LanConnect from 'vue-material-design-icons/LanConnect.vue'
import MailboxItem from './components/MailboxItem.vue'
import QuotaDonut from './components/QuotaDonut.vue'
import ContactPicker from './components/ContactPicker.vue'
import { useJmapClient } from './composables/useJmapClient.js'
import { extFolderDisplayName } from './utils/mailboxNames.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useHotkeys } from './composables/useHotkeys.js'
import { emit } from '@nextcloud/event-bus'

const { fetchMailboxes } = useJmapClient()
const SYSTEM_ROLES = ['inbox', 'drafts', 'sent', 'junk', 'trash']
const ROLE_ORDER = { inbox:0, drafts:1, sent:2, junk:3, trash:4 }

export default {
	name: 'MailV2App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption, NcAppContent, NcButton, NcCounterBubble, Pencil, Cog, Share, Archive, Contacts, ChevronDown, ChevronRight, LanConnect, MailboxItem, QuotaDonut, ContactPicker },
	data() {
		return { mailboxes: [], selectedMailbox: '', sharedFolders: [], sharedMailboxes: [], sharedAbove: true, externalAccounts: [], extFolders: {}, extFoldersLoading: {}, extExpanded: {}, quotaUsed: 0, quotaTotal: 0, quotaUnlimited: false, isVertical: false, listOnlyLayout: false, focusLayout: false, _responsiveVertical: false, mailArchiveEnabled: false, showContactPicker: false, navCollapsedGroups: [] }
	},
	computed: {
		currentRoute() { return this.$route.name || 'inbox' },		systemFolders() { return this.mailboxes.filter(m => SYSTEM_ROLES.includes(m.role)).sort((a,b) => (ROLE_ORDER[a.role]??99) - (ROLE_ORDER[b.role]??99)) },
		routeProps() {
			if (this.$route.name === 'inbox') {
				return { selectedMailbox: this.selectedMailbox, allMailboxes: [...this.mailboxes, ...this.sharedMailboxes], verticalLayout: this._responsiveVertical || this.isVertical, listOnlyLayout: (this.listOnlyLayout || this.focusLayout) && !this._responsiveVertical, focusLayout: this.focusLayout && !this._responsiveVertical }
			}
			return {}
		},
		userFolders() { return this.mailboxes.filter(m => !SYSTEM_ROLES.includes(m.role)) },
		// Only ROOT-level folders belong under "Folders". A folder whose
		// parent exists in the mailbox tree — including system folders like
		// Inbox or Trash — is rendered nested under that parent instead.
		userFolderRoots() { return this.userFolders.filter(m => !m.parentId || !this.mailboxes.some(p => p.id === m.parentId)) },
		sharedMailboxRoots() { return this.sharedMailboxes.filter(m => (!m.parentId || !this.sharedMailboxes.find(p => p.id === m.parentId)) && m.role !== 'archive') },
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
			// Sort system folders within each group by role order
			for (const [, g] of map) {
				g.roots.sort((a, b) => (ROLE_ORDER[a.role] ?? 99) - (ROLE_ORDER[b.role] ?? 99))
			}
			return [...map.values()]
		},
	},
	watch: {
		// Responsive: after picking an entry in the navigation, close the
		// slide-in menu automatically so the content is visible again.
		// NcAppNavigation keeps its open state internally and syncs it via
		// the "toggle-navigation" event-bus.
		'$route.fullPath'() {
			if (window.innerWidth < 1024) emit('toggle-navigation', { open: false })
		},
		// Mailbox switches inside the inbox route don't change the path.
		selectedMailbox() {
			if (window.innerWidth < 1024) emit('toggle-navigation', { open: false })
		},
	},
	async mounted() {
		try {
			this.mailboxes = await fetchMailboxes()
			const inbox = this.mailboxes.find(m => m.role === 'inbox') || this.mailboxes[0]
			if (inbox) this.selectedMailbox = inbox.id
		} catch(e) { console.error(e) }
		await Promise.all([this.loadQuota(), this.loadShared(), this.loadLayout(), this.loadExternalAccounts()])
		// Refresh mailbox unread counts when emails are read/deleted/moved
		window.addEventListener('souvera-mail:refresh-mailboxes', this.onRefreshMailboxes)
		// External accounts changed in Settings — refresh the sidebar list.
		window.addEventListener('souvera-mail:refresh-external', this.loadExternalAccounts)
		this._onResize = () => {
			const wasAuto = this._responsiveVertical
			this._responsiveVertical = window.innerWidth < 1024
			if (wasAuto !== this._responsiveVertical) {
				// force re-render of route view with new layout
				this.$forceUpdate()
			}
		}
		window.addEventListener('resize', this._onResize)
		this._onResize()
		this._hotkeys = useHotkeys({
			c: () => { this.startCompose() },
			'G': () => { this.$router.push({ name: 'inbox' }) },
		})
	},
	beforeUnmount() {
		window.removeEventListener('souvera-mail:refresh-mailboxes', this.onRefreshMailboxes)
		window.removeEventListener('souvera-mail:refresh-external', this.loadExternalAccounts)
		window.removeEventListener('resize', this._onResize)
		this._hotkeys?.destroy()
	},
	errorCaptured(err, instance, info) {
		console.error('App: child component error', err, info)
		return false
	},
	methods: {
		extFolderDisplayName,
		isGroupCollapsed(accountId) {
			return this.navCollapsedGroups.includes(accountId)
		},
		// Total unread across all mailboxes of a shared account group.
		groupUnread(accountId) {
			return this.sharedMailboxes
				.filter(m => m._accountId === accountId)
				.reduce((sum, m) => sum + (m.unread || 0), 0)
		},
		async toggleGroup(accountId) {
			if (this.isGroupCollapsed(accountId)) {
				this.navCollapsedGroups = this.navCollapsedGroups.filter(id => id !== accountId)
			} else {
				this.navCollapsedGroups = [...this.navCollapsedGroups, accountId]
			}
			// Persist per user — fire and forget.
			try {
				
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), { navCollapsedGroups: this.navCollapsedGroups })
			} catch {}
		},
		async onRefreshMailboxes() {
			try {
				this.mailboxes = await fetchMailboxes()
				if (this.sharedFolders.length > 0) {
					const allShared = []
					for (const sh of this.sharedFolders) {
						try { allShared.push(...(await fetchMailboxes(sh.id))) } catch {}
					}
					this.sharedMailboxes = allShared
				}
			} catch(e) { console.error(e) }
		},
		onMailboxSelect(id) {
			this.selectedMailbox = id
			const mb = this.mailboxes.find(m => m.id === id)
			if (mb && mb.role === 'junk') {
				this.$router.push({ name: 'spam' })
			} else {
				this.$router.push({ name: 'inbox' })
			}
		},
		onDropEmail({ emailId, mailboxId, mailbox }) {
			window.dispatchEvent(new CustomEvent('souvera-mail:move-email', {
				detail: { emailId, mailboxId, accountId: mailbox._accountId || null }
			}))
		},
		startCompose() {
			if (this.$route.name !== 'compose') {
				this.$router.push({ name: 'compose' })
			}
		},
		onSharedSelect(accountId, mailboxId) {
			this.selectedMailbox = accountId + '|' + mailboxId
			if (this.$route.name === 'inbox') {
				this.$router.replace({ name: 'inbox', query: { t: String(Date.now()) } })
			} else {
				this.$router.push({ name: 'inbox' })
			}
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
				
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/quota'))
				this.quotaUsed = data.used ?? 0; this.quotaTotal = data.total ?? 0; this.quotaUnlimited = data.unlimited ?? false
			} catch (e) { console.error('Failed to load quota', e) }
		},
		async loadShared() {
			try {
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
				
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'))
				this.isVertical = data.verticalLayout || false
				this.listOnlyLayout = !!data.listOnlyLayout
				this.focusLayout = !!data.focusLayout
				this.navCollapsedGroups = data.navCollapsedGroups || []
				this.mailArchiveEnabled = !!data.mailArchiveEnabled
			} catch (e) { console.error('Failed to load layout pref', e) }
		},
		async loadExternalAccounts() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/external/accounts'))
				this.externalAccounts = data.accounts || []
			} catch (e) { console.error('Failed to load external accounts', e) }
		},
		async toggleExtAccount(acc) {
			if (this.extExpanded[acc.id]) {
				this.extExpanded = { ...this.extExpanded, [acc.id]: false }
				return
			}
			this.extExpanded = { ...this.extExpanded, [acc.id]: true }
			// Load once; guard against parallel fetches on quick toggling.
			if (!this.extFolders[acc.id] && !this.extFoldersLoading[acc.id]) await this.loadExtFolders(acc)
		},
		async loadExtFolders(acc) {
			this.extFoldersLoading = { ...this.extFoldersLoading, [acc.id]: true }
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/external/accounts/' + acc.id + '/folders'))
				this.extFolders = { ...this.extFolders, [acc.id]: (data.ok === false ? [] : (data.folders || [])) }
			} catch (e) {
				this.extFolders = { ...this.extFolders, [acc.id]: [] }
			} finally {
				this.extFoldersLoading = { ...this.extFoldersLoading, [acc.id]: false }
			}
		},
		extUnread(id) {
			return (this.extFolders[id] || []).reduce((sum, f) => sum + (f.unread || 0), 0)
		},
		openExtFolder(acc, f) {
			// Drop any JMAP mailbox selection — external folders live on a
			// different route and must not keep the old mailbox lit.
			this.selectedMailbox = ''
			this.$router.push({ name: 'external', params: { id: acc.id }, query: { folder: f.path } })
		},
	},
}
</script>

<style scoped>
/* The compose row lives in NcAppNavigation's #search slot — OUTSIDE the
   scrollable list area — so it can never scroll away or show a bar. */
.compose-row { display: flex; gap: 4px; margin: 0; padding: 8px var(--app-navigation-padding, 12px) 4px; align-items: center; width: 100%; box-sizing: border-box; }
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
/* "External accounts" header — visually identical to the NcAppNavigationCaption
   sections ("Mailboxes", "Folders", "Shared with me"). The component styles
   are scoped and don't reach a hand-rolled <li>, so they are mirrored here —
   including the section spacing (margin-top). */
:deep(.ext-caption) {
	display: flex;
	justify-content: space-between;
	margin-top: calc(var(--default-clickable-area, 44px) / 2);
}
:deep(.ext-caption .app-navigation-caption__name) {
	font-weight: var(--font-weight-heading, bold);
	color: var(--color-main-text);
	font-size: var(--default-font-size);
	line-height: var(--default-clickable-area, 44px);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	box-shadow: none !important;
	flex-shrink: 0;
	padding-inline: calc(var(--default-grid-baseline, 4px) * 2) 0;
	margin-top: 0;
	margin-bottom: var(--default-grid-baseline, 4px);
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
.ext-caption__icon {
	color: var(--color-text-maxcontrast);
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

/* Collapsible account group headers — visually distinct from mailbox
   entries: bold name, subtle background. */
:deep(.nav-group-toggle .app-navigation-entry__title) {
	font-weight: 700 !important;
	font-size: 13px !important;
	letter-spacing: 0.3px;
}
:deep(.nav-group-toggle:not(.active) .app-navigation-entry) {
	background: var(--color-background-hover) !important;
	border-radius: var(--border-radius);
	margin: 2px 6px;
}
:deep(.nav-group-toggle .app-navigation-entry) {
	border-radius: var(--border-radius);
	margin: 2px 6px;
}
:deep(.nav-group-toggle .app-navigation-entry__icon-wrapper) {
	opacity: 0.8;
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
