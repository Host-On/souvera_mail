<template>
	<div class="souvera-terminal" :class="stateClass" data-testid="wizard-screen-terminal">
		<div class="souvera-terminal__hero">
			<component :is="stateIcon" :size="72" class="souvera-terminal__icon" />
			<h2 class="souvera-terminal__title">{{ title }}</h2>
			<p class="souvera-terminal__sub">{{ subtitle }}</p>
		</div>

		<dl v-if="hasStats" class="souvera-terminal__stats">
			<div v-if="messagesDone">
				<dt>{{ t('souvera_mail', 'Transferred messages') }}</dt>
				<dd>{{ messagesDone.toLocaleString() }}</dd>
			</div>
			<div v-if="foldersDone">
				<dt>{{ t('souvera_mail', 'Folders') }}</dt>
				<dd>{{ foldersDone.toLocaleString() }}</dd>
			</div>
			<div v-if="duration">
				<dt>{{ t('souvera_mail', 'Duration') }}</dt>
				<dd>{{ duration }}</dd>
			</div>
		</dl>

		<NcNoteCard
			v-if="isFail && errorDetail"
			type="error"
			:heading="t('souvera_mail', 'Error details for support')"
			data-testid="wizard-terminal-error-detail">
			<pre class="souvera-terminal__error">{{ errorDetail }}</pre>
		</NcNoteCard>

		<div class="souvera-actions">
			<NcButton
				type="primary"
				data-testid="wizard-terminal-close"
				@click="$emit('close')">
				<template #icon><Check :size="20" /></template>
				{{ t('souvera_mail', 'Done') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Check from 'vue-material-design-icons/Check.vue'

export default {
	name: 'TerminalScreen',
	components: { NcButton, NcNoteCard, CheckCircle, AlertCircle, Check },
	props: {
		migration: { type: Object, required: true },
	},
	emits: ['close'],
	setup(props) {
		// v0.14.15 — backend contract uses `status` (top-level) and
		// nested progress: { progress: {…}, queue: {…} }. See docblock
		// in ProgressScreen.vue / MigrationJob::toApiArray().
		const s = computed(() => props.migration.status.value || props.migration.lastJob.value || {})
		const state = computed(() => s.value.status || 'completed')

		const isSuccess = computed(() => state.value === 'completed')
		const isFail = computed(() => state.value === 'failed' || state.value === 'cancelled')

		const stateClass = computed(() => (isSuccess.value ? 'souvera-terminal--ok' : 'souvera-terminal--fail'))
		const stateIcon = computed(() => (isSuccess.value ? CheckCircle : AlertCircle))

		const title = computed(() => {
			if (isSuccess.value) return t('souvera_mail', 'Import successful!')
			if (state.value === 'cancelled') return t('souvera_mail', 'Import cancelled')
			return t('souvera_mail', 'Import failed')
		})
		const subtitle = computed(() => {
			if (isSuccess.value) return t('souvera_mail', 'Your old mail is now available in Souvera Mail.')
			return t('souvera_mail', 'There was a problem transferring the mail. Please contact support with the error details below.')
		})

		const progressBlock = computed(() => s.value.progress?.progress || {})
		const messagesDone = computed(() => Number(progressBlock.value.messagesDone) || 0)
		const foldersDone = computed(() => Number(progressBlock.value.foldersDone) || 0)
		const duration = computed(() => {
			const started = Number(s.value.createdAt) || 0
			const finished = Number(s.value.finishedAt) || 0
			if (!started || !finished || finished < started) return null
			const totalSec = finished - started
			const h = Math.floor(totalSec / 3600)
			const m = Math.floor((totalSec % 3600) / 60)
			const sec = totalSec % 60
			if (h > 0) return `${h} h ${m} min`
			if (m > 0) return `${m} min ${sec} s`
			return `${sec} s`
		})
		const hasStats = computed(() => messagesDone.value || foldersDone.value || duration.value)
		const errorDetail = computed(() => s.value.error || '')

		return { state, isSuccess, isFail, stateClass, stateIcon, title, subtitle, messagesDone, foldersDone, duration, hasStats, errorDetail }
	},
}
</script>

<style scoped>
.souvera-terminal {
	display: flex;
	flex-direction: column;
	gap: var(--sc-field-gap);
}
.souvera-terminal__hero {
	text-align: center;
	padding: 12px 0 4px;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;
}
.souvera-terminal--ok .souvera-terminal__icon { color: var(--color-success); }
.souvera-terminal--fail .souvera-terminal__icon { color: var(--color-error); }

.souvera-terminal__title {
	font-size: 1.4rem;
	font-weight: var(--font-weight-heading, 700);
	margin: 0;
	color: var(--color-main-text);
}
.souvera-terminal__sub {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 460px;
	line-height: 1.55;
}
.souvera-terminal__stats {
	margin: 0;
	padding: 12px 14px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	display: flex;
	flex-wrap: wrap;
	gap: 16px 32px;
	justify-content: center;
}
.souvera-terminal__stats > div {
	text-align: center;
}
.souvera-terminal__stats dt {
	color: var(--color-text-maxcontrast);
	font-size: 0.78rem;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	margin: 0 0 4px;
}
.souvera-terminal__stats dd {
	margin: 0;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
}
.souvera-terminal__error {
	white-space: pre-wrap;
	word-break: break-word;
	margin: 0;
	max-height: 140px;
	overflow: auto;
	font: 0.8rem/1.4 var(--font-face-monospace, ui-monospace, monospace);
}
</style>
