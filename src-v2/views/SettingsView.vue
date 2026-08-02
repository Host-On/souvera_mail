<template>
	<div class="settings-view">
		<h1>{{ t('souvera_mail', 'Settings') }}</h1>

		<section class="settings-section">
			<h3>{{ t('souvera_mail', 'Storage') }}</h3>
			<div class="quota-row" v-if="quotaTotal > 0">
				<QuotaDonut :used="quotaUsed" :total="quotaTotal" />
				<span>{{ formatSize(quotaUsed) }} / {{ formatSize(quotaTotal) }}</span>
			</div>
			<p v-else class="settings-muted">{{ t('souvera_mail', 'No quota information available') }}</p>
		</section>

		<section class="settings-section">
			<h3>{{ t('souvera_mail', 'Shared folders') }}</h3>
			<p class="settings-muted">{{ t('souvera_mail', 'Shared folders are mailboxes that other users have granted you access to.') }}</p>
			<div class="shared-position-row">
				<NcCheckboxRadioSwitch :checked="sharedAbove" type="radio"
					@update:checked="setSharedPosition(true)">
					{{ t('souvera_mail', 'Show shared folders above own folders') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :checked="!sharedAbove" type="radio"
					@update:checked="setSharedPosition(false)">
					{{ t('souvera_mail', 'Show shared folders below own folders') }}
				</NcCheckboxRadioSwitch>
			</div>
		</section>

		<section class="settings-section">
			<h3>{{ t('souvera_mail', 'App passwords') }}</h3>
			<NcButton variant="primary" @click="showCreate = true">
				<template #icon><Plus :size="20" /></template>
				{{ t('souvera_mail', 'New app password') }}
			</NcButton>

			<div v-if="showCreate" class="create-row">
				<NcTextField v-model:value="newName" :placeholder="t('souvera_mail', 'Name (e.g. Android, iOS)')" />
				<NcButton variant="primary" @click="create" :disabled="newName.trim() === ''">{{ t('souvera_mail', 'Create') }}</NcButton>
				<NcButton variant="tertiary" @click="showCreate = false">{{ t('souvera_mail', 'Cancel') }}</NcButton>
			</div>

			<div v-if="passwords.length > 0" class="password-list">
				<div v-for="pw in passwords" :key="pw.id" class="password-row">
					<span>{{ pw.name }} <code v-if="pw.password">{{ pw.password }}</code></span>
					<NcButton variant="tertiary" size="small" :aria-label="t('souvera_mail', 'Delete')" @click="remove(pw.id)">
						<template #icon><TrashCan :size="16" /></template>
					</NcButton>
				</div>
			</div>
		</section>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import QuotaDonut from '../components/QuotaDonut.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SettingsView',
	components: { NcButton, NcTextField, NcCheckboxRadioSwitch, Plus, TrashCan, QuotaDonut },
	data() { return { quotaUsed:0, quotaTotal:0, passwords:[], showCreate:false, newName:'', sharedAbove:true } },
	async mounted() {
		await Promise.all([this.loadQuota(), this.loadPasswords(), this.loadShared()])
	},
	methods: {
		formatSize(bytes) {
			if (!bytes) return '0 B'; const u = ['B','KB','MB','GB']; let i=0,s=bytes
			while(s>=1024 && i<u.length-1){s/=1024;i++}
			return Math.round(s*10)/10 + ' ' + u[i]
		},
		async loadQuota() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/quota'))
				this.quotaUsed = data.used||0; this.quotaTotal = data.total||0
			} catch {}
		},
		async loadPasswords() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords'))
				this.passwords = data.passwords||[]
			} catch {}
		},
		async create() {
			try {
				const { data } = await axios.post(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords'), { name: this.newName })
				this.passwords.push(data); this.showCreate=false; this.newName=''
			} catch {}
		},
		async remove(id) {
			try {
				await axios.delete(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords/' + id))
				this.passwords = this.passwords.filter(p => p.id !== id)
			} catch {}
		},
		async loadShared() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/shared'))
				this.sharedAbove = data.position === 'above'
			} catch {}
		},
		async setSharedPosition(above) {
			this.sharedAbove = above
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/shared/position'), { position: above ? 'above' : 'below' })
			} catch {}
		},
	},
}
</script>

<style scoped>
.settings-view { padding: 24px; max-width: 640px; margin: 0 auto; }
.settings-view h1 { margin: 0 0 24px; font-size: 20px; }
.settings-section { margin-bottom: 32px; }
.settings-section h3 { margin: 0 0 12px; font-size: 14px; color: var(--color-text-maxcontrast); }
.quota-row { display: flex; align-items: center; gap: 16px; }
.settings-muted { color: var(--color-text-maxcontrast); }
.shared-position-row { display: flex; flex-direction: column; gap: 6px; }
.create-row { display: flex; align-items: center; gap: 8px; margin: 10px 0; }
.password-list { margin-top: 12px; }
.password-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
.password-row code { background: var(--color-background-dark); padding: 1px 6px; border-radius: 4px; }
</style>
