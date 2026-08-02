<template>
	<div class="shield-view">
		<h2>{{ t('souvera_mail', 'Security') }}</h2>

		<h3>{{ t('souvera_mail', 'Junk mailbox') }}</h3>
		<div v-if="loading" class="shield-loading">
			<span class="icon-loading" />
		</div>
		<ul v-else-if="junk.length > 0" class="shield-list">
			<li v-for="email in junk" :key="email.id" class="shield-row">
				<div>
					<strong>{{ email.fromName || email.fromAddress }}</strong>
					<span class="shield-subject">{{ email.subject || '(no subject)' }}</span>
				</div>
				<div class="shield-actions">
					<NcButton type="success" size="small" @click="report(email.id, 'notspam')">
						{{ t('souvera_mail', 'Not spam') }}
					</NcButton>
					<NcButton type="error" size="small" @click="report(email.id, 'spam')">
						{{ t('souvera_mail', 'Spam') }}
					</NcButton>
				</div>
			</li>
		</ul>
		<NcEmptyContent v-else :title="t('souvera_mail', 'Junk mailbox is empty')">
			<template #icon><ShieldCheck :size="64" /></template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ShieldView',
	components: { NcButton, NcEmptyContent, ShieldCheck },
	data() {
		return { junk: [], loading: false }
	},
	async mounted() { await this.loadJunk() },
	methods: {
		async loadJunk() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/shield/quarantine'))
				this.junk = data.emails || []
			} catch (e) {
				console.error('Shield load failed', e)
			} finally { this.loading = false }
		},
		async report(emailId, action) {
			try {
				await axios.post(generateUrl('/apps/souvera_mail/api/v2/shield/report'), { emailId, action })
				this.loadJunk()
			} catch (e) { console.error('Report failed', e) }
		},
	},
}
</script>

<style scoped>
.shield-view { padding: 20px; }
.shield-loading { display: flex; justify-content: center; padding: 48px; }
.shield-list { list-style: none; margin: 0; padding: 0; }
.shield-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-bottom: 1px solid var(--color-border); }
.shield-subject { display: block; font-size: 12px; color: var(--color-text-maxcontrast); }
.shield-actions { display: flex; gap: 4px; flex-shrink: 0; }
</style>
