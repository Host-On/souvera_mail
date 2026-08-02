<template>
	<NcContent app-name="souvera_mail">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					v-for="item in navItems"
					:key="item.id"
					:name="item.label"
					:active="currentRoute === item.id"
					@click="navigate(item.id)">
					<template #icon>
						<component :is="item.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<QuotaDonut
					v-if="quotaTotal > 0"
					:used="quotaUsed"
					:total="quotaTotal" />
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<router-view />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent } from '@nextcloud/vue'
import Inbox from 'vue-material-design-icons/Inbox.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Shield from 'vue-material-design-icons/Shield.vue'
import QuotaDonut from './components/QuotaDonut.vue'
import { usePush } from './composables/usePush.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const push = usePush()

export default {
	name: 'MailV2App',
	components: { NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent, QuotaDonut },
	data() {
		return {
			quotaTotal: 0,
			quotaUsed: 0,
			navItems: [
				{ id: 'inbox', label: t('souvera_mail', 'Inbox'), icon: Inbox },
				{ id: 'compose', label: t('souvera_mail', 'Compose'), icon: Pencil },
				{ id: 'search', label: t('souvera_mail', 'Search'), icon: Magnify },
				{ id: 'shield', label: t('souvera_mail', 'Security'), icon: Shield },
				{ id: 'settings', label: t('souvera_mail', 'Settings'), icon: Cog },
			],
		}
	},
	computed: {
		currentRoute() {
			return this.$route.name || 'inbox'
		},
	},
	async mounted() {
		await this.loadQuota()
		push.on('quotaChanged', () => this.loadQuota())
		push.connect()
	},
	beforeUnmount() {
		push.cleanup()
	},
	methods: {
		navigate(id) { this.$router.push({ name: id }) },
		async loadQuota() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/quota'))
				this.quotaUsed = data.used ?? 0
				this.quotaTotal = data.total ?? 0
			} catch { /* ignore */ }
		},
	},
}
</script>

<style>
/* CSS injected by the bundle via vue-loader scope; no external stylesheet needed */
</style>
