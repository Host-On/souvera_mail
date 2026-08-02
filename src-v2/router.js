import { createRouter, createWebHashHistory } from 'vue-router'
import MailHomeView from './views/MailHomeView.vue'
import ComposeView from './views/ComposeView.vue'
import SearchView from './views/SearchView.vue'
import ShieldView from './views/ShieldView.vue'
import SettingsView from './views/SettingsView.vue'

const routes = [
	{ path: '/', name: 'inbox', component: MailHomeView },
	{ path: '/compose', name: 'compose', component: ComposeView },
	{ path: '/search', name: 'search', component: SearchView },
	{ path: '/shield', name: 'shield', component: ShieldView },
	{ path: '/settings', name: 'settings', component: SettingsView },
]

export default createRouter({ history: createWebHashHistory(), routes })
