<template>
	<div class="souvera-mail-migration" data-testid="souvera-mail-migration-root">
		<MigrationPill
			v-if="showPill"
			:label="migration.pillLabel.value"
			:state="migration.pillState.value"
			data-testid="migration-pill"
			@click="openWizard" />

		<MigrationWizard
			v-if="isOpen"
			:migration="migration"
			:initial-step="initialStep"
			data-testid="migration-wizard"
			@close="closeWizard"
			@dismiss-forever="onDismissForever" />
	</div>
</template>

<script>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useMigration } from './composables/useMigration.js'
import MigrationPill from './components/MigrationPill.vue'
import MigrationWizard from './components/MigrationWizard.vue'

export default {
	name: 'App',
	components: { MigrationPill, MigrationWizard },
	setup() {
		const migration = useMigration()
		const isOpen = ref(false)
		const initialStep = ref('welcome')

		const showPill = computed(() => migration.available.value)

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
			// Auto-resume active migration → straight to progress screen.
			if (migration.hasActive.value) {
				initialStep.value = 'progress'
				migration.startPolling(5000)
				// Do NOT auto-open the wizard; the pulsing pill is
				// enough — the user clicks it if they want details.
				return
			}
			// First-time welcome — open the wizard automatically unless
			// the user has previously dismissed it.
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
			initialStep,
			showPill,
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
