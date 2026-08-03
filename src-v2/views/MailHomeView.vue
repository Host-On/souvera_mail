<template>
	<div class="mail-home">
		<div class="mail-list-panel" :style="{ width: listWidth }">
			<EmailListToolbar
				:selected-count="checkedIds.length"
				:select-all-state="selectAllState"
				:target-mailboxes="moveMailboxes"
				@refresh="refreshEmails"
				@compose="$router.push({name:'compose'})"
				@mark-read="bulkMarkRead"
				@mark-unread="bulkMarkUnread"
				@bulk-delete="bulkDelete"
				@move-to="bulkMoveTo"
				@toggle-select-all="toggleSelectAll" />

			<EmailListSkeleton v-if="loadingEmails" />
			<template v-else-if="emails.length > 0">
				<div class="email-items">
					<EmailListItem
						v-for="email in emails"
						:key="email.id"
						:email="email"
						:active="selectedEmail?.id === email.id"
						:checked="checkedIds.includes(email.id)"
						:selection-mode="checkedIds.length > 0"
						@click="onOpenEmail(email)"
						@check="toggleCheck(email.id)"
						@flag="toggleFlag(email.id)" />
				</div>
				<PaginationBar
					:offset="offset"
					:limit="limit"
					:total="emailTotal"
					@prev="goPrev"
					@next="goNext" />
			</template>

			<NcEmptyContent v-else :name="t('souvera_mail', 'No messages')">
				<template #icon><EmailOutline :size="64" /></template>
				<template #action>
					<NcButton variant="primary" @click="$router.push({name:'compose'})">
						{{ t('souvera_mail', 'New message') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<div v-if="selectedEmail" class="mail-detail-panel">
			<EmailDetail
				:email="selectedEmail"
				:html-body="emailBodyHtml"
				:plain-body="emailBodyPlain"
				:loading="loadingBody"
				:mailboxes="allMailboxes"
				@close="selectedEmail = null"
				@reply="onReply"
				@reply-all="onReplyAll"
				@forward="onForward"
				@move="onMove"
				@delete="deleteEmail"
				@mailto="onMailto" />
		</div>

		<NcEmptyContent v-else :name="t('souvera_mail', 'Select a message')"
			class="mail-detail-empty">
			<template #icon><EmailOutline :size="64" /></template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcEmptyContent, NcButton } from '@nextcloud/vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import { useJmapClient } from '../composables/useJmapClient.js'
import EmailListToolbar from '../components/EmailListToolbar.vue'
import EmailListItem from '../components/EmailListItem.vue'
import EmailListSkeleton from '../components/EmailListSkeleton.vue'
import PaginationBar from '../components/PaginationBar.vue'
import EmailDetail from '../components/EmailDetail.vue'
import { useHotkeys } from '../composables/useHotkeys.js'

const { fetchEmails, fetchEmailBody, deleteEmailApi, moveEmail, markEmailRead, toggleEmailFlag } = useJmapClient()

export default {
	name: 'MailHomeView',
	components: { EmailListToolbar, EmailListItem, EmailListSkeleton, PaginationBar, EmailDetail, NcEmptyContent, NcButton, EmailOutline },
	props: {
		selectedMailbox: { type: String, default: '' },
		allMailboxes: { type: Array, default: () => [] },
	},
	data() {
		return {
			emails: [], emailTotal: 0, offset: 0, limit: 50,
			loadingEmails: false, loadingBody: false,
			selectedEmail: null,
			emailBodyHtml: '', emailBodyPlain: '',
			listWidth: 'clamp(320px, 33%, 460px)',
			checkedIds: [],
		}
	},
	computed: {
		selectAllState() {
			if (this.checkedIds.length === 0) return false
			if (this.checkedIds.length === this.emails.length) return true
			return 'indeterminate'
		},
		moveMailboxes() {
			return this.allMailboxes.filter(m => m.role !== 'trash' && m.role !== 'junk')
		},
	},
	watch: {
		selectedMailbox() { this.checkedIds = []; this.offset = 0; this.selectedEmail = null; this.loadEmails() },
	},
	async mounted() {
		if (this.selectedMailbox) await this.loadEmails()
		this._hotkeys = useHotkeys({
			k: () => this.navigateEmail(1),
			j: () => this.navigateEmail(-1),
			r: () => { if (this.selectedEmail) this.onReply() },
			a: () => { if (this.selectedEmail) this.onReplyAll() },
			f: () => { if (this.selectedEmail) this.onForward() },
			Delete: () => { if (this.selectedEmail) this.deleteEmail() },
			Escape: () => { this.selectedEmail = null; this.checkedIds = [] },
		})
	},
	beforeUnmount() {
		this._hotkeys?.destroy()
	},
	methods: {
		async loadEmails() {
			this.loadingEmails = true
			try { const r = await fetchEmails(this.selectedMailbox, this.limit, this.offset); this.emails = r.emails; this.emailTotal = r.total } catch (e) { console.error('Failed to load emails', e) } finally { this.loadingEmails = false }
		},
		async refreshEmails() { this.checkedIds = []; this.offset = 0; await this.loadEmails() },
		toggleCheck(id) {
			const idx = this.checkedIds.indexOf(id)
			if (idx >= 0) this.checkedIds.splice(idx, 1)
			else this.checkedIds.push(id)
		},
		toggleSelectAll() {
			if (this.selectAllState === true) this.checkedIds = []
			else this.checkedIds = this.emails.map(e => e.id)
		},
		async bulkMarkRead() {
			for (const id of this.checkedIds) {
				try { await markEmailRead(id, true) } catch (e) { console.error('Failed to mark read', e) }
			}
			this.checkedIds = []
			await this.loadEmails()
		},
		async bulkMarkUnread() {
			for (const id of this.checkedIds) {
				try { await markEmailRead(id, false) } catch (e) { console.error('Failed to mark unread', e) }
			}
			this.checkedIds = []
			await this.loadEmails()
		},
		async bulkDelete() {
			for (const id of this.checkedIds) {
				try { await deleteEmailApi(id) } catch (e) { console.error('Failed to delete', e) }
			}
			this.checkedIds = []
			await this.loadEmails()
		},
		async bulkMoveTo(mailboxId) {
			for (const id of this.checkedIds) {
				try { await moveEmail(id, mailboxId) } catch (e) { console.error('Failed to move', e) }
			}
			this.checkedIds = []
			await this.loadEmails()
		},
		async onOpenEmail(email) {
			this.selectedEmail = email
			this.emailBodyHtml = ''; this.emailBodyPlain = ''; this.loadingBody = true
			try {
				const body = await fetchEmailBody(email.id)
				this.emailBodyHtml = body.htmlBody || ''; this.emailBodyPlain = body.plainBody || ''
				this.selectedEmail = { ...email, ...body }
				if (!email.isRead) {
					await markEmailRead(email.id, true)
					const listItem = this.emails.find(e => e.id === email.id)
					if (listItem) listItem.isRead = true
				}
			} catch (e) { console.error('Failed to open email', e) } finally { this.loadingBody = false }
		},
		onReply() {
			this.$router.push({ name: 'compose', query: { mode: 'reply', id: this.selectedEmail.id } })
		},
		onReplyAll() {
			this.$router.push({ name: 'compose', query: { mode: 'replyAll', id: this.selectedEmail.id } })
		},
		onForward() {
			this.$router.push({ name: 'compose', query: { mode: 'forward', id: this.selectedEmail.id } })
		},
		onMailto(event) {
			this.$router.push({ name: 'compose', query: { to: event.to } })
		},
		async deleteEmail() {
			if (!this.selectedEmail) return
			try { await deleteEmailApi(this.selectedEmail.id); this.selectedEmail = null; await this.refreshEmails() } catch (e) { console.error('Failed to delete email', e) }
		},
		async onMove(mailboxId) {
			if (!this.selectedEmail) return
			try { await moveEmail(this.selectedEmail.id, mailboxId); this.selectedEmail = null; await this.refreshEmails() } catch (e) { console.error('Failed to move email', e) }
		},
		async toggleFlag(emailId) {
			const email = this.emails.find(e => e.id === emailId)
			if (!email) return
			const newFlag = !email.isFlagged
			email.isFlagged = newFlag
			try { await toggleEmailFlag(emailId, newFlag) } catch (e) { console.error('Failed to toggle flag', e); email.isFlagged = !newFlag }
		},
		goPrev() { if (this.offset > 0) { this.offset = Math.max(0, this.offset - this.limit); this.loadEmails() } },
		goNext() { if (this.offset + this.limit < this.emailTotal) { this.offset += this.limit; this.loadEmails() } },
		navigateEmail(dir) {
			if (!this.selectedEmail || this.emails.length === 0) return
			const idx = this.emails.findIndex(e => e.id === this.selectedEmail.id)
			const next = Math.max(0, Math.min(this.emails.length - 1, idx + dir))
			if (this.emails[next]) this.onOpenEmail(this.emails[next])
		},
	},
}
</script>

<style scoped>
.mail-home { display: flex; height: 100%; overflow: hidden; }
.mail-list-panel { flex-shrink: 0; overflow-y: auto; border-right: 1px solid var(--color-border); display: flex; flex-direction: column; }
.mail-detail-panel { flex: 1; overflow-y: auto; }
.mail-detail-empty { flex: 1; }
.mail-loading { display: flex; justify-content: center; padding: 48px; }
.email-items { flex: 1; overflow-y: auto; }
</style>
