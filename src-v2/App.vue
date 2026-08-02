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
import Send from 'vue-material-design-icons/Send.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Shield from 'vue-material-design-icons/Shield.vue'
import QuotaDonut from './components/QuotaDonut.vue'

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
	methods: {
		navigate(id) {
			this.$router.push({ name: id })
		},
	},
}
</script>
