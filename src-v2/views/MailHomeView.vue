<template>
	<div class="mail-home">
		<div class="mail-list-panel" :style="{ width: listWidth }">
			<EmailListToolbar
				:selected-count="0"
				@refresh="refreshEmails"
				@compose="$router.push({name:'compose'})" />

			<div v-if="loadingEmails" class="mail-loading">
				<span class="icon-loading" />
			</div>

			<template v-else-if="emails.length > 0">
				<div class="email-items">
					<EmailListItem
						v-for="email in emails"
						:key="email.id"
						:email="email"
						:active="selectedEmail?.id === email.id"
						@click="onOpenEmail(email)" />
				</div>
				<PaginationBar
					:offset="offset"
					:limit="limit"
					:total="emailTotal"
					@prev="goPrev"
					@next="goNext" />
			</template>

			<NcEmptyContent v-else :title="t('souvera_mail', 'No messages')">
				<template #icon><EmailOutline :size="64" /></template>
			</NcEmptyContent>
		</div>

		<div v-if="selectedEmail" class="mail-detail-panel">
			<EmailDetail
				:email="selectedEmail"
				:html-body="emailBodyHtml"
				:plain-body="emailBodyPlain"
				:loading="loadingBody"
				@close="selectedEmail = null"
				@reply="onReply"
				@forward="onForward"
				@delete="deleteEmail" />
		</div>

		<NcEmptyContent v-else :title="t('souvera_mail', 'Select a message')"
			class="mail-detail-empty">
			<template #icon><EmailOutline :size="64" /></template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import { useJmapClient } from '../composables/useJmapClient.js'
import EmailListToolbar from '../components/EmailListToolbar.vue'
import EmailListItem from '../components/EmailListItem.vue'
import PaginationBar from '../components/PaginationBar.vue'
import EmailDetail from '../components/EmailDetail.vue'

const { fetchEmails, fetchEmailBody, deleteEmailApi, markEmailRead } = useJmapClient()

export default {
	name: 'MailHomeView',
	components: { EmailListToolbar, EmailListItem, PaginationBar, EmailDetail, NcEmptyContent, EmailOutline },
	props: {
		selectedMailbox: { type: String, default: '' },
	},
	data() {
		return {
			emails: [], emailTotal: 0, offset: 0, limit: 50,
			loadingEmails: false, loadingBody: false,
			selectedEmail: null,
			emailBodyHtml: '', emailBodyPlain: '',
			listWidth: '420px',
		}
	},
	watch: {
		selectedMailbox() { this.offset = 0; this.selectedEmail = null; this.loadEmails() },
	},
	async mounted() {
		if (this.selectedMailbox) await this.loadEmails()
	},
	methods: {
		async loadEmails() {
			this.loadingEmails = true
			try {
				const r = await fetchEmails(this.selectedMailbox, this.limit, this.offset)
				this.emails = r.emails; this.emailTotal = r.total
			} catch {} finally { this.loadingEmails = false }
		},
		async refreshEmails() { this.offset = 0; await this.loadEmails() },
		async onOpenEmail(email) {
			this.selectedEmail = email
			this.emailBodyHtml = ''; this.emailBodyPlain = ''; this.loadingBody = true
			try {
				const body = await fetchEmailBody(email.id)
				this.emailBodyHtml = body.htmlBody || ''
				this.emailBodyPlain = body.plainBody || ''
				this.selectedEmail = { ...email, ...body }
				await markEmailRead(email.id, true)
			} catch {} finally { this.loadingBody = false }
		},
		onReply() {
			const d = { fromAddress: this.selectedEmail.fromAddress, subject: this.selectedEmail.subject, messageId: this.selectedEmail.messageId }
			this.$router.push({ name: 'compose', query: { reply: JSON.stringify(d) } })
		},
		onForward() {
			const d = { fromAddress: this.selectedEmail.fromAddress, subject: this.selectedEmail.subject, messageId: this.selectedEmail.messageId }
			this.$router.push({ name: 'compose', query: { forward: JSON.stringify(d) } })
		},
		async deleteEmail() {
			if (!this.selectedEmail) return
			try {
				await deleteEmailApi(this.selectedEmail.id)
				this.selectedEmail = null; await this.refreshEmails()
			} catch {}
		},
		goPrev() { if (this.offset > 0) { this.offset = Math.max(0, this.offset - this.limit); this.loadEmails() } },
		goNext() { if (this.offset + this.limit < this.emailTotal) { this.offset += this.limit; this.loadEmails() } },
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
