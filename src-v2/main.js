/**
 * souvera_mail v2 — Vue 3 Entry Point
 */

import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import './styles/mail.css'
import '@nextcloud/dialogs/style.css'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// The v2 template injects the catalog inline with a CSP nonce
// (window._souvera_mail_translations). On Nextcloud versions without the
// $cspNonce template variable that inline script is silently CSP-blocked —
// so when the injected catalog is missing/empty we fetch it at runtime
// from /api/v2/l10n before mounting. This guarantees translations on every
// supported NC version.
async function loadTranslations() {
	const inline = window._souvera_mail_translations
	if (inline && typeof inline === 'object' && Object.keys(inline).length > 0) {
		return inline
	}
	try {
		const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/l10n'))
		if (data && data.translations && typeof data.translations === 'object') {
			return data.translations
		}
	} catch (e) {
		console.warn('Failed to load translations from API', e)
	}
	return {}
}

async function bootstrap() {
	const mount = document.getElementById('souvera-mail-v2-app')
	if (!mount) return

	const translations = await loadTranslations()
	function appT(app, msg, vars) {
		let result = translations[msg] || msg
		if (vars && typeof vars === 'object') {
			for (const key of Object.keys(vars)) {
				result = result.replace('{' + key + '}', String(vars[key]))
			}
		}
		return result
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
