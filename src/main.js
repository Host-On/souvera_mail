/**
 * Souvera Mail v0.14.11 — Vue 3 mount for the Migration Wizard.
 *
 * Loaded by `PageController::index()` on every logged-in mail page view.
 * The mount point (#souvera-mail-migration-mount) is inserted into
 * <body> by the loader below — no template edit needed.  This keeps
 * the wizard's DOM strictly ABOVE Snappymail's chrome (which uses
 * z-index up to 210) and lets it survive Snappymail bundle refreshes.
 *
 * The App component decides via GET /migration/welcome-state whether
 * to render anything at all — if Central's provider.tools token is
 * missing, the mount stays empty.
 */

import { createApp } from 'vue'
import App from './App.vue'
import './styles/forms.css'

// Nextcloud globals — provided by the host page, referenced as bare
// identifiers throughout the codebase.
// eslint-disable-next-line no-undef
const OC_LOCAL = typeof OC !== 'undefined' ? OC : null

function ensureMountPoint() {
	let mount = document.getElementById('souvera-mail-migration-mount')
	if (!mount) {
		mount = document.createElement('div')
		mount.id = 'souvera-mail-migration-mount'
		mount.setAttribute('data-testid', 'souvera-mail-migration-mount')
		document.body.appendChild(mount)
	}
	return mount
}

function bootstrap() {
	// Do NOT run on the "not configured" page, on OIDC error pages, or
	// on Snappymail popup children — only where the engine has actually
	// booted (#x2m-app is present).
	if (document.getElementById('souvera-mail-bootstrap-hint')) return
	if (document.getElementById('souvera_mail-bootstrap-hint')) return
	// A missing #x2m-app on the very first paint is fine — the engine
	// injects it asynchronously.  We still mount; the wizard's own
	// GET /welcome-state gates every visible thing.
	const mount = ensureMountPoint()
	const app = createApp(App)
	// Nextcloud translation helpers registered globally so any child
	// component can call t() / n() without an explicit import.
	app.config.globalProperties.t = window.t || ((_app, msg) => msg)
	app.config.globalProperties.n = window.n || ((_app, s, p, c) => (c === 1 ? s : p))
	app.config.globalProperties.OC = OC_LOCAL
	app.mount(mount)
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', bootstrap, { once: true })
} else {
	bootstrap()
}
