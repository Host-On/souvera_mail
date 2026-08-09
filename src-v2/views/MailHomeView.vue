<template>
	<div class="mail-home" :class="{ 'mail-home--vertical': verticalLayout }">
		<div class="mail-list-panel" :style="panelStyle">
			<EmailListToolbar
				:selected-count="checkedIds.length"
				:select-all-state="selectAllState"
				:target-mailboxes="moveMailboxes"
				:is-trash="isTrashMailbox"
				@refresh="onRefresh"
				@compose="$router.push({name:'compose'})"
				@mark-read="bulkMarkRead"
				@mark-unread="bulkMarkUnread"
				@bulk-delete="bulkDelete"
				@move-to="bulkMoveTo"
				@toggle-select-all="toggleSelectAll"
				@select-all="selectAll"
				@mark-all-read="markAllRead"
				@mark-all-unread="markAllUnread"
				@empty-trash="emptyTrash"
				:search-query="searchQuery"
				:filter="filterType"
				:two-row="!verticalLayout"
				:refresh-countdown="refreshCountdown"
				:refresh-total="_refreshInterval"
				:loading-bulk="bulkProcessing"
				@update:search="onSearch"
				@update:filter="onFilter" />
			<EmailListSkeleton v-if="loadingEmails" />
			<template v-else-if="emails.length > 0">
				<div class="email-items">
					<EmailListItem
						v-for="email in emails"
						:key="email.id"
						:email="email"
						:active="selectedEmail?.id === email.id"
						:checked="checkedIds.includes(email.id)"
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

		<div v-if="selectedEmail && !verticalLayout" class="mail-resize-handle mail-resize-handle--h"
			@mousedown.prevent="onResizeStart($event, 'horizontal')">
			<div class="mail-resize-handle__grip" />
		</div>
		<div v-if="selectedEmail && verticalLayout" class="mail-resize-handle mail-resize-handle--v"
			@mousedown.prevent="onResizeStart($event, 'vertical')">
			<div class="mail-resize-handle__grip" />
		</div>

		<div v-if="selectedEmail" class="mail-detail-panel">
			<EmailDetail
				:email="selectedEmail"
				:html-body="emailBodyHtml"
				:plain-body="emailBodyPlain"
				:loading="loadingBody"
				:mailboxes="allMailboxes"
				:remote-always="_remoteAlways"
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
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'

const { fetchEmails, fetchEmailBody, deleteEmailApi, moveEmail, markEmailRead, toggleEmailFlag } = useJmapClient()

