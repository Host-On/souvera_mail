<template>
	<div class="ext-view">
		<div class="ext-view__toolbar">
			<h2 class="ext-view__title">
				{{ account ? account.email : t('souvera_mail', 'External account') }}
				<span v-if="activeFolder" class="ext-view__folder">/ {{ activeFolderLabel }}</span>
			</h2>
			<NcButton variant="primary" @click="compose">
				<template #icon><Pencil :size="18" /></template>
				{{ t('souvera_mail', 'New message') }}
			</NcButton>
		</div>

		<div v-if="loadError" class="ext-view__error">
			<NcEmptyContent :name="loadError">
				<template #action>
					<NcButton variant="primary" @click="loadMessages">{{ t('souvera_mail', 'Retry') }}</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="ext-view__body">
			<div class="ext-view__list">
				<div v-if="loadingMessages" class="ext-view__loading">{{ t('souvera_mail', 'Loading…') }}</div>
				<NcEmptyContent v-else-if="emails.length === 0"
					:name="t('souvera_mail', 'No messages')" />
				<div v-else>
					<EmailListItem
						v-for="m in emails"
						:key="m.id"
						:email="m"
						:active="activeEmail && activeEmail.id === m.id"
						@click="openMessage(m)" />
					<div class="ext-view__pager" v-if="total > emails.length">
						<NcButton variant="tertiary" :disabled="offset === 0" @click="prevPage">{{ t('souvera_mail', 'Newer') }}</NcButton>
						<NcButton variant="tertiary" :disabled="offset + emails.length >= total" @click="nextPage">{{ t('souvera_mail', 'Older') }}</NcButton>
					</div>
				</div>
			</div>

			<div class="ext-view__detail" v-if="activeEmail">
				<EmailDetail
					:email="activeEmail"
					:html-body="activeEmailHtml"
					:loading="loadingMessage"
					readonly
					@close="closeMessage"
					@reply="replyTo"
					@reply-all="replyTo(true)"
					@forward="forwardMessage" />
			</div>
			<div class="ext-view__detail" v-else>
				<NcEmptyContent :name="t('souvera_mail', 'Select a message')" />
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import EmailListItem from '../components/EmailListItem.vue'
import EmailDetail from '../components/EmailDetail.vue'
import { sanitizeMailHtml } from '../utils/mailSanitizer.js'
import { extFolderDisplayName } from '../utils/mailboxNames.js'

