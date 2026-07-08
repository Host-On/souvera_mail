<template>
	<NcDialog
		:name="dialogTitle"
		size="normal"
		:no-close="isBusy"
		out-transition
		container="body"
		data-testid="migration-wizard-dialog"
		@closing="onClose"
		@update:open="onUpdateOpen">

		<div class="souvera-migration-wizard souvera-content">
			<component
				:is="currentScreenComponent"
				:migration="migration"
				:form="form"
				:folder-preview="folderPreview"
				:test-result="testResult"
				:start-error="startError"
				:is-busy="isBusy"
				@advance="onAdvance"
				@back="onBack"
				@retry="onRetry"
				@close="onClose"
				@dismiss-forever="$emit('dismiss-forever')"
				@update:form="onFormUpdate" />
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computed, reactive, ref, watch } from 'vue'

import WelcomeScreen from './screens/WelcomeScreen.vue'
import ImapFormScreen from './screens/ImapFormScreen.vue'
import ConfirmScreen from './screens/ConfirmScreen.vue'
import ProgressScreen from './screens/ProgressScreen.vue'
import TerminalScreen from './screens/TerminalScreen.vue'

const SCREENS = {
	welcome: WelcomeScreen,
	form: ImapFormScreen,
	confirm: ConfirmScreen,
	progress: ProgressScreen,
	terminal: TerminalScreen,
}

export default {
	name: 'MigrationWizard',
	components: { NcDialog },
	props: {
		migration: { type: Object, required: true },
		initialStep: { type: String, default: 'welcome' },
	},
	emits: ['close', 'dismiss-forever'],
	setup(props, { emit }) {
		const step = ref(props.initialStep)
		const isBusy = ref(false)
		const form = reactive({
			host: '',
			port: 993,
			username: '',
			password: '',
			tls: true,
		})
		const folderPreview = ref(null) // {folders: n, messages: n}
		const testResult = ref(null)    // {ok: true} | {ok: false, error: '…'}
		const startError = ref('')

		const currentScreenComponent = computed(() => SCREENS[step.value] || SCREENS.welcome)

		const dialogTitle = computed(() => {
			switch (step.value) {
				case 'welcome':  return t('souvera_mail', 'Alte Mails importieren')
				case 'form':     return t('souvera_mail', 'Verbindung zum alten Postfach')
				case 'confirm':  return t('souvera_mail', 'Import bestätigen')
				case 'progress': return t('souvera_mail', 'Import läuft')
				case 'terminal': return t('souvera_mail', 'Import abgeschlossen')
				default:         return t('souvera_mail', 'Import-Assistent')
			}
		})

		// If the migration reports a terminal state while we're on
		// the progress screen, jump automatically to terminal.
		watch(
			() => props.migration.jobState.value,
			(next) => {
				if (step.value === 'progress' && props.migration.isTerminal(next)) {
					step.value = 'terminal'
				}
			},
		)

		function onFormUpdate(patch) {
			Object.assign(form, patch)
		}

		async function onAdvance(payload) {
			try {
				if (step.value === 'welcome') {
					step.value = 'form'
					return
				}
				if (step.value === 'form') {
					isBusy.value = true
					testResult.value = null
					folderPreview.value = null
					const conn = { host: form.host, port: Number(form.port), username: form.username, password: form.password, tls: !!form.tls }
					const t1 = await props.migration.testConnection(conn)
					if (!t1?.ok) {
						testResult.value = { ok: false, error: t1?.message || t('souvera_mail', 'Verbindung fehlgeschlagen.') }
						return
					}
					testResult.value = { ok: true }
					// Optional folder preview — non-fatal on error.
					try {
						const fp = await props.migration.listFolders(conn)
						folderPreview.value = {
							folders: fp?.folders?.length || fp?.folder_count || 0,
							messages: fp?.message_count || 0,
						}
					} catch (e) { folderPreview.value = null }
					step.value = 'confirm'
					return
				}
				if (step.value === 'confirm') {
					isBusy.value = true
					startError.value = ''
					const conn = { host: form.host, port: Number(form.port), username: form.username, password: form.password, tls: !!form.tls }
					try {
						await props.migration.startMigration(conn)
					} catch (e) {
						startError.value = e?.message || t('souvera_mail', 'Import konnte nicht gestartet werden.')
						return
					}
					// Wipe the source-provider password from memory immediately.
					form.password = ''
					step.value = 'progress'
					props.migration.startPolling(5000)
					return
				}
				if (step.value === 'terminal') {
					emit('close')
					return
				}
			} finally {
				isBusy.value = false
			}
		}

		function onBack() {
			if (step.value === 'form') step.value = 'welcome'
			else if (step.value === 'confirm') step.value = 'form'
		}

		function onRetry() {
			step.value = 'form'
			startError.value = ''
			testResult.value = null
			folderPreview.value = null
		}

		function onClose() {
			// Never allow force-close while a network request is in
			// flight — the dialog's `no-close` prop already blocks
			// the header ✕; this is defence-in-depth.
			if (isBusy.value) return
			props.migration.stopPolling()
			emit('close')
		}

		function onUpdateOpen(open) {
			if (!open && !isBusy.value) emit('close')
		}

		return {
			step,
			isBusy,
			form,
			folderPreview,
			testResult,
			startError,
			currentScreenComponent,
			dialogTitle,
			onAdvance,
			onBack,
			onRetry,
			onClose,
			onFormUpdate,
			onUpdateOpen,
		}
	},
}
</script>

<style>
/* Give the NcDialog body a comfortable, Central-aligned padding.
   Scoped to the wizard root so we never leak into other dialogs. */
.souvera-migration-wizard {
	padding: var(--sc-field-gap);
	color: var(--color-main-text);
	line-height: 1.6;
	font-size: 0.95rem;
}
.souvera-migration-wizard .souvera-section + .souvera-section {
	margin-top: var(--sc-section-gap);
}
.souvera-migration-wizard .souvera-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: var(--sc-section-gap);
	padding-top: var(--sc-field-gap);
	border-top: 1px solid var(--color-border);
}
.souvera-migration-wizard .souvera-actions--split {
	justify-content: space-between;
}
</style>
