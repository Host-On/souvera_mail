<template>
	<div class="settings-view">
		<h1 class="settings-view__title">{{ t('souvera_mail', 'Settings') }}</h1>

		<div class="settings-grid">
			<div class="settings-card">
				<h2 class="settings-card__title">
					<Account :size="20" />
					{{ t('souvera_mail', 'Account') }}
				</h2>
				<div class="settings-card__body">
					<div class="setting-row">
						<span class="setting-label">{{ t('souvera_mail', 'Email') }}</span>
						<span class="setting-value">{{ accountEmail || t('souvera_mail', 'Loading…') }}</span>
					</div>
					<div class="setting-row">
						<span class="setting-label">{{ t('souvera_mail', 'Storage') }}</span>
						<span class="setting-value">
							<template v-if="quotaUnlimited">{{ t('souvera_mail', 'Unlimited') }}</template>
							<template v-else-if="quotaTotal > 0">{{ formatSize(quotaUsed) }} / {{ formatSize(quotaTotal) }}</template>
							<span v-else class="settings-muted">{{ t('souvera_mail', 'No quota information available') }}</span>
						</span>
					</div>
					<QuotaDonut v-if="quotaUnlimited || quotaTotal > 0"
						:size="56" :used="quotaUsed" :total="quotaTotal" :unlimited="quotaUnlimited" />
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Palette :size="20" />
					{{ t('souvera_mail', 'Appearance') }}
				</h2>
				<div class="settings-card__body">
					<div class="setting-row">
						<span class="setting-label">{{ t('souvera_mail', 'Layout') }}</span>
					</div>
					<div class="layout-options">
						<label class="layout-option" :class="{ 'layout-option--active': !verticalLayout }"
							@click="setVerticalLayout(false)">
							<div class="layout-preview layout-preview--horizontal">
								<div class="layout-preview__sidebar"></div>
								<div class="layout-preview__detail"></div>
							</div>
							<span class="layout-option__label">{{ t('souvera_mail', 'Side by side') }}</span>
						</label>
						<label class="layout-option" :class="{ 'layout-option--active': verticalLayout }"
							@click="setVerticalLayout(true)">
							<div class="layout-preview layout-preview--vertical">
								<div class="layout-preview__sidebar"></div>
								<div class="layout-preview__detail"></div>
							</div>
							<span class="layout-option__label">{{ t('souvera_mail', 'List above, detail below') }}</span>
						</label>
					</div>
					<div class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'External images') }}</span>
							<p class="settings-muted">{{ t('souvera_mail', 'Remote content can be used to track you.') }}</p>
						</div>
						<NcSelect v-model="remoteImagesOption" :options="remoteImageOptions"
							label="label" class="setting-select"
							:clearable="false" />
					</div>
					<div class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'Messages per page') }}</span>
						</div>
						<NcSelect v-model="messagesPerPageOption" :options="pageSizeOptions"
							label="label" class="setting-select" :clearable="false" />
					</div>
					<div class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'Auto-refresh') }}</span>
							<p class="settings-muted">{{ t('souvera_mail', 'Periodically check for new mail. Disabled when set to 0.') }}</p>
						</div>
						<NcSelect v-model="autoRefreshOption" :options="autoRefreshOptions"
							label="label" class="setting-select" :clearable="false" />
					</div>
					<div class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'Notification sound') }}</span>
						</div>
						<div class="setting-row__sound">
							<NcSelect v-model="soundOption" :options="soundOptions"
								label="label" class="setting-select" :clearable="false"
								@update:modelValue="onSoundChange" />
							<NcButton variant="tertiary" size="small"
								:aria-label="t('souvera_mail', 'Preview sound')"
								@click="previewSound">
								<template #icon><Play :size="16" /></template>
							</NcButton>
						</div>
					</div>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Pencil :size="20" />
					{{ t('souvera_mail', 'Signature') }}
				</h2>
				<div class="settings-card__body">
					<NcCheckboxRadioSwitch :model-value="sigEnabled"
						@update:modelValue="sigEnabled = $event">
						{{ t('souvera_mail', 'Append signature to messages') }}
					</NcCheckboxRadioSwitch>
					<div v-if="sigEnabled" class="setting-row setting-row--column">
						<span class="setting-label">{{ t('souvera_mail', 'Signature') }}</span>
						<div class="signature-editor">
							<template v-if="showSigSource">
								<textarea class="signature-textarea signature-textarea--source" v-model="sigHtml"
									:placeholder="t('souvera_mail', 'HTML source code…')" rows="10" spellcheck="false" />
							</template>
							<div v-else class="signature-preview" v-html="sigPreviewHtml"></div>
						</div>
						<div class="signature-editor__actions">
							<NcButton variant="tertiary" size="small" @click="toggleSigSource">
								<template #icon><CodeTags :size="16" /></template>
								{{ showSigSource ? t('souvera_mail', 'Show preview') : t('souvera_mail', 'HTML source code') }}
							</NcButton>
							<NcButton variant="tertiary" size="small" @click="pickSignatureFile">
								<template #icon><FileUpload :size="16" /></template>
								{{ t('souvera_mail', 'Import HTML file…') }}
							</NcButton>
							<input ref="signatureFileInput" type="file" accept=".html,.htm,text/html"
								class="hidden-file-input" @change="onSignatureFileSelected" />
						</div>
					</div>
					<div v-if="sigEnabled" class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'Signature position') }}</span>
						</div>
						<NcSelect v-model="signaturePositionOption" :options="signaturePositionOptions" :clearable="false"
							label="label" class="setting-select"
							@update:modelValue="saveSig" />
					</div>
					<div v-if="sigEnabled" class="setting-row">
						<NcButton variant="primary" @click="saveSig">
							{{ t('souvera_mail', 'Save signature') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Pencil :size="20" />
					{{ t('souvera_mail', 'Reply settings') }}
				</h2>
				<div class="settings-card__body">
					<div class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'Write replies') }}</span>
						</div>
						<NcSelect v-model="replyPositionOption" :options="replyPositionOptions" :clearable="false"
							label="label" class="setting-select"
							@update:modelValue="saveSig" />
					</div>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Download :size="20" />
					{{ t('souvera_mail', 'Email migration') }}
				</h2>
				<div class="settings-card__body">
					<p class="settings-muted">{{ t('souvera_mail', 'Import your old emails from another provider.') }}</p>
					<NcButton variant="primary" @click="openMigration"
						:disabled="migrationCompleted">
						<template #icon><Import :size="20" /></template>
						{{ migrationCompleted ? t('souvera_mail', 'Import already completed') : t('souvera_mail', 'Start migration assistant') }}
					</NcButton>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<ShareVariant :size="20" />
					{{ t('souvera_mail', 'Shared folders') }}
				</h2>
				<div class="settings-card__body">
					<p class="settings-muted">{{ t('souvera_mail', 'Control where shared mailboxes appear in your folder list.') }}</p>
					<div class="shared-position-row">
						<NcCheckboxRadioSwitch :model-value="sharedAbove" type="radio"
							@update:modelValue="setSharedPosition(true)">
							{{ t('souvera_mail', 'Show above own folders') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :model-value="!sharedAbove" type="radio"
							@update:modelValue="setSharedPosition(false)">
							{{ t('souvera_mail', 'Show below own folders') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>
			</div>

		<div class="settings-card">
				<h2 class="settings-card__title">
					<Folder :size="20" />
					{{ t('souvera_mail', 'Folders') }}
				</h2>
				<div class="settings-card__body">
					<NcButton variant="primary" @click="showCreateFolder = true">
						<template #icon><Plus :size="20" /></template>
						{{ t('souvera_mail', 'New folder') }}
					</NcButton>
					<div v-if="showCreateFolder" class="create-row">
						<NcTextField v-model="newFolderName" :placeholder="t('souvera_mail', 'Folder name')" />
						<NcButton variant="primary" @click="createFolder" :disabled="newFolderName.trim() === ''">{{ t('souvera_mail', 'Create') }}</NcButton>
						<NcButton variant="tertiary" @click="showCreateFolder = false">{{ t('souvera_mail', 'Cancel') }}</NcButton>
					</div>
					<div v-if="userFoldersList.length > 0" class="folder-list">
						<div v-for="f in userFoldersList" :key="f.id" class="folder-row">
							<span>{{ f.name }}</span>
							<div class="folder-row__actions">
								<NcButton variant="tertiary" size="small"
									:aria-label="t('souvera_mail', 'Rename')" @click="startRenameFolder(f)">
									<template #icon><Pencil :size="14" /></template>
								</NcButton>
								<NcButton variant="tertiary" size="small"
									:aria-label="t('souvera_mail', 'Delete')" @click="deleteFolder(f.id)">
									<template #icon><TrashCan :size="14" /></template>
								</NcButton>
							</div>
						</div>
					</div>
					<NcEmptyContent v-else-if="loadedFolders" :name="t('souvera_mail', 'No custom folders')" />
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Key :size="20" />
					{{ t('souvera_mail', 'App passwords') }}
				</h2>
				<div class="settings-card__body">
					<p class="settings-muted">{{ t('souvera_mail', 'Create device-specific passwords for mail clients and mobile apps.') }}</p>
					<NcButton variant="primary" @click="showCreate = true">
						<template #icon><Plus :size="20" /></template>
						{{ t('souvera_mail', 'New app password') }}
					</NcButton>
					<div v-if="showCreate" class="create-row">
						<NcTextField v-model="newName" :placeholder="t('souvera_mail', 'Name (e.g. Android, iOS)')" />
						<NcButton variant="primary" @click="create" :disabled="newName.trim() === ''">{{ t('souvera_mail', 'Create') }}</NcButton>
						<NcButton variant="tertiary" @click="showCreate = false">{{ t('souvera_mail', 'Cancel') }}</NcButton>
					</div>
					<div v-if="passwords.length > 0" class="password-list">
						<div v-for="pw in passwords" :key="pw.id" class="password-row">
							<div>
								<div class="password-name">{{ pw.description || pw.name }}</div>
								<div class="settings-muted">{{ pw.createdAt ? fmtDate(pw.createdAt) : '' }}</div>
							</div>
							<NcButton variant="tertiary" size="small"
								:aria-label="t('souvera_mail', 'Delete')" @click="remove(pw.id)">
								<template #icon><TrashCan :size="16" /></template>
							</NcButton>
						</div>
					</div>
					<NcEmptyContent v-else-if="loaded && passwords.length === 0"
						:name="t('souvera_mail', 'No app passwords')" />
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcCheckboxRadioSwitch, NcSelect, NcEmptyContent } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import Account from 'vue-material-design-icons/Account.vue'
import Palette from 'vue-material-design-icons/Palette.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import Key from 'vue-material-design-icons/Key.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Play from 'vue-material-design-icons/Play.vue'
import DOMPurify from 'dompurify'
import QuotaDonut from '../components/QuotaDonut.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const API = {
	quota: () => axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/quota')),
	passwords: () => axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords')),
	shared: () => axios.get(generateUrl('/apps/souvera_mail/api/v2/shared')),
	prefs: () => axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/preferences')),
}

export default {
	name: 'SettingsView',
	components: { NcButton, NcTextField, NcCheckboxRadioSwitch, NcSelect, NcEmptyContent, Plus, TrashCan, Account, Palette, Pencil, ShareVariant, Key, Folder, CodeTags, FileUpload, Download, Import, Play, QuotaDonut },
	data() {
		return {
			accountEmail: '',
			quotaUsed: 0, quotaTotal: 0, quotaUnlimited: false,
			passwords: [], showCreate: false, newName: '',
			sharedAbove: true,
			sigHtml: '', sigEnabled: false, showSigSource: false,
			replyPosition: 'above', signaturePosition: 'above',
			replyPositionOptions: [
				{ value: 'above', label: this.t ? this.t('souvera_mail', 'Above the quoted text') : 'Above the quoted text' },
				{ value: 'below', label: this.t ? this.t('souvera_mail', 'Below the quoted text') : 'Below the quoted text' },
			],
			replyPositionOption: { value: 'above', label: 'Above the quoted text' },
			signaturePositionOptions: [
				{ value: 'above', label: this.t ? this.t('souvera_mail', 'Above the quoted text') : 'Above the quoted text' },
				{ value: 'below', label: this.t ? this.t('souvera_mail', 'Below the quoted text') : 'Below the quoted text' },
			],
			signaturePositionOption: { value: 'above', label: 'Above the quoted text' },
			loaded: false,
			migrationCompleted: false,
			remoteImageOptions: [
				{ value: 'never', label: this.t ? this.t('souvera_mail', 'Ask before loading') : 'Ask before loading' },
				{ value: 'always', label: this.t ? this.t('souvera_mail', 'Always load') : 'Always load' },
			],
			remoteImagesOption: { value: 'never', label: 'Ask before loading' },
			pageSizeOptions: [
				{ value: 25, label: '25' },
				{ value: 50, label: '50' },
				{ value: 100, label: '100' },
			],
			messagesPerPageOption: { value: 50, label: '50' },
			verticalLayout: false,
			autoRefreshOptions: [
				{ value: 0, label: 'Off' },
				{ value: 30, label: '30s' },
				{ value: 60, label: '1m' },
				{ value: 120, label: '2m' },
				{ value: 300, label: '5m' },
			],
			autoRefreshOption: { value: 60, label: '1m' },
			soundOptions: [
				{ value: 'none', label: 'Off' },
				{ value: 'chime', label: 'Chime' },
				{ value: 'bell', label: 'Bell' },
				{ value: 'new-mail', label: 'New mail' },
				{ value: 'alert', label: 'Alert' },
				{ value: 'ping', label: 'Ping' },
			],
			soundOption: { value: 'none', label: 'Off' },
			userFoldersList: [],
			showCreateFolder: false,
			newFolderName: '',
			loadedFolders: false,
		}
	},
	mounted() {
		this.remoteImageOptions[0].label = this.t('souvera_mail', 'Ask before loading')
		this.remoteImageOptions[1].label = this.t('souvera_mail', 'Always load')
		this.loadAll()
	},
	computed: {
		sigPreviewHtml() {
			return DOMPurify.sanitize(this.sigHtml || '', { USE_PROFILES: { html: true } })
		},
	},
	methods: {
		async loadAll() {
			try { const r = await API.quota(); this.quotaUsed = r.data.used || 0; this.quotaTotal = r.data.total || 0; this.quotaUnlimited = r.data.unlimited || false } catch {}
			try { const r = await API.passwords(); this.passwords = r.data.passwords || [] } catch {}
			try { const r = await API.shared(); this.sharedAbove = r.data.position === 'above' } catch {}
			try { const r = await axios.get(generateUrl('/apps/souvera_mail/migration/welcome-state')); this.migrationCompleted = r.data?.state?.lastJob?.state === 'completed' } catch {}
			try { const r = await axios.get(generateUrl('/apps/souvera_mail/api/v2/mailboxes')); this.userFoldersList = (r.data.mailboxes || []).filter(m => !['inbox','sent','drafts','archive','junk','trash'].includes(m.role)) } catch {} finally { this.loadedFolders = true }
			try {
				const r = await API.prefs(); const p = r.data
				this.accountEmail = (p.account && p.account.email) || ''
				this.sigHtml = p.signatureHtml || ''
				this.sigEnabled = p.signatureEnabled || false
				this.replyPosition = p.replyPosition === 'below' ? 'below' : 'above'
				this.signaturePosition = p.signaturePosition === 'below' ? 'below' : 'above'
				const rp = this.replyPositionOptions.find(o => o.value === this.replyPosition)
				if (rp) this.replyPositionOption = rp
				const sp = this.signaturePositionOptions.find(o => o.value === this.signaturePosition)
				if (sp) this.signaturePositionOption = sp
				if (p.remoteImages === 'always') this.remoteImagesOption = this.remoteImageOptions[1]
				this.verticalLayout = p.verticalLayout || false
				const ar = this.autoRefreshOptions.find(o => o.value === (p.autoRefresh || 60))
				if (ar) this.autoRefreshOption = ar
				const so = this.soundOptions.find(o => o.value === (p.notificationSound || 'none'))
				if (so) this.soundOption = so
				const pp = this.pageSizeOptions.find(o => o.value === p.messagesPerPage)
				if (pp) this.messagesPerPageOption = pp
			} catch {}
			this.loaded = true
		},
		formatSize(bytes) {
			if (!bytes) return '0 B'; const u = ['B','KB','MB','GB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s * 10) / 10 + ' ' + u[i]
		},
		fmtDate(ts) { return ts ? new Date(ts).toLocaleDateString() : '' },
		async create() {
			try {
				const r = await axios.post(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords'), { name: this.newName })
				this.passwords.push({ id: r.data.id, description: this.newName, createdAt: new Date().toISOString() })
				this.showCreate = false; this.newName = ''
				showSuccess(this.t('souvera_mail', 'App password created'))
			} catch (e) { console.error('App password create failed', e); showError(this.t('souvera_mail', 'Failed to create app password')) }
		},
		async remove(id) {
			try {
				await axios.delete(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords/' + id))
				this.passwords = this.passwords.filter(p => p.id !== id)
				showSuccess(this.t('souvera_mail', 'App password removed'))
			} catch (e) { console.error('App password remove failed', e); showError(this.t('souvera_mail', 'Failed to remove app password')) }
		},
		async setSharedPosition(above) {
			this.sharedAbove = above
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/shared/position'), { position: above ? 'above' : 'below' })
				showSuccess(this.t('souvera_mail', 'Shared folder position saved'))
			} catch (e) { console.error('Shared position save failed', e); showError(this.t('souvera_mail', 'Failed to save')) }
		},
		async setVerticalLayout(val) {
			this.verticalLayout = val
			try { await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), { verticalLayout: val }) } catch {}
			window.location.reload()
		},
		async onSoundChange(val) {
			if (val?.value) {
				try {
					await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), { notificationSound: val.value })
					showSuccess(this.t('souvera_mail', 'Notification sound saved'))
				} catch (e) { console.error('Sound save failed', e); showError(this.t('souvera_mail', 'Failed to save')) }
			}
		},
		previewSound() {
			const sound = this.soundOption?.value
			if (!sound || sound === 'none') return
			this.playSound(sound)
		},
		playSound(sound) {
			if (sound === 'chime' || sound === 'bell') {
				try {
					const ctx = new (window.AudioContext || window.webkitAudioContext)()
					const gain = ctx.createGain()
					gain.connect(ctx.destination)
					gain.gain.value = 0.15
					if (sound === 'chime') {
						const o1 = ctx.createOscillator(); o1.connect(gain); o1.frequency.value = 880; o1.type = 'sine'; o1.start(); o1.stop(ctx.currentTime + 0.15)
						const o2 = ctx.createOscillator(); o2.connect(gain); o2.frequency.value = 1100; o2.type = 'sine'; o2.start(ctx.currentTime + 0.15); o2.stop(ctx.currentTime + 0.35)
					} else {
						const o1 = ctx.createOscillator(); o1.connect(gain); o1.frequency.value = 660; o1.type = 'triangle'; o1.start(); gain.gain.setTargetAtTime(0, ctx.currentTime + 0.3, 0.05); o1.stop(ctx.currentTime + 0.5)
					}
					setTimeout(() => { try { gain.disconnect(); ctx.close() } catch {} }, 1000)
				} catch (e) { console.error('Sound preview failed', e) }
			} else {
				try {
					const root = (typeof OC !== 'undefined' && OC.getRootPath ? OC.getRootPath() : '')
					const a = new Audio(root + '/apps/souvera_mail/app/smail/v/current/static/sounds/' + sound + '.mp3')
					a.volume = 0.4
					a.play()
				} catch {}
			}
		},
		async createFolder() {
			const name = this.newFolderName.trim()
			if (!name) return
			try {
				const { data } = await axios.post(generateUrl('/apps/souvera_mail/api/v2/mailboxes'), { name })
				this.userFoldersList.push({ id: data.id, name })
				this.showCreateFolder = false; this.newFolderName = ''
				showSuccess(this.t('souvera_mail', 'Folder created'))
			} catch (e) { console.error('Folder create failed', e); showError(this.t('souvera_mail', 'Failed to create folder')) }
		},
		async startRenameFolder(f) {
			const name = prompt(this.t('souvera_mail', 'New name'), f.name)
			if (name && name.trim() && name.trim() !== f.name) {
				try {
					await axios.put(generateUrl('/apps/souvera_mail/api/v2/mailboxes/' + f.id), { name: name.trim() })
					f.name = name.trim()
					showSuccess(this.t('souvera_mail', 'Folder renamed'))
				} catch (e) { console.error('Folder rename failed', e); showError(this.t('souvera_mail', 'Failed to rename folder')) }
			}
		},
		async deleteFolder(id) {
			if (!confirm(this.t('souvera_mail', 'Delete this folder?'))) return
			try {
				await axios.delete(generateUrl('/apps/souvera_mail/api/v2/mailboxes/' + id))
				this.userFoldersList = this.userFoldersList.filter(f => f.id !== id)
				showSuccess(this.t('souvera_mail', 'Folder deleted'))
			} catch (e) { console.error('Folder delete failed', e); showError(this.t('souvera_mail', 'Failed to delete folder')) }
		},
		async saveSig() {
			try {
				const replyPosition = this.replyPositionOption?.value === 'below' ? 'below' : 'above'
				const signaturePosition = this.signaturePositionOption?.value === 'below' ? 'below' : 'above'
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), {
					signatureHtml: this.sigHtml,
					signatureEnabled: this.sigEnabled,
					replyPosition,
					signaturePosition,
				})
				this.replyPosition = replyPosition
				this.signaturePosition = signaturePosition
				showSuccess(this.t('souvera_mail', 'Signature saved'))
			} catch (e) {
				console.error('Failed to save signature', e)
				showError(this.t('souvera_mail', 'Failed to save signature') + ': ' + (e.response?.data?.error || e.message))
			}
		},
		openMigration() {
			// The migration assistant (provider.tools IMAP import) is a
			// separate bundle; the event forces it open even when it was
			// previously dismissed.
			window.dispatchEvent(new CustomEvent('souvera-mail:open-migration'))
		},
		toggleSigSource() {
			this.showSigSource = !this.showSigSource
			if (!this.showSigSource) this.saveSig()
		},
		pickSignatureFile() { this.$refs.signatureFileInput?.click() },
		onSignatureFileSelected(e) {
			const file = e.target.files?.[0]
			e.target.value = ''
			if (!file) return
			if (file.size === 0 || file.size > 2 * 1024 * 1024) {
				showError(this.t('souvera_mail', 'Signature file ignored (empty or larger than 2 MB)'))
				return
			}
			const reader = new FileReader()
			reader.onload = () => {
				const raw = String(reader.result || '')
				this.sigHtml = DOMPurify.sanitize(raw, { USE_PROFILES: { html: true } })
				this.saveSig()
			}
			reader.onerror = () => {
				console.error('Failed to read signature file')
				showError(this.t('souvera_mail', 'Failed to read signature file'))
			}
			reader.readAsText(file)
		},
	},
}
</script>

