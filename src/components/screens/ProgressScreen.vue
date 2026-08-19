<template>
	<div class="souvera-progress" data-testid="wizard-screen-progress">
		<div class="souvera-progress__hero">
			<NcLoadingIcon :size="44" />
			<h2 class="souvera-progress__title">{{ progressTitle }}</h2>
			<p class="souvera-progress__sub">{{ progressSubtitle }}</p>
		</div>

		<div class="souvera-progress__bar-wrap" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
			<div class="souvera-progress__bar" :style="{ width: percent + '%' }"></div>
		</div>
		<div class="souvera-progress__stats">
			<span data-testid="wizard-progress-percent">{{ percent }} %</span>
			<span v-if="messagesTotal > 0" data-testid="wizard-progress-messages">
				{{ n('souvera_mail', '%n message transferred', '%n messages transferred', messagesDone) }}
				<span v-if="messagesTotal > 0"> / {{ messagesTotal.toLocaleString() }}</span>
			</span>
		</div>

		<dl v-if="foldersTotal > 0" class="souvera-progress__meta" data-testid="wizard-progress-folders">
			<div>
				<dt>{{ t('souvera_mail', 'Folders') }}</dt>
				<dd>{{ foldersDone.toLocaleString() }} / {{ foldersTotal.toLocaleString() }}</dd>
			</div>
			<div v-if="currentFolder">
				<dt>{{ t('souvera_mail', 'Current folder') }}</dt>
				<dd><code>{{ currentFolder }}</code></dd>
			</div>
		</dl>

		<NcNoteCard type="success">
			{{ t('souvera_mail', 'The import runs in the background. You can close this window at any time — the next time you open Souvera Mail you will see the current status automatically.') }}
		</NcNoteCard>

		<NcNoteCard v-if="cancelError" type="error" data-testid="wizard-progress-cancel-error">
			{{ cancelError }}
		</NcNoteCard>

		<div class="souvera-actions" :class="{ 'souvera-actions--split': canCancel }">
			<NcButton
				v-if="canCancel"
				type="tertiary"
				:disabled="isCancelling"
				data-testid="wizard-progress-cancel"
				@click="onCancelClick">
				<template #icon>
					<NcLoadingIcon v-if="isCancelling" :size="20" />
					<CancelIcon v-else :size="20" />
				</template>
				{{ isCancelling ? t('souvera_mail', 'Cancelling …') : t('souvera_mail', 'Cancel import') }}
			</NcButton>
			<NcButton
				type="secondary"
				data-testid="wizard-progress-close"
				@click="$emit('close')">
				<template #icon><Close :size="20" /></template>
				{{ t('souvera_mail', 'Keep running in the background') }}
			</NcButton>
		</div>

		<NcDialog
			v-if="showConfirm"
			:name="t('souvera_mail', 'Really cancel the import?')"
			size="small"
			container="body"
			data-testid="wizard-progress-cancel-confirm"
			@closing="showConfirm = false"
			@update:open="v => { if (!v) showConfirm = false }">
			<div class="souvera-progress__confirm">
				<p>
					{{ t('souvera_mail', 'Your job is currently queued at provider.tools. On cancel:') }}
				</p>
				<ul>
					<li>{{ t('souvera_mail', 'The temporary target app password is revoked immediately.') }}</li>
					<li>{{ t('souvera_mail', 'When a worker would pick up the job, it will fail to log in and the import will not run.') }}</li>
					<li>{{ t('souvera_mail', 'No mail has been transferred yet — nothing is lost.') }}</li>
				</ul>
				<div class="souvera-actions souvera-actions--split">
					<NcButton
						type="tertiary"
						data-testid="wizard-progress-cancel-cancel"
						@click="showConfirm = false">
						{{ t('souvera_mail', 'Back') }}
					</NcButton>
					<NcButton
						type="error"
						:disabled="isCancelling"
						data-testid="wizard-progress-cancel-confirm-btn"
						@click="onCancelConfirm">
						<template #icon>
							<NcLoadingIcon v-if="isCancelling" :size="20" />
							<CancelIcon v-else :size="20" />
						</template>
						{{ t('souvera_mail', 'Yes, cancel now') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import Close from 'vue-material-design-icons/Close.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

/**
 * v0.14.15 — Backend-contract-aware progress screen.
 *
 * MigrationJob::toApiArray() emits:
 *   status: 'pending' | 'running' | 'completed' | 'failed' | 'dismissed'
 *   progress: {
 *     progress: { foldersDone, foldersTotal, messagesDone, messagesTotal },
 *     queue:    { position, totalInQueue }
 *   }
 *
 * Field names are camelCase and nested. Prior versions of this screen
 * read snake_case top-level fields (`messages_total`, `queue_position`)
 * which never existed on the wire, so the UI stayed at "Warteschlange"
 * forever even when the poller had a fresh update in the DB.
 */
export default {
	name: 'ProgressScreen',
	components: { NcButton, NcNoteCard, NcLoadingIcon, NcDialog, Close, CancelIcon },
	props: {
		migration: { type: Object, required: true },
	},
	emits: ['close'],
	setup(props, { emit }) {
		const job = computed(() => props.migration.status.value)
		const state = computed(() => job.value?.status || 'pending')

		// Nested progress + queue snapshots (see contract docblock above).
		const progressBlock = computed(() => job.value?.progress?.progress || {})
		const queueBlock = computed(() => job.value?.progress?.queue || {})

		const messagesDone = computed(() => Number(progressBlock.value.messagesDone) || 0)
		const messagesTotal = computed(() => Number(progressBlock.value.messagesTotal) || 0)
		const foldersDone = computed(() => Number(progressBlock.value.foldersDone) || 0)
		const foldersTotal = computed(() => Number(progressBlock.value.foldersTotal) || 0)
		const currentFolder = computed(() => progressBlock.value.currentFolder || '')

		const percent = computed(() => {
			if (messagesTotal.value > 0) {
				return Math.min(100, Math.round((messagesDone.value / messagesTotal.value) * 100))
			}
			if (foldersTotal.value > 0) {
				return Math.min(100, Math.round((foldersDone.value / foldersTotal.value) * 100))
			}
			return state.value === 'pending' ? 2 : 5
		})

		const progressTitle = computed(() => {
			if (state.value === 'pending') return t('souvera_mail', 'Queued …')
			if (state.value === 'running') return t('souvera_mail', 'Import running …')
			return t('souvera_mail', 'Processing import …')
		})
		const progressSubtitle = computed(() => {
			if (state.value === 'pending') {
				const pos = Number(queueBlock.value.position) || 0
				const total = Number(queueBlock.value.totalInQueue) || 0
				if (pos > 0 && total > 0) {
					return t('souvera_mail', 'Queue position: {n} of {t}', { n: pos, t: total })
				}
				if (pos > 0) {
					return t('souvera_mail', 'Queue position: {n}', { n: pos })
				}
				return t('souvera_mail', 'Your job is waiting for a free worker at provider.tools.')
			}
			if (currentFolder.value) {
				return t('souvera_mail', 'Current folder: {folder}', { folder: currentFolder.value })
			}
			return t('souvera_mail', 'Mail is being transferred to Souvera Mail.')
		})

		// v0.14.16 — cancel path (queue only).
		const showConfirm = ref(false)
		const isCancelling = ref(false)
		const cancelError = ref('')
		const canCancel = computed(() => state.value === 'pending' && job.value?.id)

		function onCancelClick() {
			cancelError.value = ''
			showConfirm.value = true
		}
		async function onCancelConfirm() {
			if (!job.value?.id || isCancelling.value) return
			isCancelling.value = true
			try {
				await props.migration.cancelActiveJob(Number(job.value.id))
				showConfirm.value = false
				// Wizard watches jobState → auto-transitions to Terminal.
			} catch (e) {
				cancelError.value = e?.message || t('souvera_mail', 'The import could not be cancelled.')
				showConfirm.value = false
			} finally {
				isCancelling.value = false
			}
		}

		return {
			state, percent, messagesDone, messagesTotal, foldersDone, foldersTotal,
			currentFolder, progressTitle, progressSubtitle,
			// cancel state
			canCancel, showConfirm, isCancelling, cancelError,
			onCancelClick, onCancelConfirm,
		}
	},
}
</script>

<style scoped>
.souvera-progress {
	display: flex;
	flex-direction: column;
	gap: var(--sc-field-gap);
}
.souvera-progress__hero {
	text-align: center;
	padding: 12px 0 4px;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
}
.souvera-progress__hero .material-design-icon {
	color: var(--color-primary-element);
}
.souvera-progress__title {
	font-size: 1.3rem;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}
.souvera-progress__sub {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
.souvera-progress__bar-wrap {
	height: 12px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 999px);
	overflow: hidden;
}
.souvera-progress__bar {
	height: 100%;
	background: var(--color-primary-element);
	transition: width 500ms ease;
	border-radius: var(--border-radius-pill, 999px);
}
.souvera-progress__stats {
	display: flex;
	justify-content: space-between;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
.souvera-progress__meta {
	margin: 0;
	padding: 10px 14px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	display: flex;
	flex-wrap: wrap;
	gap: 8px 24px;
}
.souvera-progress__meta > div {
	display: flex;
	align-items: baseline;
	gap: 8px;
	min-width: 0;
}
.souvera-progress__meta dt {
	color: var(--color-text-maxcontrast);
	font-size: 0.78rem;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	margin: 0;
	white-space: nowrap;
}
.souvera-progress__meta dd {
	margin: 0;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}
.souvera-progress__meta code {
	font-family: var(--font-face-monospace, ui-monospace, monospace);
	font-size: 0.85rem;
	background: transparent;
	padding: 0;
}
.souvera-progress__confirm {
	padding: var(--sc-field-gap);
	color: var(--color-main-text);
	line-height: 1.55;
}
.souvera-progress__confirm p {
	margin: 0 0 12px;
}
.souvera-progress__confirm ul {
	margin: 0 0 var(--sc-field-gap);
	padding-left: 20px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}
.souvera-progress__confirm li {
	margin: 4px 0;
}
</style>