export default {
	name: 'MailHomeView',
	components: { EmailListToolbar, EmailListItem, EmailListSkeleton, PaginationBar, EmailDetail, NcEmptyContent, NcButton, EmailOutline },
	props: {
		selectedMailbox: { type: String, default: '' },
		allMailboxes: { type: Array, default: () => [] },
		verticalLayout: { type: Boolean, default: false },
	},
	data() {
		return {
			emails: [], emailTotal: 0, offset: 0, limit: 50,
			loadingEmails: false, loadingBody: false,
			bulkProcessing: false,
			selectedEmail: null,
			emailBodyHtml: '', emailBodyPlain: '',
			listWidth: 'clamp(320px, 33%, 460px)',
			listHeight: '45%',
			resizing: false,
			checkedIds: [],
			searchQuery: '',
			filterType: 'all',
			refreshCountdown: 60,
			_refreshInterval: 60,
			_soundPref: 'none',
		}
	},
	computed: {
		currentAccountId() {
			if (this.selectedMailbox && this.selectedMailbox.includes('|')) {
				return this.selectedMailbox.split('|')[0]
			}
			return null
		},
		selectAllState() {
			if (this.checkedIds.length === 0) return false
			if (this.checkedIds.length === this.emails.length) return true
			return 'indeterminate'
		},
		moveMailboxes() {
			return this.allMailboxes.filter(m => m.role !== 'trash' && m.role !== 'junk')
		},
		panelStyle() {
			if (this.verticalLayout) return { maxHeight: this.listHeight }
			return { width: this.listWidth }
		},
		isTrashMailbox() {
			const mb = this.allMailboxes.find(m => m.id === this.selectedMailbox || (m._accountId + '|' + m.id) === this.selectedMailbox)
			return mb?.role === 'trash'
		},
	},
	watch: {
		selectedMailbox() { clearTimeout(this._searchTimer); this.checkedIds = []; this.offset = 0; this.selectedEmail = null; this.loadEmails() },
	},
	async mounted() {
		this._originalTitle = document.title
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
		this.startAutoRefresh()
		// AudioContext must be created/resumed during a user gesture;
		// browsers block sound from timers otherwise.
		this._onUserGesture = () => { this.wakeAudio(); this.requestNotifyPerm() }
		document.addEventListener('click', this._onUserGesture, { once: true })
		document.addEventListener('keydown', this._onUserGesture, { once: true })
		this._onMoveEmail = (ev) => {
			if (this._movingEmail) return
			const { emailId, mailboxId } = ev.detail || {}
			if (!emailId || !mailboxId) return
			this._movingEmail = true
			;(async () => {
				try {
					await moveEmail(emailId, mailboxId, this.currentAccountId)
					await this.loadEmails(false)
					this.notifyMailboxChange()
					showSuccess(this.t('souvera_mail', 'Message moved'))
				} catch (e) {
					console.error('Drag-drop move failed', e)
					showError(this.t('souvera_mail', 'Failed to move message'))
				} finally { this._movingEmail = false }
			})()
		}
		window.addEventListener('souvera-mail:move-email', this._onMoveEmail)
	},
	beforeUnmount() {
		this._hotkeys?.destroy()
		this.stopAutoRefresh()
		clearTimeout(this._searchTimer)
		document.removeEventListener('click', this._onUserGesture)
		document.removeEventListener('keydown', this._onUserGesture)
		window.removeEventListener('souvera-mail:move-email', this._onMoveEmail)
		if (this._audioCtx) { this._audioCtx.close(); this._audioCtx = null }
	},
	methods: {
		wakeAudio() {
			try {
				if (!this._audioCtx) {
					this._audioCtx = new (window.AudioContext || window.webkitAudioContext)()
				}
				if (this._audioCtx.state === 'suspended') this._audioCtx.resume()
			} catch {}
		},
		requestNotifyPerm() {
			if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
				try { Notification.requestPermission() } catch {}
			}
		},
		async loadEmails(showSkeleton = true) {
			if (showSkeleton) this.loadingEmails = true
			const seq = (this._loadSeq || 0) + 1
			this._loadSeq = seq
			let accountId = null
			let mailboxId = this.selectedMailbox
			if (mailboxId && mailboxId.includes('|')) {
				[accountId, mailboxId] = mailboxId.split('|')
			}
			const prevIds = this.emails.map(e => e.id)
			const prevTotal = this.emailTotal
			try {
				const r = await fetchEmails(mailboxId, this.limit, this.offset, accountId, this.searchQuery, this.filterType)
				// Ignore stale responses from earlier search/filter/pagination changes
				if (seq !== this._loadSeq) return
				this.emails = r.emails
				this.emailTotal = r.total
			} catch (e) {
				console.error('Failed to load emails', e)
				return
			} finally {
				// Only the current request controls the loading state; stale
				// requests must never hide the list or keep the skeleton up
				if (seq === this._loadSeq) this.loadingEmails = false
			}
			// Play sound and notify ONLY during auto-refresh, never on
			// manual folder switch, filter, pagination, or initial load.
			const wasAutoRefresh = this._isAutoRefresh
			this._isAutoRefresh = false
			if (wasAutoRefresh && prevIds.length > 0 && this.emailTotal > prevTotal) {
				const newIds = this.emails.map(e => e.id).filter(id => !prevIds.includes(id))
				const newUnread = this.emails.filter(e => newIds.includes(e.id) && !e.isRead)
				if (newUnread.length > 0) {
					this.playNewMailSound()
					this.notifyBrowser()
				}
			}
			this.notifyTitle()
		},
		async onRefresh() {
			this.refreshCountdown = this._refreshInterval || 60
			await this.refreshEmails()
		},
		onSearch(q) {
			this.searchQuery = q
			this.offset = 0
			// Invalidate in-flight responses immediately and keep the last valid
			// list visible (no skeleton flicker) until the debounced search runs
			this._loadSeq = (this._loadSeq || 0) + 1
			this.loadingEmails = false
			this.scheduleSearch()
		},
		scheduleSearch() {
			clearTimeout(this._searchTimer)
			this._searchTimer = setTimeout(() => {
				this.loadEmails()
			}, 350)
		},
		onFilter(type) {
			this.filterType = type
			this.offset = 0
			clearTimeout(this._searchTimer)
			this.loadEmails()
		},
		getAccountId() {
			return this.currentAccountId
		},
		async refreshEmails() { this.checkedIds = []; this.offset = 0; clearTimeout(this._searchTimer); await this.loadEmails() },
		// Notify the navigation sidebar to reload mailbox counts.
		notifyMailboxChange() {
			setTimeout(() => {
				window.dispatchEvent(new CustomEvent('souvera-mail:refresh-mailboxes'))
			}, 300)
		},
		toggleCheck(id) {
			const idx = this.checkedIds.indexOf(id)
			if (idx >= 0) this.checkedIds.splice(idx, 1)
			else this.checkedIds.push(id)
		},
		toggleSelectAll() {
			if (this.selectAllState === true) this.checkedIds = []
			else this.checkedIds = this.emails.map(e => e.id)
		},
		selectAll() { this.checkedIds = this.emails.map(e => e.id) },
		async markAllRead() {
			this.bulkProcessing = true
			try {
				let accountId = null; let mailboxId = this.selectedMailbox
				if (mailboxId && mailboxId.includes('|')) { [accountId, mailboxId] = mailboxId.split('|') }
				let offset = 0; const batchSize = 500
				while (true) {
					const r = await fetchEmails(mailboxId, batchSize, offset, accountId, '', 'unread')
					if (r.emails.length === 0) break
					this.checkedIds = r.emails.map(e => e.id)
					await this.bulkMarkReadSilent()
					offset += r.emails.length
				}
				this.checkedIds = []
				await this.loadEmails(false)
				this.notifyMailboxChange()
				showSuccess(this.t('souvera_mail', 'All marked as read'))
			} catch (e) { console.error(e); showError(this.t('souvera_mail', 'Failed to mark all as read')) }
			finally { this.bulkProcessing = false }
		},
		async markAllUnread() {
			this.bulkProcessing = true
			try {
				let accountId = null; let mailboxId = this.selectedMailbox
				if (mailboxId && mailboxId.includes('|')) { [accountId, mailboxId] = mailboxId.split('|') }
				let offset = 0; const batchSize = 500
				while (true) {
					const r = await fetchEmails(mailboxId, batchSize, offset, accountId)
					if (r.emails.length === 0) break
					this.checkedIds = r.emails.map(e => e.id)
					await this.bulkMarkUnreadSilent()
					offset += r.emails.length
				}
				this.checkedIds = []
				await this.loadEmails(false)
				this.notifyMailboxChange()
				showSuccess(this.t('souvera_mail', 'All marked as unread'))
			} catch (e) { console.error(e); showError(this.t('souvera_mail', 'Failed to mark all as unread')) }
			finally { this.bulkProcessing = false }
		},
		async emptyTrash() {
			if (!confirm(this.t('souvera_mail', 'Empty trash folder permanently? This cannot be undone.'))) return
			this.bulkProcessing = true
			try {
				let accountId = null; let mailboxId = this.selectedMailbox
				if (mailboxId && mailboxId.includes('|')) { [accountId, mailboxId] = mailboxId.split('|') }
				await axios.post(generateUrl('/apps/souvera_mail/api/v2/mailboxes/' + mailboxId + '/empty'), {}, {
					params: accountId ? { accountId } : {},
				})
				showSuccess(this.t('souvera_mail', 'Trash emptied'))
				await this.loadEmails()
			} catch (e) {
				console.error('Empty trash failed', e)
				showError(this.t('souvera_mail', 'Failed to empty trash'))
			} finally { this.bulkProcessing = false }
		},
		async bulkMarkRead() {
			this.bulkProcessing = true
			for (const id of this.checkedIds) {
				try { await markEmailRead(id, true, this.currentAccountId) } catch (e) { console.error('Failed to mark read', e) }
			}
			this.checkedIds = []
			await this.loadEmails()
			this.notifyMailboxChange()
			showSuccess(this.t('souvera_mail', 'Marked as read'))
			this.bulkProcessing = false
		},
		async bulkMarkUnread() {
			this.bulkProcessing = true
			for (const id of this.checkedIds) {
				try { await markEmailRead(id, false, this.currentAccountId) } catch (e) { console.error('Failed to mark unread', e) }
			}
			this.checkedIds = []
			await this.loadEmails()
			this.notifyMailboxChange()
			showSuccess(this.t('souvera_mail', 'Marked as unread'))
			this.bulkProcessing = false
		},
		// Silent variants — used by the pagination loop; the caller
		// handles loadEmails() and notifications once at the end.
		async bulkMarkReadSilent() {
			for (const id of this.checkedIds) {
				try { await markEmailRead(id, true, this.currentAccountId) } catch (e) { console.error('Failed to mark read', e) }
			}
		},
		async bulkMarkUnreadSilent() {
			for (const id of this.checkedIds) {
				try { await markEmailRead(id, false, this.currentAccountId) } catch (e) { console.error('Failed to mark unread', e) }
			}
		},
		async bulkDelete() {
			this.bulkProcessing = true
			for (const id of this.checkedIds) {
				try { await deleteEmailApi(id, this.currentAccountId) } catch (e) { console.error('Failed to delete', e) }
			}
			this.checkedIds = []
			await this.loadEmails()
			this.notifyMailboxChange()
			showSuccess(this.t('souvera_mail', 'Messages deleted'))
			this.bulkProcessing = false
		},
		async bulkMoveTo(mailboxId) {
			this.bulkProcessing = true
			for (const id of this.checkedIds) {
				try { await moveEmail(id, mailboxId, this.currentAccountId) } catch (e) { console.error('Failed to move', e) }
			}
			this.checkedIds = []
			await this.loadEmails()
			this.notifyMailboxChange()
			showSuccess(this.t('souvera_mail', 'Messages moved'))
			this.bulkProcessing = false
		},
		async onOpenEmail(email) {
			this.selectedEmail = email
			this.emailBodyHtml = ''; this.emailBodyPlain = ''; this.loadingBody = true
			const wasUnread = !email.isRead
			try {
				const body = await fetchEmailBody(email.id, this.currentAccountId)
				this.emailBodyHtml = body.htmlBody || ''; this.emailBodyPlain = body.plainBody || ''
				this.selectedEmail = { ...email, ...body }
				if (!email.isRead) {
					await markEmailRead(email.id, true, this.currentAccountId)
					const listItem = this.emails.find(e => e.id === email.id)
					if (listItem) listItem.isRead = true
				}
				if (wasUnread) this.notifyMailboxChange()
			} catch (e) { console.error('Failed to open email', e) } finally { this.loadingBody = false }
		},
		onReply() {
			this.$router.push({ name: 'compose', query: { mode: 'reply', id: this.selectedEmail.id, accountId: this.currentAccountId || undefined } })
		},
		onReplyAll() {
			this.$router.push({ name: 'compose', query: { mode: 'replyAll', id: this.selectedEmail.id, accountId: this.currentAccountId || undefined } })
		},
		onForward() {
			this.$router.push({ name: 'compose', query: { mode: 'forward', id: this.selectedEmail.id, accountId: this.currentAccountId || undefined } })
		},
		onMailto(event) {
			this.$router.push({ name: 'compose', query: { to: event.to } })
		},
		async deleteEmail() {
			if (!this.selectedEmail) return
			const idx = this.emails.findIndex(e => e.id === this.selectedEmail.id)
			try { await deleteEmailApi(this.selectedEmail.id, this.currentAccountId) } catch (e) { console.error('Failed to delete email', e) }
			await this.refreshEmails()
			this.notifyMailboxChange()
			if (this.emails.length > 0) {
				const next = this.emails[Math.min(idx, this.emails.length - 1)]
				if (next) this.onOpenEmail(next)
			} else {
				this.selectedEmail = null; this.emailBodyHtml = ''; this.emailBodyPlain = ''
			}
		},
		async onMove(mailboxId) {
			if (!this.selectedEmail) return
			const idx = this.emails.findIndex(e => e.id === this.selectedEmail.id)
			try { await moveEmail(this.selectedEmail.id, mailboxId, this.currentAccountId); await this.refreshEmails(); this.notifyMailboxChange() } catch (e) { console.error('Failed to move email', e) }
			if (this.emails.length > 0) {
				const next = this.emails[Math.min(idx, this.emails.length - 1)]
				if (next) this.onOpenEmail(next)
			} else {
				this.selectedEmail = null; this.emailBodyHtml = ''; this.emailBodyPlain = ''
			}
		},
		async toggleFlag(emailId) {
			const email = this.emails.find(e => e.id === emailId)
			if (!email) return
			const newFlag = !email.isFlagged
			email.isFlagged = newFlag
			try { await toggleEmailFlag(emailId, newFlag, this.currentAccountId) } catch (e) { console.error('Failed to toggle flag', e); email.isFlagged = !newFlag }
		},
		goPrev() { if (this.offset > 0) { clearTimeout(this._searchTimer); this.offset = Math.max(0, this.offset - this.limit); this.loadEmails() } },
		goNext() { if (this.offset + this.limit < this.emailTotal) { clearTimeout(this._searchTimer); this.offset += this.limit; this.loadEmails() } },
		navigateEmail(dir) {
			if (!this.selectedEmail || this.emails.length === 0) return
			const idx = this.emails.findIndex(e => e.id === this.selectedEmail.id)
			const next = Math.max(0, Math.min(this.emails.length - 1, idx + dir))
			if (this.emails[next]) this.onOpenEmail(this.emails[next])
		},
		async startAutoRefresh() {
			this.stopAutoRefresh()
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'))
				const interval = (data.autoRefresh || 60)
				this._refreshInterval = interval
				this._soundPref = data.notificationSound || 'none'
				this._remoteAlways = data.remoteImages === 'always'
				this.refreshCountdown = interval
				this._countdownTimer = setInterval(() => {
					this.refreshCountdown = Math.max(0, this.refreshCountdown - 1)
					if (this.refreshCountdown <= 0) {
						this.refreshCountdown = interval
						this._isAutoRefresh = true
						this.loadEmails(false) // background reload, no skeleton
					}
				}, 1000)
			} catch {
				this._refreshInterval = 60
				this.refreshCountdown = 60
				this._countdownTimer = setInterval(() => {
					this.refreshCountdown = Math.max(0, this.refreshCountdown - 1)
					if (this.refreshCountdown <= 0) {
						this.refreshCountdown = 60
						this._isAutoRefresh = true
						this.loadEmails(false)
					}
				}, 1000)
			}
		},
		stopAutoRefresh() {
			if (this._countdownTimer) { clearInterval(this._countdownTimer); this._countdownTimer = null }
		},
		async playNewMailSound() {
			try {
				let sound = this._soundPref || 'none'
				try {
					const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'))
					sound = data.notificationSound || 'none'
					this._soundPref = sound
				} catch {}
				if (sound === 'none') return
				this.playSound(sound)
			} catch {}
		},
		playSound(sound) {
			if (sound === 'chime' || sound === 'bell') {
				this.playSynthSound(sound)
			} else {
				this.playFileSound(sound)
			}
		},
		playFileSound(sound) {
			try {
				const root = (window.OC && window.OC.getRootPath) ? window.OC.getRootPath() : ''
				const url = root + '/apps/souvera_mail/sound/' + sound + '.mp3'
				const a = new Audio(url)
				a.volume = 0.4
				const playPromise = a.play()
				if (playPromise) playPromise.catch(() => {})
			} catch { /* Audio.play() blocked or file not found */ }
		},
		playSynthSound(sound) {
			try {
				if (!this._audioCtx) { this._audioCtx = new (window.AudioContext || window.webkitAudioContext)() }
				const ctx = this._audioCtx
				if (ctx.state === 'suspended') { try { ctx.resume() } catch {} }
				const gain = ctx.createGain()
				gain.connect(ctx.destination)
				gain.gain.value = 0.15
				if (sound === 'chime') {
					const o1 = ctx.createOscillator(); o1.connect(gain); o1.frequency.value = 880; o1.type = 'sine'; o1.start(); o1.stop(ctx.currentTime + 0.15)
					const o2 = ctx.createOscillator(); o2.connect(gain); o2.frequency.value = 1100; o2.type = 'sine'; o2.start(ctx.currentTime + 0.15); o2.stop(ctx.currentTime + 0.35)
				} else if (sound === 'bell') {
					const o1 = ctx.createOscillator(); o1.connect(gain); o1.frequency.value = 660; o1.type = 'triangle'; o1.start(); gain.gain.setTargetAtTime(0, ctx.currentTime + 0.3, 0.05); o1.stop(ctx.currentTime + 0.5)
				}
				setTimeout(() => { try { gain.disconnect() } catch {} }, 1000)
			} catch {}
		},
		notifyTitle() {
			if (!this.emails.length) return
			const unread = this.emails.filter(e => !e.isRead).length
			document.title = unread > 0 ? `(${unread}) ${this._originalTitle}` : (this._originalTitle || document.title)
		},
		// ---- resize handle for the list / detail panels ----
		onResizeStart(e, direction) {
			this.resizing = true
			const startX = e.clientX
			const startY = e.clientY
			const startW = this.$el.querySelector('.mail-list-panel')?.offsetWidth || 320
			const startH = this.$el.querySelector('.mail-list-panel')?.offsetHeight || 200
			const containerW = this.$el.offsetWidth
			const containerH = this.$el.offsetHeight
			const onMove = (ev) => {
				if (direction === 'horizontal') {
					const w = Math.max(260, Math.min(containerW - 200, startW + (ev.clientX - startX)))
					this.listWidth = w + 'px'
				} else {
					const h = Math.max(150, Math.min(containerH - 150, startH + (ev.clientY - startY)))
					this.listHeight = (h / containerH * 100).toFixed(0) + '%'
				}
			}
			const onUp = () => { this.resizing = false; window.removeEventListener('mousemove', onMove); window.removeEventListener('mouseup', onUp) }
			window.addEventListener('mousemove', onMove)
			window.addEventListener('mouseup', onUp)
		},
		notifyBrowser() {
			if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return
			const unread = this.emails.filter(e => !e.isRead).length
			if (unread > 0) {
				try {
					new Notification('Souvera Mail', { body: `${unread} neue Nachricht${unread !== 1 ? 'en' : ''}`, icon: generateUrl('/apps/souvera_mail/img/app.svg') })
				} catch {}
			}
		},
	},
}
</script>