<style scoped>
.settings-view { padding: 30px 32px; height: 100%; overflow-y: auto; box-sizing: border-box; }
.settings-view__title { margin: 0 0 24px; font-size: 22px; font-weight: 700; }

/* Masonry layout: cards keep their natural content height — the next card
   flows directly below, no equal-height rows. Column width drives the
   responsive column count (same effect as auto-fill minmax(380px,1fr)). */
.settings-grid { columns: 380px; column-gap: 20px; }

.settings-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	overflow: hidden;
	break-inside: avoid;
	margin-bottom: 20px;
}
.settings-card__title {
	display: flex; align-items: center; gap: 8px;
	margin: 0; padding: 14px 20px;
	font-size: 15px; font-weight: 600;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}
.settings-card__body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }

.setting-row {
	display: flex; justify-content: space-between; align-items: center;
	gap: 16px;
}
.setting-label { font-size: 14px; font-weight: 500; }
.setting-value { font-size: 14px; color: var(--color-text-maxcontrast); }
.setting-select { min-width: 180px; }
.setting-row__sound { display: flex; align-items: center; gap: 6px; }

.layout-options { display: flex; gap: 12px; }
.layout-option {
	flex: 1; cursor: pointer;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	text-align: center;
	transition: border-color 0.2s;
}
.layout-option:hover { border-color: var(--color-primary-element); }
.layout-option--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}
.layout-preview {
	height: 60px; margin-bottom: 8px;
	border-radius: var(--border-radius);
	overflow: hidden; display: flex;
}
.layout-preview--horizontal { flex-direction: row; }
.layout-preview--horizontal .layout-preview__sidebar { width: 35%; background: var(--color-background-dark); border-right: 2px solid var(--color-border); }
.layout-preview--horizontal .layout-preview__detail { flex: 1; background: var(--color-main-background); border: 1px solid var(--color-border); border-left: none; }

