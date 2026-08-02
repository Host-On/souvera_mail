<template>
	<div class="search-view">
		<div class="search-bar">
			<NcTextField v-model="q" :placeholder="t('souvera_mail', 'Search emails...')"
				class="search-input" @keyup.enter="doSearch" />
			<NcButton variant="primary" @click="doSearch" :disabled="q.trim() === ''">
				<template #icon><Magnify :size="20" /></template>
			</NcButton>
		</div>
		<div v-if="searching" class="search-loading"><span class="icon-loading" /></div>
		<ul v-else-if="results.length > 0" class="search-results">
			<li v-for="r in results" :key="r.id" class="search-row">
				<div class="search-row__header">
					<span class="search-row__from">{{ r.fromName || r.fromAddress }}</span>
					<span class="search-row__date">{{ formatDate(r.receivedAt) }}</span>
				</div>
				<div class="search-row__subject">{{ r.subject || '(no subject)' }}</div>
				<div class="search-row__preview">{{ r.preview }}</div>
			</li>
		</ul>
		<NcEmptyContent v-else-if="q && !searching" :title="t('souvera_mail', 'No results')">
			<template #icon><Magnify :size="64" /></template>
		</NcEmptyContent>
		<NcEmptyContent v-else :title="t('souvera_mail', 'Search your emails')">
			<template #icon><Magnify :size="64" /></template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcEmptyContent } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

let timer = null
export default {
	name: 'SearchView',
	components: { NcTextField, NcButton, NcEmptyContent, Magnify },
	data() { return { q: '', results: [], searching: false } },
	methods: {
		formatDate(iso) { try { return new Date(iso).toLocaleDateString([], { month:'short', day:'numeric' }) } catch { return iso } },
		async doSearch() {
			if (this.q.trim() === '') return; this.searching = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/search'), { params: { q: this.q, limit: 50 } })
				this.results = data.results || []
			} catch { this.results = [] } finally { this.searching = false }
		},
	},
	beforeUnmount() { clearTimeout(timer) },
}
</script>

<style scoped>
.search-view { padding: 20px; max-width: 720px; margin: 0 auto; }
.search-bar { display: flex; gap: 8px; margin-bottom: 16px; }
.search-input { flex: 1; }
.search-loading { display: flex; justify-content: center; padding: 48px; }
.search-results { list-style: none; margin: 0; padding: 0; }
.search-row { padding: 10px 12px; border-bottom: 1px solid var(--color-border); cursor: pointer; }
.search-row:hover { background: var(--color-background-hover); }
.search-row__header { display: flex; justify-content: space-between; }
.search-row__from { font-weight: 600; }
.search-row__date { font-size: 12px; color: var(--color-text-maxcontrast); }
.search-row__subject { font-size: 13px; margin-top: 2px; }
.search-row__preview { font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
