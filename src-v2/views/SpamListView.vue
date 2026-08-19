<template>
	<div class="spam-view">
		<div class="spam-view__toolbar">
			<h2 class="spam-view__title">{{ t('souvera_mail', 'Spam') }}</h2>
			<div class="spam-view__actions" v-if="checkedIds.length > 0">
				<NcButton variant="primary" @click="releaseSelected">
					<template #icon><EmailOutline :size="18" /></template>
					{{ t('souvera_mail', 'Release') }}
				</NcButton>
				<NcButton variant="error" @click="deleteSelected">
					<template #icon><TrashCan :size="18" /></template>
					{{ t('souvera_mail', 'Delete') }}
				</NcButton>
			</div>
		</div>

		<div v-if="loading && items.length === 0" class="spam-view__loading">
			<div class="spam-view__skeleton" v-for="n in 6" :key="n">
				<div class="skeleton-line skeleton-line--wide" />
				<div class="skeleton-line" />
			</div>
		</div>

		<div v-else-if="error" class="spam-view__error">
			<NcEmptyContent :name="t('souvera_mail', 'Failed to load spam')">
				<template #icon><AlertCircle :size="48" /></template>
				<template #action>
					<NcButton variant="primary" @click="loadItems">{{ t('souvera_mail', 'Retry') }}</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<NcEmptyContent v-else-if="!loading && items.length === 0"
			:name="t('souvera_mail', 'No spam')">
			<template #icon><CheckAll :size="48" /></template>
		</NcEmptyContent>

		<div class="spam-view__list" v-else>
			<div v-for="item in items" :key="item._source + '|' + item.id"
				class="spam-item"
				:class="{ 'spam-item--active': selectedItem && selectedItem.id === item.id && selectedItem._source === item._source }"
				@click="openItem(item)">
				<NcCheckboxRadioSwitch :model-value="item._checked" @click.stop @update:model-value="item._checked = $event" />
				<div class="spam-item__content">
					<div class="spam-item__top">
						<span class="spam-item__from">{{ item.fromName || item.fromAddress || '&nbsp;' }}</span>
						<span class="spam-item__date">{{ formatDate(item.receivedAt) }}</span>
					</div>
					<div class="spam-item__subject">{{ item.subject || t('souvera_mail', '(No subject)') }}</div>
					<div class="spam-item__meta">
						<span v-if="item._source === 'shield'" class="spam-badge" :class="'spam-badge--' + spamLevelClass(item.spamLevel)">
							{{ item.spamLevel }}
						</span>
						<span class="spam-item__source" :class="'spam-item__source--' + item._source">
							{{ item._source === 'shield' ? 'Shield' : 'Junk' }}
						</span>
					</div>
				</div>
			</div>
		</div>

		<div class="spam-view__pagination" v-if="total > pageSize">
			<NcButton variant="tertiary" :disabled="offset === 0" @click="prevPage">
				{{ t('souvera_mail', 'Previous') }}
			</NcButton>
			<span class="spam-view__pagination-info">{{ offset + 1 }} – {{ Math.min(offset + pageSize, total) }} / {{ total }}</span>
			<NcButton variant="tertiary" :disabled="offset + pageSize >= total" @click="nextPage">
				{{ t('souvera_mail', 'Next') }}
			</NcButton>
		</div>

		<SpamDetail v-if="selectedItem && showDetail"
			:item="selectedItem"
			:body="detailBody"
			:loading="loadingDetail"
			@close="closeDetail"
			@release="releaseOne(selectedItem)"
			@delete="deleteOne(selectedItem)" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { useSpamClient } from '../composables/useSpamClient.js'
import SpamDetail from '../components/SpamDetail.vue'

const { fetchSpamItems, viewSpamItem, releaseSpamItems, deleteSpamItems } = useSpamClient()

