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
				:folders="folderList"
				:selected="selectedFolders"
				@advance="onAdvance"
				@back="onBack"
				@retry="onRetry"
				@close="onClose"
				@dismiss-forever="$emit('dismiss-forever')"
				@update:form="onFormUpdate"
				@update:selected="onSelectedUpdate" />
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computed, reactive, ref, watch } from 'vue'

import WelcomeScreen from './screens/WelcomeScreen.vue'
import ImapFormScreen from './screens/ImapFormScreen.vue'
import FolderMappingScreen from './screens/FolderMappingScreen.vue'
import ConfirmScreen from './screens/ConfirmScreen.vue'
import ProgressScreen from './screens/ProgressScreen.vue'
import TerminalScreen from './screens/TerminalScreen.vue'

/**
 * v0.14.14 — new `mapping` step between `form` and `confirm`.
 *
 * provider.tools 2026-02 now requires a non-empty `folders` array in
 * the start payload, so the wizard must let the user pick source
 * folders before we can call /start. We also use the mapping screen
 * to communicate a nice UX truth: standard-role folders (INBOX, Sent,
 * Drafts, Trash, Junk, Archive) land in the corresponding canonical
 * Souvera folders — no manual rename needed.
 */
const SCREENS = {
	welcome: WelcomeScreen,
	form: ImapFormScreen,
	mapping: FolderMappingScreen,
	confirm: ConfirmScreen,
	progress: ProgressScreen,
	terminal: TerminalScreen,
}

// System folders we DON'T pre-select — user can opt in.
function isSystemFolder(path) {
	if (!path) return false
	const p = String(path).toLowerCase()
	if (p.startsWith('[gmail]') || p.startsWith('[google mail]')) return true
	if (p.startsWith('.') || p.includes('/.')) return true
	if (p === 'outbox' || p === 'postausgang') return true
	return false
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
		const folderList = ref([])      // full [{path, messages}, …] for mapping screen
		const selectedFolders = ref(new Set()) // Set<string>
		const testResult = ref(null)    // {ok: true} | {ok: false, error: '…'}
		const startError = ref('')

		const currentScreenComponent = computed(() => SCREENS[step.value] || SCREENS.welcome)

		const dialogTitle = computed(() => {
			switch (step.value) {
				case 'welcome':  return t('souvera_mail', 'Import old mail')
				case 'form':     return t('souvera_mail', 'Connection to old mailbox')
				case 'mapping':  return t('souvera_mail', 'Select folders')
				case 'confirm':  return t('souvera_mail', 'Confirm import')
				case 'progress': return t('souvera_mail', 'Import running')
				case 'terminal': return t('souvera_mail', 'Import complete')
				default:         return t('souvera_mail', 'Import wizard')
			}
		})

		// Terminal-state watcher on the progress screen.
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
		function onSelectedUpdate(next) {
			selectedFolders.value = next
		}

		async function onAdvance() {
			try {
				if (step.value === 'welcome') {
					step.value = 'form'
					return
				}
				if (step.value === 'form') {
					isBusy.value = true
					testResult.value = null
					folderPreview.value = null
					folderList.value = []
					const conn = { host: form.host, port: Number(form.port), username: form.username, password: form.password, tls: !!form.tls }
					let t1
					try {
						t1 = await props.migration.testConnection(conn)
					} catch (e) {
						testResult.value = { ok: false, error: e?.message || t('souvera_mail', 'Connection failed.') }
						return
					}
					if (!t1?.ok) {
						testResult.value = { ok: false, error: t1?.message || t('souvera_mail', 'Connection failed.') }
						return
					}
					testResult.value = { ok: true }
					// Full folder inventory — needed by the mapping screen.
					// Failure here IS fatal because we can't ask
					// provider.tools to migrate an empty folder list.
					try {
						const fp = await props.migration.listFolders(conn)
						folderList.value = Array.isArray(fp?.folders) ? fp.folders : []
						folderPreview.value = {
							folders: fp?.folder_count ?? folderList.value.length,
							messages: fp?.message_count ?? 0,
						}
					} catch (e) {
						testResult.value = { ok: false, error: e?.message || t('souvera_mail', 'Could not fetch the folder list.') }
						return
					}
					if (folderList.value.length === 0) {
						testResult.value = { ok: false, error: t('souvera_mail', 'No folders were found in the old mailbox.') }
						return
					}
					// Auto-preselect all NON-system folders.
					selectedFolders.value = new Set(
						folderList.value
							.map(f => String(f.path || ''))
							.filter(p => p && !isSystemFolder(p))
					)
					step.value = 'mapping'
					return
				}
				if (step.value === 'mapping') {
					if (selectedFolders.value.size === 0) return
					step.value = 'confirm'
					return
				}
				if (step.value === 'confirm') {
					isBusy.value = true
					startError.value = ''
					const conn = { host: form.host, port: Number(form.port), username: form.username, password: form.password, tls: !!form.tls }
					const folders = Array.from(selectedFolders.value)
					try {
						await props.migration.startMigration(conn, folders)
					} catch (e) {
						startError.value = e?.message || t('souvera_mail', 'Could not start the import.')
						return
					}
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
			else if (step.value === 'mapping') step.value = 'form'
			else if (step.value === 'confirm') step.value = 'mapping'
		}

		function onRetry() {
			step.value = 'form'
			startError.value = ''
			testResult.value = null
			folderPreview.value = null
			folderList.value = []
			selectedFolders.value = new Set()
		}

		function onClose() {
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
			folderList,
			selectedFolders,
			testResult,
			startError,
			currentScreenComponent,
			dialogTitle,
			onAdvance,
			onBack,
			onRetry,
			onClose,
			onFormUpdate,
			onSelectedUpdate,
			onUpdateOpen,
		}
	},
}
</script>

<style>
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
