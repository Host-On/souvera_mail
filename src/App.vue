<template>
	<div class="souvera-mail-migration" data-testid="souvera-mail-migration-root">
		<!--
		v0.14.41: MigrationPill (floating „Alte Mails importieren"
		CTA) removed at operator request — the same entry point
		already lives in Snappymail's top-right dropdown menu
		(see `js/dropdown-menu.js` 📥 entry). Two CTAs for the
		same wizard was redundant. The wizard itself is still
		reachable via the dropdown menu (which dispatches
		`souvera-mail:open-migration`) or the `?openMigration=1`
		URL parameter.
		-->

		<MigrationWizard
			v-if="isOpen"
			:migration="migration"
			:initial-step="initialStep"
			data-testid="migration-wizard"
			@close="closeWizard"
			@dismiss-forever="onDismissForever" />

		<ResyncDialog
			v-if="isResyncOpen"
			data-testid="resync-dialog-root"
			@close="isResyncOpen = false" />
	</div>
</template>

<script>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useMigration } from './composables/useMigration.js'
import MigrationWizard from './components/MigrationWizard.vue'
import ResyncDialog from './components/ResyncDialog.vue'

export default {
	name: 'App',
	components: { MigrationWizard, ResyncDialog },
	setup() {
		const migration = useMigration()
		const isOpen = ref(false)
		const isResyncOpen = ref(false)
		const initialStep = ref('welcome')

		function openWizard(step) {
			// If a job is already running, jump straight to progress.
			if (migration.hasActive.value) {
				initialStep.value = 'progress'
			} else if (typeof step === 'string' && step) {
				initialStep.value = step
			} else {
				initialStep.value = 'welcome'
			}
			isOpen.value = true
		}

		function closeWizard() {
			isOpen.value = false
		}

		async function onDismissForever() {
			try {
				await migration.dismissWelcome()
			} catch (e) {
				// Non-fatal; a dismiss failure just means the welcome
				// popup keeps appearing — no user-visible error needed.
			}
			isOpen.value = false
		}

		onMounted(async () => {
			try {
				await migration.loadState()
			} catch (e) {
				// If backend is unreachable (Central token not set), the
				// wizard stays hidden — matches v0.14.10 semantics.
				return
			}

			// v0.14.12 — user-menu deep-link `?openMigration=1`
			// (from the "Alte Mails importieren" entry in the top-right
			// avatar drop-down). Forces the wizard open regardless of
			// the dismissed flag; the entry point exists precisely for
			// users who previously clicked "Nicht mehr zeigen".
			const forceOpen = new URLSearchParams(window.location.search).get('openMigration') === '1'

			// v0.14.17 — the Snappymail SystemDropDown injects the
			// "Alte Mails importieren" entry client-side and dispatches
			// this event when clicked (no page reload).  Reuses the same
			// force-open semantics as ?openMigration=1.
			window.addEventListener('souvera-mail:open-migration', () => {
				// Refresh state so we jump to the correct screen even
				// after a long idle session.
				migration.loadState()
					.catch(() => { /* silent — treat as fresh session */ })
					.finally(() => {
						if (migration.hasActive.value) {
							initialStep.value = 'progress'
							migration.startPolling(5000)
						} else if (migration.lastJob.value) {
							initialStep.value = 'terminal'
						} else {
							initialStep.value = 'welcome'
						}
						isOpen.value = true
					})
			})

			// v0.14.19 — Snappymail dropdown injects a "↻ Postfach neu
			// synchronisieren" entry that dispatches this event.
			window.addEventListener('souvera-mail:open-resync', () => {
				isResyncOpen.value = true
			})

			// Auto-resume active migration → straight to progress screen.
			if (migration.hasActive.value) {
				initialStep.value = 'progress'
				migration.startPolling(5000)
				if (forceOpen) {
					isOpen.value = true
				}
				return
			}
			if (forceOpen) {
				initialStep.value = migration.lastJob.value ? 'terminal' : 'welcome'
				isOpen.value = true
				return
			}
			// First-time welcome — open the wizard automatically unless
			// the user has previously dismissed it. v0.14.41: the pill
			// is gone, so this auto-open is the ONLY entry point that
			// first-time users see. Kept intentionally.
			if (!migration.dismissed.value && !migration.lastJob.value) {
				initialStep.value = 'welcome'
				isOpen.value = true
			}
		})

		onBeforeUnmount(() => {
			migration.stopPolling()
		})

		return {
			migration,
			isOpen,
			isResyncOpen,
			initialStep,
			openWizard,
			closeWizard,
			onDismissForever,
		}
	},
}
</script>

<style>
/* App-shell scope only — every actual UI element uses NC theme vars via
   the Souvera Design System (see src/styles/forms.css). */
.souvera-mail-migration {
	position: static;
}
</style>
