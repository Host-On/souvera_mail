/**
 * souvera_mail v2 — Vue 3 Entry Point
 *
 * Parallel to SnappyMail (v1). Activated via feature flag
 * `souvera_mail.v2_enabled` + OCC toggle. Builds to js/souvera_mail-v2.js.
 */

import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import './styles/mail.css'

const app = createApp(App)
app.use(router)
app.mount('#souvera-mail-v2-app')