export default {
	name: 'ExternalMailView',
	components: { NcButton, NcEmptyContent, Pencil, EmailListItem, EmailDetail },
	data() {
		return {
			accounts: [],
			account: null,
			loadError: '',
			activeFolder: '',
			emails: [],
			loadingMessages: false,
			total: 0,
			offset: 0,
			limit: 50,
			activeEmail: null,
			activeEmailHtml: '',
			loadingMessage: false,
		}
	},
	computed: {
		accountId() { return String(this.$route.params.id || '') },
		activeFolderLabel() {
			return extFolderDisplayName({ path: this.activeFolder, name: this.activeFolder })
		},
	},
	async mounted() {
		await this.loadAccounts()
	},
	methods: {
		async loadAccounts() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/external/accounts'))
				this.accounts = data.accounts || []
				this.account = this.accounts.find(a => String(a.id) === this.accountId) || null
				if (this.account) await this.loadMessages()
				else this.loadError = this.t('souvera_mail', 'Account not found')
			} catch (e) {
				this.loadError = e?.response?.data?.error || this.t('souvera_mail', 'Failed to load account')
			}
		},
		async loadMessages() {
			this.loadError = ''
			this.activeFolder = this.$route.query.folder || 'INBOX'
			this.closeMessage()
			this.loadingMessages = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/external/accounts/' + this.accountId + '/messages'), {
					params: { folder: this.activeFolder, offset: this.offset, limit: this.limit },
				})
				if (data.ok === false) {
					this.loadError = data.error || this.t('souvera_mail', 'Failed to load messages')
					return
				}
				this.emails = (data.messages || []).map(m => this.mapEmail(m))
				this.total = data.total || 0
			} catch (e) {
				this.loadError = e?.response?.data?.error || this.t('souvera_mail', 'Failed to load messages')
			} finally {
				this.loadingMessages = false
			}
		},
		// External message → the shape EmailListItem/EmailDetail expect.
		mapEmail(m) {
			return {
				id: 'ext-' + this.accountId + '-' + this.activeFolder + '-' + m.uid,
				uid: m.uid,
				subject: m.subject,
				fromName: m.from.split('<')[0].trim(),
				fromAddress: m.from.match(/<([^>]+)>/)?.[1] || m.from,
				receivedAt: m.date ? m.date * 1000 : Date.now(),
				isRead: !!m.seen,
				isFlagged: !!m.flagged,
				hasAttachment: false,
				preview: '',
				attachments: [],
			}
		},
		async openMessage(m) {
			this.activeEmail = m
			this.activeEmailHtml = ''
			this.loadingMessage = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/external/accounts/' + this.accountId + '/message/' + m.uid), {
					params: { folder: this.activeFolder },
				})
				if (data.ok === false) {
					this.activeEmail = { ...m, subject: data.error || this.t('souvera_mail', 'Failed to load message'), receivedAt: m.receivedAt }
					return
				}
				const msg = data.message || {}
				const raw = msg.html || ''
				if (raw) {
					const { html } = sanitizeMailHtml(raw, { blockRemote: false })
					this.activeEmailHtml = html
				}
			} catch (e) {
				this.activeEmailHtml = ''
			} finally {
				this.loadingMessage = false
			}
		},
		closeMessage() {
			this.activeEmail = null
			this.activeEmailHtml = ''
		},
		nextPage() { this.offset += this.limit; this.loadMessages() },
		prevPage() { this.offset = Math.max(0, this.offset - this.limit); this.loadMessages() },
		compose() {
			this.$router.push({ name: 'compose', query: { ext: this.accountId } })
		},
		replyTo(replyAll = false) {
			const e = this.activeEmail
			if (!e) return
			this.$router.push({
				name: 'compose',
				query: {
					ext: this.accountId,
					mode: replyAll ? 'replyAll' : 'reply',
					reply: JSON.stringify({
						fromName: e.fromName,
						fromAddress: e.fromAddress,
						subject: e.subject,
						messageId: '',
						htmlBody: '',
						plainBody: '',
						toList: [],
						ccList: [],
					}),
				},
			})
		},
		forwardMessage() {
			const e = this.activeEmail
			if (!e) return
			this.$router.push({
				name: 'compose',
				query: {
					ext: this.accountId,
					mode: 'forward',
					forward: JSON.stringify({
						fromName: e.fromName,
						fromAddress: e.fromAddress,
						subject: e.subject,
						messageId: '',
						htmlBody: '',
						plainBody: '',
						// The external mail body is not quoted; do not
						// prefill the recipient with the original sender.
						noPrefillRecipients: true,
					}),
				},
			})
		},
	},
}
</script>

<style scoped>
.ext-view { display: flex; flex-direction: column; height: 100%; }
.ext-view__toolbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--color-border); }
.ext-view__title { margin: 0; font-size: 18px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ext-view__folder { font-size: 13px; color: var(--color-text-maxcontrast); font-weight: 400; }
.ext-view__error { padding: 24px; }
.ext-view__body { flex: 1; display: grid; grid-template-columns: minmax(320px, 40%) 1fr; min-height: 0; }
.ext-view__list { overflow-y: auto; border-right: 1px solid var(--color-border); }
.ext-view__loading { padding: 16px; color: var(--color-text-maxcontrast); }
.ext-view__pager { display: flex; justify-content: center; gap: 8px; padding: 12px; }
.ext-view__detail { overflow-y: auto; padding: 0; }
</style>