<style scoped>
.mail-home { display: flex; height: 100%; overflow: hidden; }
.mail-home--vertical { flex-direction: column; }
.mail-home--vertical .mail-list-panel { width: 100% !important; flex-shrink: 0; max-height: 45%; }
.mail-home--vertical .mail-detail-panel { flex: 1; overflow-y: auto; }
.mail-home--vertical .mail-detail-empty { flex: 1; }
.mail-list-panel { flex-shrink: 0; overflow-y: auto; border-right: 1px solid var(--color-border); display: flex; flex-direction: column; }
.mail-detail-panel { flex: 1; overflow-y: auto; }
.mail-detail-empty { flex: 1; }
.mail-loading { display: flex; justify-content: center; padding: 48px; }
.email-items { flex: 1; overflow-y: auto; }
.mail-resize-handle { flex-shrink: 0; background: var(--color-background-dark); transition: background 0.15s; z-index: 5; display: flex; align-items: center; justify-content: center; }
.mail-resize-handle:hover { background: var(--color-primary-element-light); }
.mail-resize-handle--h { width: 8px; cursor: col-resize; border-left: 1px solid var(--color-border); border-right: 1px solid var(--color-border); }
.mail-resize-handle--v { height: 6px; cursor: row-resize; border-top: 1px solid var(--color-border); border-bottom: 1px solid var(--color-border); }
.mail-resize-handle__grip {
	width: 4px; height: 36px; border-radius: 2px;
	background: var(--color-border-dark);
}
.mail-resize-handle--v .mail-resize-handle__grip { width: 36px; height: 4px; }
.mail-resize-handle--v:hover { opacity: 0.5; }
body.resize-active { user-select: none; cursor: col-resize; }
</style>
