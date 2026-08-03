<template>
	<div class="email-detail" v-if="email">
		<div class="email-detail__toolbar">
			<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Back')" @click="$emit('close')">
				<template #icon><ArrowLeft :size="20" /></template>
			</NcButton>
			<div class="email-detail__actions">
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Reply')" @click="$emit('reply')">
					<template #icon><Reply :size="20" /></template>
				</NcButton>
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Reply all')" @click="$emit('replyAll')">
					<template #icon><ReplyAll :size="20" /></template>
				</NcButton>
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Forward')" @click="$emit('forward')">
					<template #icon><Forward :size="20" /></template>
				</NcButton>
				<NcActions :aria-label="t('souvera_mail', 'More actions')">
					<template #icon><FolderMove :size="20" /></template>
					<NcActionButton v-for="mb in moveMailboxes" :key="mb.id"
						:name="mb.name"
						@click="moveTo(mb.id)">
						<template #icon><Folder :size="20" /></template>
					</NcActionButton>
				</NcActions>
				<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Delete')" @click="$emit('delete')">
					<template #icon><TrashCan :size="20" /></template>
				</NcButton>
			</div>
		</div>

		<div class="email-detail__header">
			<h2>{{ email.subject || t('souvera_mail', '(no subject)') }}</h2>
			<div class="email-detail__from">
				<BimiLogo :email="email.fromAddress" />
				<strong>{{ email.fromName || email.fromAddress }}</strong>
				<span class="email-detail__addr">&lt;{{ email.fromAddress }}&gt;</span>
			</div>
			<div class="email-detail__meta">
				<span v-if="email.toAddresses">{{ t('souvera_mail', 'To:') }} {{ email.toAddresses }}</span>
				<span>{{ formatDateTime(email.receivedAt) }}</span>
			</div>

			<div v-if="email.attachments && email.attachments.length > 0" class="email-detail__attachments">
				<div class="email-detail__attachments-header">
					<h4>{{ t('souvera_mail', 'Attachments') }} ({{ email.attachments.length }})</h4>
					<NcButton variant="tertiary" size="small" @click="openSaveAllPicker" :disabled="savingAll">
						<template #icon><FolderDownload :size="16" /></template>
						{{ savingAll ? t('souvera_mail', 'Saving…') : t('souvera_mail', 'Save all to Files') }}
					</NcButton>
				</div>
				<div class="attachment-chips">
					<div v-for="att in email.attachments" :key="att.blobId" class="attachment-chip">
						<a :href="buildBlobUrl(att.blobId, att.name)" download
							:title="t('souvera_mail', 'Download file')">
							<NcButton variant="tertiary">
								<template #icon><Download :size="16" /></template>
								{{ att.name }} ({{ formatSize(att.size) }})
							</NcButton>
						</a>
						<NcButton variant="tertiary" size="small"
							:title="t('souvera_mail', 'Save to Files')"
							:aria-label="t('souvera_mail', 'Save to Files')"
							@click="startSaveToFiles(att)">
							<template #icon><ContentSave :size="16" /></template>
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<NcDialog v-if="showFolderPicker" :open="true"
			:name="t('souvera_mail', 'Choose folder')"
			size="normal" @close="showFolderPicker = false">
			<div class="folder-picker">
				<div class="folder-picker__breadcrumb">
					<NcButton variant="tertiary" size="small" @click="folderPath = ''; loadFolders()">
						{{ t('souvera_mail', 'Files') }}
					</NcButton>
					<template v-for="(part, i) in folderBreadcrumbs">
						<span class="folder-picker__sep">/</span>
						<NcButton variant="tertiary" size="small" @click="folderPath = part.path; loadFolders()">
							{{ part.label }}
						</NcButton>
					</template>
				</div>
				<div class="folder-picker__actions">
					<NcTextField v-if="showCreateFolder" v-model="newFolderName"
						:placeholder="t('souvera_mail', 'Folder name')" />
					<NcButton v-if="showCreateFolder" variant="primary" size="small"
						@click="createFolder" :disabled="!newFolderName.trim()">
						{{ t('souvera_mail', 'Create') }}
					</NcButton>
					<NcButton v-if="!showCreateFolder" variant="tertiary" size="small" @click="showCreateFolder = true">
						<template #icon><FolderPlus :size="16" /></template>
						{{ t('souvera_mail', 'New folder') }}
					</NcButton>
				</div>
				<div v-if="loadingFolders" class="folder-picker__loading"><span class="icon-loading" /></div>
				<div v-else class="folder-picker__list">
					<div v-for="f in folders" :key="f.name" class="folder-picker__item"
						:class="{ 'folder-picker__item--selected': folderPath === f.path }"
						@dblclick="folderPath = f.path; loadFolders()"
						@click="folderPath = f.path">
						<FolderOpen :size="20" />
						{{ f.name }}
					</div>
					<NcEmptyContent v-if="folders.length === 0" :name="t('souvera_mail', 'No subfolders')" />
				</div>
				<div class="folder-picker__footer">
					<span v-if="folderPath" class="folder-picker__current">
						{{ t('souvera_mail', 'Save to') }}: {{ folderPath }}
					</span>
					<NcButton variant="primary" @click="doSaveToFiles" :disabled="!folderPath">
						{{ t('souvera_mail', 'Save here') }}
					</NcButton>
					<NcButton variant="tertiary" @click="showFolderPicker = false">
						{{ t('souvera_mail', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>

		<div class="email-detail__body">
			<HtmlMailFrame v-if="htmlBody"
				:html="htmlBody"
				:attachments="email.attachments || []"
				@mailto="$emit('mailto', $event)" />
			<div v-else-if="plainBody" class="email-body-text">{{ plainBody }}</div>
			<div v-else-if="loading" class="email-detail__loading">
				<span class="icon-loading" />
			</div>
			<p v-else class="email-detail__empty">
				{{ t('souvera_mail', 'This message has no content or could not be loaded.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton, NcActions, NcActionButton, NcDialog, NcTextField, NcEmptyContent } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import ReplyAll from 'vue-material-design-icons/ReplyAll.vue'
import Forward from 'vue-material-design-icons/Forward.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import FolderDownload from 'vue-material-design-icons/FolderDownload.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import HtmlMailFrame from './HtmlMailFrame.vue'
import BimiLogo from './BimiLogo.vue'
import { buildBlobUrl } from '../utils/mailSanitizer.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'EmailDetail',
	components: { NcButton, NcActions, NcActionButton, NcDialog, NcTextField, NcEmptyContent, ArrowLeft, Reply, ReplyAll, Forward, TrashCan, Download, Paperclip, FolderMove, Folder, FolderOpen, FolderPlus, FolderDownload, ContentSave, HtmlMailFrame, BimiLogo },
	props: {
		email: { type: Object, default: null },
		htmlBody: { type: String, default: '' },
		plainBody: { type: String, default: '' },
		loading: { type: Boolean, default: false },
		mailboxes: { type: Array, default: () => [] },
	},
	emits: ['close', 'reply', 'replyAll', 'forward', 'delete', 'move', 'mailto'],
	data() { return { savingAll: false, showFolderPicker: false, folderPath: '', folders: [], loadingFolders: false, showCreateFolder: false, newFolderName: '', pendingAtt: null, pendingAll: false } },
	computed: {
		filePath() {
			const p = this.folderPath.replace(/^\//, '')
			return p
		},
		folderBreadcrumbs() {
			if (!this.folderPath) return []
			const parts = this.folderPath.split('/').filter(Boolean)
			const crumbs = []
			let path = ''
			for (const p of parts) {
				path += '/' + p
				crumbs.push({ label: p, path: path.replace(/^\//, '') })
			}
			return crumbs
		},
		moveMailboxes() {
			return this.mailboxes.filter(m => m.role !== 'trash' && m.role !== 'junk')
		},
	},
	methods: {
		buildBlobUrl,
		formatDateTime(iso) { try { return new Date(iso).toLocaleString() } catch { return iso } },
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const u = ['B', 'KB', 'MB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s) + ' ' + u[i]
		},
		moveTo(mailboxId) {
			this.$emit('move', mailboxId)
		},
		startSaveToFiles(att) {
			this.pendingAtt = att
			this.pendingAll = false
			this.showFolderPicker = true
			this.folderPath = ''
			this.loadFolders()
		},
		openSaveAllPicker() {
			this.pendingAtt = null
			this.pendingAll = true
			this.showFolderPicker = true
			this.folderPath = ''
			this.loadFolders()
		},
		async loadFolders() {
			this.loadingFolders = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/files/list'), { params: { path: this.folderPath || '/' } })
				this.folders = (data.files || []).filter(f => f.type === 'dir').map(f => ({
					name: f.name,
					path: (this.folderPath ? this.folderPath + '/' : '') + f.name,
				}))
			} catch { this.folders = [] }
			finally { this.loadingFolders = false }
		},
		async createFolder() {
			const name = this.newFolderName.trim()
			if (!name) return
			this.folders.push({ name, path: (this.folderPath ? this.folderPath + '/' : '') + name })
			this.newFolderName = ''
			this.showCreateFolder = false
		},
		async doSaveToFiles() {
			const targetPath = this.folderPath || ''
			if (this.pendingAll) {
				this.savingAll = true
				this.showFolderPicker = false
				try {
					const attachments = this.email.attachments.map(a => ({ blobId: a.blobId, name: a.name }))
					await axios.post(generateUrl('/apps/souvera_mail/api/v2/attachments/save-all'), { attachments, targetPath })
				} catch (e) { console.error('Save all failed', e) }
				finally { this.savingAll = false }
			} else if (this.pendingAtt) {
				this.showFolderPicker = false
				try {
					await axios.post(generateUrl('/apps/souvera_mail/api/v2/attachments/' + this.pendingAtt.blobId + '/save'), {
						name: this.pendingAtt.name,
						targetPath,
					})
				} catch (e) { console.error('Save to Files failed', e) }
			}
		},
	},
}
</script>

<style scoped>
.email-detail { padding: 20px; }
.email-detail__toolbar {
	display: flex; justify-content: space-between; align-items: center;
	padding: 8px 12px; margin: -20px -20px 0 -20px;
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
}
.email-detail__actions { display: flex; gap: 4px; }
.email-detail__header {
	margin: 0 -20px 16px -20px;
	padding: 14px 20px;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}
.email-detail__header h2 { margin: 0 0 8px; font-size: 20px; font-weight: 600; }
.email-detail__from { margin-bottom: 4px; }
.email-detail__addr { color: var(--color-text-maxcontrast); margin-left: 6px; font-weight: 400; }
.email-detail__meta { display: flex; justify-content: space-between; font-size: 12px; color: var(--color-text-maxcontrast); }
.email-detail__attachments {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}
.email-detail__attachments-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.email-detail__attachments-header h4 { margin: 0; font-size: 13px; color: var(--color-text-maxcontrast); }
.attachment-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.attachment-chip { display: flex; align-items: center; gap: 4px; }
.email-detail__body { line-height: 1.7; word-break: break-word; }
.email-body-text { white-space: pre-wrap; }
.email-detail__loading { display: flex; justify-content: center; padding: 48px; }
.email-detail__empty { color: var(--color-text-maxcontrast); text-align: center; padding: 48px; }
.folder-picker { display: flex; flex-direction: column; min-height: 300px; max-height: 55vh; }
.folder-picker__breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: 2px; margin-bottom: 8px; }
.folder-picker__sep { color: var(--color-text-maxcontrast); padding: 0 2px; }
.folder-picker__actions { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.folder-picker__loading { display: flex; justify-content: center; padding: 48px; }
.folder-picker__list { flex: 1; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--border-radius); }
.folder-picker__item { display: flex; align-items: center; gap: 8px; padding: 6px 12px; cursor: pointer; font-size: 13px; }
.folder-picker__item:hover { background: var(--color-background-hover); }
.folder-picker__item--selected { background: var(--color-primary-element-light); }
.folder-picker__footer { display: flex; align-items: center; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--color-border); }
.folder-picker__current { flex: 1; font-size: 12px; color: var(--color-text-maxcontrast); }
</style>
