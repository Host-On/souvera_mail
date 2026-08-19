import { createRouter, createWebHashHistory } from 'vue-router'
import MailHomeView from './views/MailHomeView.vue'
import ComposeView from './views/ComposeView.vue'
import SearchView from './views/SearchView.vue'
import ShieldView from './views/ShieldView.vue'
import SettingsView from './views/SettingsView.vue'
import SpamListView from './views/SpamListView.vue'
import ExternalMailView from './views/ExternalMailView.vue'

const routes = [
	{ path: '/', name: 'inbox', component: MailHomeView },
	{ path: '/compose', name: 'compose', component: ComposeView },
	{ path: '/search', name: 'search', component: SearchView },
	{ path: '/spam', name: 'spam', component: SpamListView },
	{ path: '/external/:id', name: 'external', component: ExternalMailView },
	{ path: '/shield', name: 'shield', component: ShieldView },
	{ path: '/settings', name: 'settings', component: SettingsView },
]

export default createRouter({ history: createWebHashHistory(), routes })