.layout-preview--vertical { flex-direction: column; }
.layout-preview--vertical .layout-preview__sidebar { height: 35%; background: var(--color-background-dark); border-bottom: 2px solid var(--color-border); }
.layout-preview--vertical .layout-preview__detail { flex: 1; background: var(--color-main-background); border: 1px solid var(--color-border); border-top: none; }

.layout-option__label { font-size: 12px; font-weight: 500; color: var(--color-text-maxcontrast); }
.layout-option--active .layout-option__label { color: var(--color-primary-element); font-weight: 600; }

.settings-muted { color: var(--color-text-maxcontrast); font-size: 12px; margin: 4px 0 0; }
.shared-position-row { display: flex; flex-direction: column; gap: 6px; }
.create-row { display: flex; align-items: center; gap: 8px; }
.create-row :deep(input) { min-width: 200px; }
.password-list { display: flex; flex-direction: column; gap: 6px; }
.password-row {
	display: flex; justify-content: space-between; align-items: center; gap: 8px;
	padding: 10px 14px; border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}
.password-name { font-weight: 500; font-size: 13px; }
.password-value { font-size: 12px; font-family: monospace; word-break: break-all; }
.setting-row--column { flex-direction: column; align-items: stretch; }
.signature-editor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
	background: var(--color-main-background);
}
.signature-editor__actions {
	display: flex; gap: 8px; margin-top: 8px;
}
.hidden-file-input { display: none; }
.signature-textarea {
	width: 100%; border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px 14px;
	font: inherit; font-size: 13px; resize: vertical;
	background: var(--color-main-background); color: var(--color-main-text);
	box-sizing: border-box;
}
/* After .signature-textarea so monospace wins for the source view */
.signature-textarea--source {
	min-height: 200px;
	font-family: monospace; font-size: 12px; font-weight: normal;
}
/* True layout-preserving preview — renders the EXACT sanitized HTML
   (no Tiptap normalisation, which would strip tables/images/layout). */
.signature-preview {
	min-height: 120px;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	font-size: 13px; line-height: 1.5;
	overflow-x: hidden;
	overflow-y: auto;
	word-break: break-word;
}
.signature-preview :deep(img) { max-width: 100%; height: auto; }
.folder-list { display: flex; flex-direction: column; gap: 4px; }
.folder-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--border-radius); }
.folder-row__actions { display: flex; gap: 2px; }
</style>