export default {
	name: 'SpamListView',
	components: { NcButton, NcEmptyContent, NcCheckboxRadioSwitch, EmailOutline, TrashCan, CheckAll, AlertCircle, SpamDetail },
	data() {
		return {
			items: [],
			total: 0,
			loading: false,
			error: false,
			offset: 0,
			pageSize: 50,
			selectedItem: null,
			showDetail: false,
			detailBody: null,
			loadingDetail: false,
		}
	},
	computed: {
		checkedIds() {
			return this.items.filter(i => i._checked).map(i => ({ id: i.id, source: i._source }))
		},
	},
	async mounted() {
		await this.loadItems()
	},
	methods: {
		async loadItems() {
			this.loading = true
			this.error = false
			try {
				const res = await fetchSpamItems(this.pageSize, this.offset)
				this.items = (res.items || []).map(item => ({ ...item, _checked: false }))
				this.total = res.total || 0
			} catch (e) {
				console.error('Failed to load spam items', e)
				this.error = true
			} finally {
				this.loading = false
			}
		},
		async openItem(item) {
			this.selectedItem = item
			this.showDetail = true
			this.loadingDetail = true
			try {
				const res = await viewSpamItem(item.id, item._source)
				this.detailBody = res
				if (item._source === 'jmap' && !item.isRead) {
					item.isRead = true
				}
			} catch (e) {
				console.error('Failed to load spam detail', e)
			} finally {
				this.loadingDetail = false
			}
		},
		closeDetail() {
			this.showDetail = false
			this.selectedItem = null
			this.detailBody = null
		},
		async releaseSelected() {
			const checked = this.checkedIds
			if (checked.length === 0) return
			try {
				const jmapIds = checked.filter(c => c.source === 'jmap').map(c => c.id)
				const shieldIds = checked.filter(c => c.source === 'shield').map(c => c.id)
				if (jmapIds.length > 0) await releaseSpamItems(jmapIds, 'jmap')
				if (shieldIds.length > 0) await releaseSpamItems(shieldIds, 'shield')
				showSuccess(this.t('souvera_mail', 'Released {n} items', { n: checked.length }))
				this.offset = 0
				await this.loadItems()
			} catch (e) {
				console.error('Release failed', e)
				showError(this.t('souvera_mail', 'Failed to release'))
			}
		},
		async releaseOne(item) {
			try {
				await releaseSpamItems([item.id], item._source)
				showSuccess(this.t('souvera_mail', 'Released'))
				this.closeDetail()
				await this.loadItems()
			} catch (e) {
				console.error('Release failed', e)
				showError(this.t('souvera_mail', 'Failed to release'))
			}
		},
		async deleteSelected() {
			const checked = this.checkedIds
			if (checked.length === 0) return
			try {
				const jmapIds = checked.filter(c => c.source === 'jmap').map(c => c.id)
				const shieldIds = checked.filter(c => c.source === 'shield').map(c => c.id)
				if (shieldIds.length > 0) await deleteSpamItems(shieldIds, 'shield')
				if (jmapIds.length > 0) await deleteSpamItems(jmapIds, 'jmap')
				showSuccess(this.t('souvera_mail', 'Deleted {n} items', { n: checked.length }))
				this.offset = 0
				await this.loadItems()
			} catch (e) {
				console.error('Delete failed', e)
				showError(this.t('souvera_mail', 'Failed to delete'))
			}
		},
		async deleteOne(item) {
			try {
				await deleteSpamItems([item.id], item._source)
				showSuccess(this.t('souvera_mail', 'Deleted'))
				this.closeDetail()
				await this.loadItems()
			} catch (e) {
				console.error('Delete failed', e)
				showError(this.t('souvera_mail', 'Failed to delete'))
			}
		},
		prevPage() {
			this.offset = Math.max(0, this.offset - this.pageSize)
			this.loadItems()
		},
		nextPage() {
			this.offset = this.offset + this.pageSize
			this.loadItems()
		},
		spamLevelClass(level) {
			if (level < 3) return 'low'
			if (level < 5) return 'medium'
			return 'high'
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) return dateStr
			return d.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.spam-view {
	display: flex; flex-direction: column; height: 100%;
	padding: 0;
}
.spam-view__toolbar {
	display: flex; justify-content: space-between; align-items: center;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
}
.spam-view__title { margin: 0; font-size: 16px; }
.spam-view__actions { display: flex; gap: 8px; }
.spam-view__list { flex: 1; overflow-y: auto; }
.spam-item {
	display: flex; align-items: flex-start; gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}
.spam-item:hover { background: var(--color-background-hover); }
.spam-item--active { background: var(--color-primary-element-light); }
.spam-item__content { flex: 1; min-width: 0; }
.spam-item__top { display: flex; justify-content: space-between; margin-bottom: 2px; }
.spam-item__from { font-weight: 600; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.spam-item__date { font-size: 11px; color: var(--color-text-maxcontrast); flex-shrink: 0; margin-left: 8px; }
.spam-item__subject { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.spam-item__meta { display: flex; gap: 6px; align-items: center; margin-top: 2px; }
.spam-badge {
	font-size: 10px; font-weight: 700; padding: 0 4px; border-radius: 3px;
	color: #fff; line-height: 16px;
}
.spam-badge--low { background: #4caf50; }
.spam-badge--medium { background: #ff9800; }
.spam-badge--high { background: #f44336; }
.spam-item__source { font-size: 10px; padding: 0 4px; border-radius: 3px; }
.spam-item__source--shield { background: var(--color-primary-element); color: #fff; }
.spam-item__source--jmap { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.spam-view__pagination {
	display: flex; justify-content: center; align-items: center; gap: 12px;
	padding: 8px; border-top: 1px solid var(--color-border);
}
.spam-view__pagination-info { font-size: 12px; color: var(--color-text-maxcontrast); }
.spam-view__loading { padding: 12px; }
.spam-view__skeleton { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.skeleton-line { height: 12px; background: var(--color-background-dark); border-radius: 4px; animation: pulse 1.5s ease-in-out infinite; width: 60%; }
.skeleton-line--wide { width: 90%; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
.spam-view__error { padding: 24px; }
</style>
