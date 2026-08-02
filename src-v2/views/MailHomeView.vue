<template>
	<div class="mail-home">
		<MailboxSidebar
			:mailboxes="mailboxes"
			:selected="selectedMailbox"
			:loading="loadingMailboxes"
			@select="onSelectMailbox" />

		<div class="mail-content" v-if="!selectedEmail">
			<div v-if="loadingEmails" class="mail-loading">
				<span class="icon-loading" />
				<p>{{ t('souvera_mail', 'Loading...') }}</p>
			</div>

			<template v-else-if="emails.length > 0">
				<div class="mail-toolbar">
					<NcButton type="tertiary" @click="refreshEmails">
						<template #icon><Refresh :size="20" /></template>
					</NcButton>
					<NcButton type="primary" @click="goCompose">
						<template #icon><Pencil :size="20" /></template>
						{{ t('souvera_mail', 'New') }}
					</NcButton>
				</div>
				<EmailList
					:emails="emails"
					:total="emailTotal"
					:offset="offset"
					:limit="limit"
					@prev="goPrev"
					@next="goNext"
					@open="onOpenEmail" />
			</template>

			<NcEmptyContent v-else :title="t('souvera_mail', 'No messages')">
				<template #icon><EmailOutline :size="64" /></template>
			</NcEmptyContent>

			<div v-if="errorMsg" class="mail-error">{{ errorMsg }}</div>
		</div>

		<EmailDetail
			v-else
			:email="selectedEmail"
			:html-body="emailBodyHtml"
			:plain-body="emailBodyPlain"
			:loading="loadingBody"
			@close="selectedEmail = null"
			@reply="onReply"
			@forward="onForward"
			@flag="toggleFlag"
			@delete="deleteEmail" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import { useJmapClient } from '../composables/useJmapClient.js'
import MailboxSidebar from '../components/MailboxSidebar.vue'
import EmailList from '../components/EmailList.vue'
import EmailDetail from '../components/EmailDetail.vue'

const { fetchMailboxes, fetchEmails, fetchEmailBody, toggleEmailFlag, deleteEmailApi } = useJmapClient()

export default {
	name: 'MailHomeView',
	components: { MailboxSidebar, EmailList, EmailDetail, NcButton, NcEmptyContent, Refresh, Pencil, EmailOutline },
		data() {
		return {
			mailboxes: [],
			selectedMailbox: '',
			emails: [],
			emailTotal: 0,
			offset: 0,
			limit: 50,
			loadingMailboxes: true,
			loadingEmails: false,
			errorMsg: '',
			selectedEmail: null,
			emailBodyHtml: '',
			emailBodyPlain: '',
			loadingBody: false,
		}
	},
	async mounted() {
		await this.loadMailboxes()
	},
	methods: {
		async loadMailboxes() {
			this.loadingMailboxes = true
			try {
				this.mailboxes = await fetchMailboxes()
				const inbox = this.mailboxes.find(m => m.role === 'inbox') || this.mailboxes[0]
				if (inbox) {
					this.selectedMailbox = inbox.id
					await this.loadEmails()
				}
			} catch (e) {
				this.errorMsg = 'Could not load mailboxes: ' + (e.response?.data?.error || e.message || e)
			} finally {
				this.loadingMailboxes = false
			}
		},
		async loadEmails() {
			this.loadingEmails = true
			this.errorMsg = ''
			try {
				const result = await fetchEmails(this.selectedMailbox, this.limit, this.offset)
				this.emails = result.emails
				this.emailTotal = result.total
			} catch (e) {
				this.errorMsg = 'Could not load emails'
			} finally {
				this.loadingEmails = false
			}
		},
		async refreshEmails() {
			this.offset = 0
			await this.loadEmails()
		},
		onSelectMailbox(mailboxId) {
			this.selectedMailbox = mailboxId
			this.selectedEmail = null
			this.offset = 0
			this.loadEmails()
		},
		async onOpenEmail(email) {
			this.selectedEmail = email
			this.emailBodyHtml = ''
			this.emailBodyPlain = ''
			this.loadingBody = true
			try {
				const body = await fetchEmailBody(email.id)
				this.emailBodyHtml = body.htmlBody || ''
				this.emailBodyPlain = body.plainBody || ''
				this.selectedEmail = { ...email, ...body }
			} catch (e) {
				this.errorMsg = 'Could not load email body'
			} finally {
				this.loadingBody = false
			}
		},
		goCompose() {
			this.$router.push({ name: 'compose' })
		},
		onReply() {
			const data = { fromAddress: this.selectedEmail.fromAddress, subject: this.selectedEmail.subject, messageId: this.selectedEmail.messageId }
			this.$router.push({ name: 'compose', query: { reply: JSON.stringify(data) } })
		},
		onForward() {
			const data = { fromAddress: this.selectedEmail.fromAddress, subject: this.selectedEmail.subject, messageId: this.selectedEmail.messageId }
			this.$router.push({ name: 'compose', query: { forward: JSON.stringify(data) } })
		},
		async toggleFlag() {
			if (!this.selectedEmail) return
			const newState = !this.selectedEmail.isFlagged
			try {
				await toggleEmailFlag(this.selectedEmail.id, newState)
				this.selectedEmail.isFlagged = newState
			} catch (e) { this.errorMsg = 'Flag toggle failed' }
		},
		async deleteEmail() {
			if (!this.selectedEmail) return
			try {
				await deleteEmailApi(this.selectedEmail.id)
				this.selectedEmail = null
				await this.refreshEmails()
			} catch (e) { this.errorMsg = 'Delete failed' }
		},
		goPrev() {
			if (this.offset <= 0) return
			this.offset = Math.max(0, this.offset - this.limit)
			this.loadEmails()
		},
		goNext() {
			if (this.offset + this.limit >= this.emailTotal) return
			this.offset += this.limit
			this.loadEmails()
		},
	},
}
</script>

<style scoped>
.mail-home { display: flex; height: 100%; overflow: hidden; }
.mail-content { flex: 1; overflow-y: auto; padding: 16px; }
.mail-loading { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 48px; color: var(--color-text-maxcontrast); }
.mail-error { background: var(--color-error); color: var(--color-error-text); padding: 8px 16px; border-radius: 4px; margin-top: 8px; }
.mail-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
</style>
