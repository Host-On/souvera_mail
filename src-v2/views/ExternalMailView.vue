<template>
	<div class="ext-view">
		<div class="ext-view__toolbar">
			<h2 class="ext-view__title">
				{{ account ? account.email : t('souvera_mail', 'External account') }}
				<span v-if="activeFolder" class="ext-view__folder">/ {{ activeFolder }}</span>
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
				<NcEmptyContent v-else-if="messages.length === 0"
					:name="t('souvera_mail', 'No messages')" />
				<div v-else>
					<div v-for="m in messages" :key="m.uid"
						class="ext-msg"
						:class="{ 'ext-msg--active': activeMessage && activeMessage.uid === m.uid, 'ext-msg--unread': !m.seen }"
						@click="openMessage(m)">
						<div class="ext-msg__top">
							<span class="ext-msg__from">{{ m.from }}</span>
							<span class="ext-msg__date">{{ fmtDate(m.date) }}</span>
						</div>
						<div class="ext-msg__subject">{{ m.subject || t('souvera_mail', '(No subject)') }}</div>
					</div>
					<div class="ext-view__pager" v-if="total > messages.length">
						<NcButton variant="tertiary" :disabled="offset === 0" @click="prevPage">{{ t('souvera_mail', 'Newer') }}</NcButton>
						<NcButton variant="tertiary" :disabled="offset + messages.length >= total" @click="nextPage">{{ t('souvera_mail', 'Older') }}</NcButton>
					</div>
				</div>
			</div>

			<div class="ext-view__detail" v-if="activeMessage">
				<div class="ext-detail__header">
					<h3 class="ext-detail__subject">{{ activeMessage.subject || t('souvera_mail', '(No subject)') }}</h3>
					<div class="ext-detail__meta">
						<span class="ext-detail__from">{{ activeMessage.fromName }} &lt;{{ activeMessage.fromAddress }}&gt;</span>
						<span class="ext-detail__date">{{ fmtDate(activeMessage.date) }}</span>
					</div>
				</div>
				<div class="ext-detail__body">
					<HtmlMailFrame v-if="displayHtml" :html="displayHtml" />
					<pre v-else class="ext-detail__plain">{{ messagePlain }}</pre>
				</div>
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
import HtmlMailFrame from '../components/HtmlMailFrame.vue'
import { sanitizeMailHtml } from '../utils/mailSanitizer.js'

export default {
	name: 'ExternalMailView',
	components: { NcButton, NcEmptyContent, Pencil, HtmlMailFrame },
	data() {
		return {
			accounts: [],
			account: null,
			loadError: '',
			activeFolder: '',
			messages: [],
			loadingMessages: false,
			total: 0,
			offset: 0,
			limit: 50,
			activeMessage: null,
			displayHtml: '',
			messagePlain: '',
		}
	},
	computed: {
		accountId() { return String(this.$route.params.id || '') },
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
			this.activeMessage = null
			this.displayHtml = ''
			this.messagePlain = ''
			this.loadingMessages = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/external/accounts/' + this.accountId + '/messages'), {
					params: { folder: this.activeFolder, offset: this.offset, limit: this.limit },
				})
				if (data.ok === false) {
					this.loadError = data.error || this.t('souvera_mail', 'Failed to load messages')
					return
				}
				this.messages = data.messages || []
				this.total = data.total || 0
			} catch (e) {
				this.loadError = e?.response?.data?.error || this.t('souvera_mail', 'Failed to load messages')
			} finally {
				this.loadingMessages = false
			}
		},
		async openMessage(m) {
			this.activeMessage = m
			this.displayHtml = ''
			this.messagePlain = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/external/accounts/' + this.accountId + '/message/' + m.uid), {
					params: { folder: this.activeFolder },
				})
				if (data.ok === false) {
					this.messagePlain = data.error || this.t('souvera_mail', 'Failed to load message')
					return
				}
				const msg = data.message || {}
				this.activeMessage = {
					...m,
					subject: msg.subject || m.subject,
					fromName: msg.fromName || '',
					fromAddress: msg.fromAddress || m.from || '',
					date: msg.date || m.date,
				}
				const raw = msg.html || ''
				if (raw) {
					const { html } = sanitizeMailHtml(raw, { blockRemote: false })
					this.displayHtml = html
				} else {
					this.messagePlain = this.t('souvera_mail', '(Empty message)')
				}
			} catch (e) {
				this.messagePlain = this.t('souvera_mail', 'Failed to load message')
			}
		},
		nextPage() { this.offset += this.limit; this.loadMessages() },
		prevPage() { this.offset = Math.max(0, this.offset - this.limit); this.loadMessages() },
		compose() {
			this.$router.push({ name: 'compose', query: { ext: this.accountId } })
		},
		fmtDate(ts) {
			if (!ts) return ''
			const d = new Date(ts * 1000)
			return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
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
.ext-view__body { flex: 1; display: grid; grid-template-columns: 320px 1fr; min-height: 0; }
.ext-view__list { overflow-y: auto; border-right: 1px solid var(--color-border); }
.ext-view__loading { padding: 16px; color: var(--color-text-maxcontrast); }
.ext-msg { padding: 10px 12px; border-bottom: 1px solid var(--color-border); cursor: pointer; }
.ext-msg:hover { background: var(--color-background-hover); }
.ext-msg--active { background: var(--color-primary-element-light); }
.ext-msg--unread .ext-msg__from, .ext-msg--unread .ext-msg__subject { font-weight: 700; }
.ext-msg__top { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; color: var(--color-text-maxcontrast); }
.ext-msg__from { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ext-msg__subject { font-size: 13px; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ext-view__pager { display: flex; justify-content: center; gap: 8px; padding: 12px; }
.ext-view__detail { overflow-y: auto; padding: 16px; }
.ext-detail__header { border-bottom: 1px solid var(--color-border); padding-bottom: 10px; margin-bottom: 12px; }
.ext-detail__subject { margin: 0 0 6px; }
.ext-detail__meta { display: flex; flex-direction: column; gap: 2px; font-size: 12px; color: var(--color-text-maxcontrast); }
.ext-detail__body { font-size: 14px; line-height: 1.5; }
.ext-detail__plain { white-space: pre-wrap; word-break: break-word; margin: 0; }
@media (max-width: 900px) {
	.ext-view__body { grid-template-columns: 1fr; }
	.ext-view__detail { grid-column: 1 / -1; }
}
</style>
