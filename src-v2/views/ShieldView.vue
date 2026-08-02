<template>
	<div class="shield-view">
		<h1>{{ t('souvera_mail', 'Security') }}</h1>

		<section class="shield-section">
			<h3>{{ t('souvera_mail', 'Junk mailbox') }}</h3>
			<div v-if="loading" class="shield-loading"><span class="icon-loading" /></div>
			<div v-else-if="junk.length > 0" class="junk-list">
				<div v-for="email in junk" :key="email.id" class="junk-row">
					<div class="junk-info">
						<strong>{{ email.fromName || email.fromAddress }}</strong>
						<span class="junk-subject">{{ email.subject || '(no subject)' }}</span>
					</div>
					<div class="junk-actions">
						<NcButton type="tertiary" size="small" @click="report(email.id, 'notspam')">
							{{ t('souvera_mail', 'Not spam') }}
						</NcButton>
						<NcButton type="tertiary" size="small" @click="report(email.id, 'spam')">
							{{ t('souvera_mail', 'Spam') }}
						</NcButton>
					</div>
				</div>
			</div>
			<NcEmptyContent v-else :title="t('souvera_mail', 'Junk mailbox is empty')">
				<template #icon><ShieldCheck :size="64" /></template>
			</NcEmptyContent>
		</section>
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
	data() { return { junk: [], loading: false } },
	async mounted() { await this.loadJunk() },
	methods: {
		async loadJunk() {
			this.loading = true
			try { const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/shield/quarantine')); this.junk = data.emails || [] }
			catch {} finally { this.loading = false }
		},
		async report(emailId, action) {
			try { await axios.post(generateUrl('/apps/souvera_mail/api/v2/shield/report'), { emailId, action }); await this.loadJunk() }
			catch {}
		},
	},
}
</script>

<style scoped>
.shield-view { padding: 24px; }
.shield-view h1 { margin: 0 0 24px; font-size: 20px; }
.shield-section { margin-bottom: 32px; }
.shield-section h3 { margin: 0 0 12px; font-size: 14px; color: var(--color-text-maxcontrast); }
.shield-loading { display: flex; justify-content: center; padding: 48px; }
.junk-list { border: 1px solid var(--color-border); border-radius: 8px; overflow: hidden; }
.junk-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-bottom: 1px solid var(--color-border); }
.junk-row:last-child { border-bottom: none; }
.junk-subject { display: block; font-size: 12px; color: var(--color-text-maxcontrast); }
.junk-actions { display: flex; gap: 4px; flex-shrink: 0; }
</style>
