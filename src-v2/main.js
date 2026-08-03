/**
 * souvera_mail v2 — Vue 3 Entry Point
 */

import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import './styles/mail.css'
import '@nextcloud/dialogs/style.css'

function bootstrap() {
	const mount = document.getElementById('souvera-mail-v2-app')
	if (!mount) return

	// Read translations from mount point data attribute — CSP-proof.
	let translations = {}
	try {
		const raw = mount.getAttribute('data-translations')
		if (raw) translations = JSON.parse(raw)
	} catch {}
	function appT(app, msg) {
		return translations[msg] || msg
	}

	const app = createApp(App)
	app.config.globalProperties.t = appT
	app.config.globalProperties.n = window.n || ((app, singular, plural, count) => count === 1 ? singular : plural)
	app.config.globalProperties.OC = typeof OC !== 'undefined' ? OC : null
	app.mixin({
		methods: {
			t(...args) { return appT(...args) },
			n(...args) { return (window.n || ((a, s, p, c) => c === 1 ? s : p))(...args) },
		},
	})

	app.use(router)
	app.mount('#souvera-mail-v2-app')
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', bootstrap, { once: true })
} else {
	bootstrap()
}
