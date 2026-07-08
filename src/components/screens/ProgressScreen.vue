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
				{{ n('souvera_mail', '%n Nachricht übertragen', '%n Nachrichten übertragen', messagesDone) }}
				<span v-if="messagesTotal > 0"> / {{ messagesTotal }}</span>
			</span>
		</div>

		<NcNoteCard type="success">
			{{ t('souvera_mail', 'Der Import läuft im Hintergrund. Du kannst dieses Fenster jederzeit schließen — beim nächsten Öffnen von Souvera Mail siehst du den aktuellen Stand automatisch.') }}
		</NcNoteCard>

		<div class="souvera-actions">
			<NcButton
				type="secondary"
				data-testid="wizard-progress-close"
				@click="$emit('close')">
				<template #icon><Close :size="20" /></template>
				{{ t('souvera_mail', 'Im Hintergrund weiterlaufen lassen') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'ProgressScreen',
	components: { NcButton, NcNoteCard, NcLoadingIcon, Close },
	props: {
		migration: { type: Object, required: true },
	},
	emits: ['close'],
	setup(props) {
		const s = computed(() => props.migration.status.value)
		const state = computed(() => s.value?.state || 'pending')

		const percent = computed(() => {
			const p = s.value?.progress
			if (typeof p === 'number' && p >= 0 && p <= 100) return Math.round(p)
			const total = s.value?.messages_total || 0
			const done = s.value?.messages_done || 0
			if (total > 0) return Math.min(100, Math.round((done / total) * 100))
			return state.value === 'pending' ? 2 : 5
		})
		const messagesDone = computed(() => s.value?.messages_done || 0)
		const messagesTotal = computed(() => s.value?.messages_total || 0)

		const progressTitle = computed(() => {
			if (state.value === 'pending') return t('souvera_mail', 'Warteschlange …')
			if (state.value === 'running') return t('souvera_mail', 'Import läuft …')
			return t('souvera_mail', 'Import wird verarbeitet …')
		})
		const progressSubtitle = computed(() => {
			if (state.value === 'pending') {
				const pos = s.value?.queue_position
				if (pos) return t('souvera_mail', 'Position in der Warteschlange: {n}', { n: pos })
				return t('souvera_mail', 'Dein Job wartet auf einen freien Worker.')
			}
			const folder = s.value?.current_folder
			if (folder) return t('souvera_mail', 'Aktueller Ordner: {folder}', { folder })
			return t('souvera_mail', 'Mails werden nach Souvera Mail übertragen.')
		})

		return { state, percent, messagesDone, messagesTotal, progressTitle, progressSubtitle }
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
</style>
