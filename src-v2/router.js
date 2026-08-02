import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
	{
		path: '/',
		name: 'inbox',
		component: () => import('./views/MailHomeView.vue'),
	},
	{
		path: '/compose',
		name: 'compose',
		component: () => import('./views/ComposeView.vue'),
	},
	{
		path: '/search',
		name: 'search',
		component: () => import('./views/SearchView.vue'),
	},
	{
		path: '/shield',
		name: 'shield',
		component: () => import('./views/ShieldView.vue'),
	},
	{
		path: '/settings',
		name: 'settings',
		component: () => import('./views/SettingsView.vue'),
	},
]

export default createRouter({
	history: createWebHashHistory(),
	routes,
})
