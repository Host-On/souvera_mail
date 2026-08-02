<template>
	<div class="search-view">
		<div class="search-bar">
			<NcTextField v-model:value="q" :placeholder="t('souvera_mail', 'Search emails...')"
				@input="debounceSearch" @keyup.enter="doSearch" />
			<NcButton type="primary" @click="doSearch" :disabled="q.trim() === ''">
				<template #icon><Magnify :size="20" /></template>
			</NcButton>
		</div>

		<div v-if="searching" class="search-loading">
			<span class="icon-loading" />
		</div>

		<ul v-else-if="results.length > 0" class="search-results">
			<li v-for="email in results" :key="email.id" class="search-row"
				@click="openEmail(email)">
				<div class="search-row__header">
					<span class="search-row__from">{{ email.fromName || email.fromAddress }}</span>
					<span class="search-row__date">{{ formatDate(email.receivedAt) }}</span>
				</div>
				<div class="search-row__subject">{{ email.subject || '(no subject)' }}</div>
				<div class="search-row__preview">{{ email.preview }}</div>
			</li>
		</ul>

		<NcEmptyContent v-else-if="q && !searching"
			:title="t('souvera_mail', 'No results')">
			<template #icon><Magnify :size="64" /></template>
		</NcEmptyContent>

		<NcEmptyContent v-else
			:title="t('souvera_mail', 'Search your emails')">
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
	data() {
		return {
			q: '',
			results: [],
			searching: false,
		}
	},
	methods: {
		formatDate(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
		},
		debounceSearch() {
			clearTimeout(timer)
			timer = setTimeout(() => this.doSearch(), 400)
		},
		async doSearch() {
			if (this.q.trim() === '') return
			this.searching = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/search'), {
					params: { q: this.q, limit: 50 },
				})
				this.results = data.results || []
			} catch (e) {
				console.error('Search failed', e)
			} finally {
				this.searching = false
			}
		},
		openEmail(email) {
			this.$router.push({ name: 'inbox' })
		},
	},
	beforeUnmount() { clearTimeout(timer) },
}
</script>

<style scoped>
.search-view { padding: 16px; max-width: 720px; margin: 0 auto; }
.search-bar { display: flex; gap: 8px; margin-bottom: 16px; }
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
