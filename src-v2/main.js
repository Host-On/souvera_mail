/**
 * souvera_mail v2 — Vue 3 Entry Point
 */

import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import './styles/mail.css'

function bootstrap() {
	const mount = document.getElementById('souvera-mail-v2-app')
	if (!mount) return

	const app = createApp(App)

	const tFn = window.t || ((app, msg) => msg)
	const nFn = window.n || ((app, singular, plural, count) => count === 1 ? singular : plural)
	app.config.globalProperties.t = tFn
	app.config.globalProperties.n = nFn
	app.config.globalProperties.OC = typeof OC !== 'undefined' ? OC : null
	app.mixin({
		methods: {
			t(...args) { return tFn(...args) },
			n(...args) { return nFn(...args) },
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
