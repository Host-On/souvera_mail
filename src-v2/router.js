import { createRouter, createWebHashHistory } from 'vue-router'
import MailHomeView from './views/MailHomeView.vue'

const routes = [
	{ path: '/', name: 'inbox', component: MailHomeView },
	{ path: '/compose', name: 'compose', component: () => import('./views/ComposeView.vue') },
	{ path: '/search', name: 'search', component: () => import('./views/SearchView.vue') },
	{ path: '/shield', name: 'shield', component: () => import('./views/ShieldView.vue') },
	{ path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue') },
]

const router = createRouter({
	history: createWebHashHistory(),
	routes,
})

router.onError((error) => {
	console.warn('Router: chunk load failed', error)
})

export default router
