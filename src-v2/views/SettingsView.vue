<template>
	<div class="settings-view">
		<h2>{{ t('souvera_mail', 'Settings') }}</h2>

		<section class="settings-section">
			<h3>{{ t('souvera_mail', 'Storage') }}</h3>
			<div v-if="quotaTotal > 0" class="settings-quota">
				<QuotaDonut :used="quotaUsed" :total="quotaTotal" />
				<p>{{ formatSize(quotaUsed) }} / {{ formatSize(quotaTotal) }}</p>
			</div>
		</section>

		<section class="settings-section">
			<h3>{{ t('souvera_mail', 'App passwords') }}</h3>
			<NcButton type="primary" @click="showCreate = true">
				<template #icon><Plus :size="20" /></template>
				{{ t('souvera_mail', 'New app password') }}
			</NcButton>

			<div v-if="showCreate" class="create-form">
				<NcTextField :value.sync="newName" :placeholder="t('souvera_mail', 'Name (e.g. Android, iOS)')" />
				<NcButton type="primary" @click="create" :disabled="newName.trim() === ''">
					{{ t('souvera_mail', 'Create') }}
				</NcButton>
				<NcButton type="tertiary" @click="showCreate = false">{{ t('souvera_mail', 'Cancel') }}</NcButton>
			</div>

			<ul v-if="passwords.length > 0" class="password-list">
				<li v-for="pw in passwords" :key="pw.id" class="password-row">
					<span>{{ pw.name }} <code v-if="pw.password">{{ pw.password }}</code></span>
					<NcButton type="error" size="small" @click="remove(pw.id)">
						<template #icon><TrashCan :size="16" /></template>
					</NcButton>
				</li>
			</ul>
		</section>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import QuotaDonut from '../components/QuotaDonut.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SettingsView',
	components: { NcButton, NcTextField, Plus, TrashCan, QuotaDonut },
	data() {
		return {
			quotaUsed: 0,
			quotaTotal: 0,
			passwords: [],
			showCreate: false,
			newName: '',
		}
	},
	async mounted() {
		await Promise.all([this.loadQuota(), this.loadPasswords()])
	},
	methods: {
		formatSize(bytes) {
			if (!bytes) return '0 B'
			const u = ['B', 'KB', 'MB', 'GB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s * 10) / 10 + ' ' + u[i]
		},
		async loadQuota() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/quota'))
				this.quotaUsed = data.used || 0
				this.quotaTotal = data.total || 0
			} catch (e) { console.error('Quota load failed', e) }
		},
		async loadPasswords() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords'))
				this.passwords = data.passwords || []
			} catch (e) { console.error('Passwords load failed', e) }
		},
		async create() {
			try {
				const { data } = await axios.post(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords'), { name: this.newName })
				this.passwords.push(data)
				this.showCreate = false
				this.newName = ''
			} catch (e) { console.error('Create failed', e) }
		},
		async remove(id) {
			try {
				await axios.delete(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords/' + id))
				this.passwords = this.passwords.filter(p => p.id !== id)
			} catch (e) { console.error('Delete failed', e) }
		},
	},
}
</script>

<style scoped>
.settings-view { padding: 20px; max-width: 600px; margin: 0 auto; }
.settings-section { margin-bottom: 28px; }
.settings-quota { display: flex; align-items: center; gap: 16px; }
.create-form { display: flex; align-items: center; gap: 8px; margin: 10px 0; }
.password-list { list-style: none; margin: 12px 0 0; padding: 0; }
.password-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
.password-row code { background: var(--color-background-dark); padding: 1px 4px; border-radius: 3px; }
</style>
