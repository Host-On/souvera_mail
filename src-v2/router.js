import { createRouter, createWebHashHistory } from 'vue-router'

import MailHomeView from './views/MailHomeView.vue'

const routes = [
	{
		path: '/',
		name: 'inbox',
		component: MailHomeView,
	},
	{
		path: '/compose',
		name: 'compose',
		component: MailHomeView, // placeholder until ComposeView is built
	},
	{
		path: '/search',
		name: 'search',
		component: MailHomeView, // placeholder until SearchView is built
	},
	{
		path: '/shield',
		name: 'shield',
		component: MailHomeView, // placeholder until V2ShieldController is ready
	},
	{
		path: '/settings',
		name: 'settings',
		component: MailHomeView, // placeholder until SettingsView is built
	},
]

export default createRouter({
	history: createWebHashHistory(),
	routes,
})
