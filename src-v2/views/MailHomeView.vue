<template>
	<div class="mail-home">
		<MailboxSidebar
			:mailboxes="mailboxes"
			:selected="selectedMailbox"
			:loading="loading"
			@select="onSelectMailbox" />

		<div class="mail-content">
			<div v-if="loading" class="mail-loading">
				<span class="icon-loading" />
				<p>{{ t('souvera_mail', 'Loading...') }}</p>
			</div>

			<EmailList
				v-else-if="emails.length > 0"
				:emails="emails"
				:total="emailTotal"
				:offset="offset"
				:limit="limit"
				@prev="goPrev"
				@next="goNext" />

			<NcEmptyContent
				v-else
				:title="t('souvera_mail', 'No messages')">
				<template #icon>
					<EmailOutline :size="64" />
				</template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import { useJmapClient } from '../composables/useJmapClient.js'
import MailboxSidebar from '../components/MailboxSidebar.vue'
import EmailList from '../components/EmailList.vue'

const { fetchMailboxes, fetchEmails } = useJmapClient()

export default {
	name: 'MailHomeView',
	components: { MailboxSidebar, EmailList, NcEmptyContent, EmailOutline },
	data() {
		return {
			mailboxes: [],
			selectedMailbox: '',
			emails: [],
			emailTotal: 0,
			offset: 0,
			limit: 50,
			loading: true,
		}
	},
	async mounted() {
		this.loading = true
		try {
			this.mailboxes = await fetchMailboxes()
			const inbox = this.mailboxes.find(m => m.role === 'inbox') || this.mailboxes[0]
			if (inbox) {
				this.selectedMailbox = inbox.id
				await this.loadEmails()
			}
		} catch (e) {
			console.error('MailHomeView: load failed', e)
		} finally {
			this.loading = false
		}
	},
	methods: {
		async onSelectMailbox(mailboxId) {
			this.selectedMailbox = mailboxId
			this.offset = 0
			await this.loadEmails()
		},
		async loadEmails() {
			this.loading = true
			try {
				const result = await fetchEmails(this.selectedMailbox, this.limit, this.offset)
				this.emails = result.emails
				this.emailTotal = result.total
			} catch (e) {
				console.error('Failed to load emails', e)
			} finally {
				this.loading = false
			}
		},
		async goPrev() {
			if (this.offset <= 0) return
			this.offset = Math.max(0, this.offset - this.limit)
			await this.loadEmails()
		},
		async goNext() {
			if (this.offset + this.limit >= this.emailTotal) return
			this.offset += this.limit
			await this.loadEmails()
		},
	},
}
</script>

<style scoped>
.mail-home { display: flex; height: 100%; overflow: hidden; }
.mail-content { flex: 1; overflow-y: auto; padding: 16px; }
.mail-loading { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 48px; color: var(--color-text-maxcontrast); }
</style>
