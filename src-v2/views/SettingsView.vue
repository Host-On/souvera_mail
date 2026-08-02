<template>
	<div class="settings-view">
		<h1>{{ t('souvera_mail', 'Settings') }}</h1>

		<NcSettingsSection :name="t('souvera_mail', 'Account')">
			<div class="settings-info">
				<div><strong>{{ t('souvera_mail', 'Email') }}:</strong> {{ prefs.account.email || '—' }}</div>
			</div>
			<div class="quota-row" v-if="quotaTotal > 0">
				<QuotaDonut :used="quotaUsed" :total="quotaTotal" />
				<span>{{ formatSize(quotaUsed) }} / {{ formatSize(quotaTotal) }}</span>
			</div>
			<p v-else class="settings-muted">{{ t('souvera_mail', 'No quota information available') }}</p>
		</NcSettingsSection>

		<NcSettingsSection :name="t('souvera_mail', 'Signature')">
			<div class="settings-row">
				<NcCheckboxRadioSwitch :model-value="prefs.signatureEnabled"
					@update:modelValue="sigEnabled = $event; saveSig()">
					{{ t('souvera_mail', 'Append signature') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div v-if="sigEnabled || prefs.signatureEnabled" class="settings-row">
				<textarea class="signature-textarea" v-model="sigHtml"
					:placeholder="t('souvera_mail', '--\nYour signature')"
					rows="5"
					@blur="saveSig" />
			</div>
			<NcButton variant="primary" @click="saveSig" :disabled="!sigDirty">
				{{ t('souvera_mail', 'Save signature') }}
			</NcButton>
		</NcSettingsSection>

		<NcSettingsSection :name="t('souvera_mail', 'Appearance')">
			<div class="settings-row">
				<label>{{ t('souvera_mail', 'Messages per page') }}</label>
				<NcSelect v-model="msgsPerPage" :options="pageSizeOptions"
					@update:modelValue="val => { prefs.messagesPerPage = val; savePref({ messagesPerPage: val }) }" />
			</div>
			<div class="settings-row">
				<NcCheckboxRadioSwitch :model-value="prefs.readingPane"
					@update:modelValue="val => { prefs.readingPane = val; savePref({ readingPane: val }) }">
					{{ t('souvera_mail', 'Show reading pane') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="settings-row">
				<NcCheckboxRadioSwitch :model-value="prefs.remoteImages === 'always'"
					@update:modelValue="val => { prefs.remoteImages = val ? 'always' : 'never'; savePref({ remoteImages: prefs.remoteImages }) }">
					{{ t('souvera_mail', 'Always load external images') }}
				</NcCheckboxRadioSwitch>
				<p class="settings-muted">{{ t('souvera_mail', 'External images can be used to track you. Enable only if needed.') }}</p>
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('souvera_mail', 'Shared folders')">
			<p class="settings-muted">{{ t('souvera_mail', 'Shared folders are mailboxes that other users have granted you access to.') }}</p>
			<div class="shared-position-row">
				<NcCheckboxRadioSwitch :model-value="sharedAbove" type="radio"
					@update:modelValue="setSharedPosition(true)">
					{{ t('souvera_mail', 'Show shared folders above own folders') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :model-value="!sharedAbove" type="radio"
					@update:modelValue="setSharedPosition(false)">
					{{ t('souvera_mail', 'Show shared folders below own folders') }}
				</NcCheckboxRadioSwitch>
			</div>
		</NcSettingsSection>

		<NcSettingsSection :name="t('souvera_mail', 'App passwords')">
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
					<span>{{ pw.name }} <code v-if="pw.password">{{ pw.password }}</code></span>
					<NcButton variant="tertiary" size="small" :aria-label="t('souvera_mail', 'Delete')" @click="remove(pw.id)">
						<template #icon><TrashCan :size="16" /></template>
					</NcButton>
				</div>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcCheckboxRadioSwitch, NcSettingsSection, NcSelect } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import QuotaDonut from '../components/QuotaDonut.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { usePreferences } from '../composables/usePreferences.js'

const { state: prefs, load: loadPrefs, save: savePref } = usePreferences()

export default {
	name: 'SettingsView',
	components: { NcButton, NcTextField, NcCheckboxRadioSwitch, NcSettingsSection, NcSelect, Plus, TrashCan, QuotaDonut },
	data() {
		return {
			quotaUsed: 0, quotaTotal: 0, passwords: [], showCreate: false, newName: '', sharedAbove: true,
			prefs,
			pageSizeOptions: [
				{ value: 25, label: '25' },
				{ value: 50, label: '50' },
				{ value: 100, label: '100' },
			],
			msgsPerPage: { value: 50, label: '50' },
			sigHtml: '',
			sigEnabled: false,
			sigDirty: false,
		}
	},
	async mounted() {
		await Promise.all([this.loadQuota(), this.loadPasswords(), this.loadShared(), loadPrefs()])
		this.sigHtml = this.prefs.signatureHtml || ''
		this.sigEnabled = this.prefs.signatureEnabled
		const pp = this.pageSizeOptions.find(o => o.value === this.prefs.messagesPerPage)
		if (pp) this.msgsPerPage = pp
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
			} catch (e) { console.error('Failed to load quota', e) }
		},
		async loadPasswords() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords'))
				this.passwords = data.passwords||[]
			} catch (e) { console.error('Failed to load passwords', e) }
		},
		async create() {
			try {
				const { data } = await axios.post(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords'), { name: this.newName })
				this.passwords.push(data); this.showCreate=false; this.newName=''
			} catch (e) { console.error('Failed to create password', e) }
		},
		async remove(id) {
			try {
				await axios.delete(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords/' + id))
				this.passwords = this.passwords.filter(p => p.id !== id)
			} catch (e) { console.error('Failed to remove password', e) }
		},
		async loadShared() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/shared'))
				this.sharedAbove = data.position === 'above'
			} catch (e) { console.error('Failed to load shared', e) }
		},
		async setSharedPosition(above) {
			this.sharedAbove = above
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/shared/position'), { position: above ? 'above' : 'below' })
			} catch (e) { console.error('Failed to set shared position', e) }
		},
		savePref,
		async saveSig() {
			this.sigDirty = false
			await savePref({ signatureHtml: this.sigHtml, signatureEnabled: this.sigEnabled })
		},
	},
	watch: {
		sigHtml() { this.sigDirty = true },
		sigEnabled() { this.sigDirty = true },
	},
}
</script>

<style scoped>
.settings-view { padding: 24px; max-width: 720px; margin: 0 auto; }
.settings-view h1 { margin: 0 0 24px; font-size: 20px; }
.settings-info { margin-bottom: 12px; font-size: 14px; }
.settings-row { margin-bottom: 12px; }
.settings-row label { display: block; font-size: 13px; color: var(--color-text-maxcontrast); margin-bottom: 4px; }
.settings-muted { color: var(--color-text-maxcontrast); font-size: 12px; }
.quota-row { display: flex; align-items: center; gap: 16px; margin-top: 8px; }
.shared-position-row { display: flex; flex-direction: column; gap: 6px; }
.create-row { display: flex; align-items: center; gap: 8px; margin: 10px 0; }
.password-list { margin-top: 12px; }
.password-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
.password-row code { background: var(--color-background-dark); padding: 1px 6px; border-radius: 4px; }
.signature-textarea { width: 100%; border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 8px 12px; font: inherit; font-size: 13px; resize: vertical; background: var(--color-main-background); color: var(--color-main-text); }
</style>
