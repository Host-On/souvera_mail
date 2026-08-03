<template>
	<NcDialog :open="true" :name="t('souvera_mail', 'Attach from Files')" size="normal" @close="$emit('close')">
		<div class="cloud-picker">
			<div class="cloud-picker__breadcrumb">
				<NcButton v-for="(part, i) in breadcrumbs" :key="i" variant="tertiary" size="small"
					@click="navigate(part.path)">
					{{ part.label }}
				</NcButton>
			</div>
			<div v-if="loading" class="cloud-picker__loading"><span class="icon-loading" /></div>
			<div v-else class="cloud-picker__list">
				<div v-for="f in files" :key="f.name" class="cloud-picker__item"
					:class="{ 'cloud-picker__item--selected': selectedPath === (currentPath + '/' + f.name) }"
					@click="selectItem(f)">
					<FolderOpen v-if="f.type === 'dir'" :size="20" />
					<File v-else :size="20" />
					<span class="cloud-picker__name">{{ f.name }}</span>
					<span v-if="f.type !== 'dir'" class="cloud-picker__size">{{ fmtSize(f.size) }}</span>
				</div>
				<NcEmptyContent v-if="files.length === 0" :name="t('souvera_mail', 'Empty folder')" />
			</div>
			<div class="cloud-picker__footer">
				<NcButton variant="primary" :disabled="!selectedPath" @click="attachSelected">
					{{ t('souvera_mail', 'Attach') }}
				</NcButton>
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('souvera_mail', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcEmptyContent } from '@nextcloud/vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import File from 'vue-material-design-icons/File.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'CloudFilePicker',
	components: { NcDialog, NcButton, NcEmptyContent, FolderOpen, File },
	emits: ['close', 'attach'],
	data() {
		return {
			files: [],
			currentPath: '/',
			selectedPath: '',
			loading: false,
		}
	},
	computed: {
		breadcrumbs() {
			const parts = this.currentPath.split('/').filter(Boolean)
			const crumbs = [{ label: t('souvera_mail', 'Files'), path: '/' }]
			let path = ''
			for (const p of parts) {
				path += '/' + p
				crumbs.push({ label: p, path })
			}
			return crumbs
		},
	},
	mounted() { this.load('/') },
	methods: {
		async load(path) {
			this.currentPath = path
			this.selectedPath = ''
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/files/list'), { params: { path } })
				this.files = data.files || []
			} catch { this.files = [] }
			finally { this.loading = false }
		},
		selectItem(f) {
			if (f.type === 'dir') {
				this.load(this.currentPath.replace(/\/$/, '') + '/' + f.name)
			} else {
				this.selectedPath = this.currentPath.replace(/\/$/, '') + '/' + f.name
			}
		},
		navigate(path) {
			this.load(path)
		},
		async attachSelected() {
			if (!this.selectedPath) return
			try {
				const { data } = await axios.post(generateUrl('/apps/souvera_mail/api/v2/files/attach'), {
					filePath: this.selectedPath,
				})
				this.$emit('attach', {
					blobId: data.blobId,
					name: data.name,
					type: data.type,
					size: data.size,
				})
				this.$emit('close')
			} catch {}
		},
		fmtSize(bytes) {
			if (!bytes) return ''
			const u = ['B', 'KB', 'MB', 'GB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s * 10) / 10 + ' ' + u[i]
		},
	},
}
</script>

<style scoped>
.cloud-picker { display: flex; flex-direction: column; min-height: 300px; max-height: 60vh; }
.cloud-picker__breadcrumb { display: flex; flex-wrap: wrap; gap: 2px; margin-bottom: 12px; padding: 4px 0; }
.cloud-picker__loading { display: flex; justify-content: center; padding: 48px; }
.cloud-picker__list { flex: 1; overflow-y: auto; }
.cloud-picker__item {
	display: flex; align-items: center; gap: 8px;
	padding: 8px 12px; cursor: pointer;
	border-radius: var(--border-radius);
}
.cloud-picker__item:hover { background: var(--color-background-hover); }
.cloud-picker__item--selected { background: var(--color-primary-element-light); }
.cloud-picker__name { flex: 1; font-size: 13px; }
.cloud-picker__size { font-size: 12px; color: var(--color-text-maxcontrast); }
.cloud-picker__footer { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--color-border); }
</style>
