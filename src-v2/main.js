/**
 * souvera_mail v2 — Vue 3 Entry Point
 *
 * Parallel to SnappyMail (v1). Activated via feature flag
 * `souvera_mail.v2_enabled` + OCC toggle. Builds to js/souvera_mail-v2.js.
 */

import { createApp } from 'vue'
import { setApp } from '@nextcloud/vue'
import App from './App.vue'
import router from './router'
import './styles/mail.css'

function bootstrap() {
	const mount = document.getElementById('souvera-mail-v2-app')
	if (!mount) return

	const app = createApp(App)

	app.config.globalProperties.t = window.t || ((_app, msg) => msg)
	app.config.globalProperties.n = window.n || ((_app, s, p, count) => count === 1 ? s : p)
	app.config.globalProperties.OC = typeof OC !== 'undefined' ? OC : null

	setApp('souvera_mail')

	app.use(router)
	app.mount('#souvera-mail-v2-app')
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', bootstrap, { once: true })
} else {
	bootstrap()
}
