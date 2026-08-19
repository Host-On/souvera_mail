<template>
	<div class="souvera-confirm" data-testid="wizard-screen-confirm">
		<NcNoteCard type="success" :heading="t('souvera_mail', 'Connection successful')">
			{{ t('souvera_mail', 'We could connect to your old mailbox.') }}
		</NcNoteCard>

		<dl class="souvera-confirm__summary">
			<div>
				<dt>{{ t('souvera_mail', 'Server') }}</dt>
				<dd><code>{{ form.host }}:{{ form.port }}</code></dd>
			</div>
			<div>
				<dt>{{ t('souvera_mail', 'Account') }}</dt>
				<dd><code>{{ form.username }}</code></dd>
			</div>
			<div v-if="folderPreview">
				<dt>{{ t('souvera_mail', 'Preview') }}</dt>
				<dd>
					{{ n('souvera_mail', '%n folder', '%n folders', folderPreview.folders) }}
					<span v-if="folderPreview.messages > 0"> · {{ n('souvera_mail', '%n message', '%n messages', folderPreview.messages) }}</span>
				</dd>
			</div>
			<div v-if="selectedCount > 0" data-testid="wizard-confirm-selected-count">
				<dt>{{ t('souvera_mail', 'To import') }}</dt>
				<dd>
					<strong>{{ n('souvera_mail', '%n folder selected', '%n folders selected', selectedCount) }}</strong>
				</dd>
			</div>
		</dl>

		<NcNoteCard type="warning" :heading="t('souvera_mail', 'Important')">
			{{ t('souvera_mail', 'A started import cannot be cancelled. It runs entirely in the background — you can keep working while it runs.') }}
		</NcNoteCard>

		<NcNoteCard
			v-if="startError"
			type="error"
			:heading="t('souvera_mail', 'Could not start import')"
			data-testid="wizard-confirm-error">
			{{ startError }}
		</NcNoteCard>

		<div class="souvera-actions souvera-actions--split">
			<NcButton
				type="tertiary"
				data-testid="wizard-confirm-back"
				@click="$emit('back')">
				<template #icon><ArrowLeft :size="20" /></template>
				{{ t('souvera_mail', 'Back') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="isBusy"
				data-testid="wizard-confirm-start"
				@click="$emit('advance')">
				<template #icon>
					<NcLoadingIcon v-if="isBusy" :size="20" />
					<PlayCircleOutline v-else :size="20" />
				</template>
				{{ isBusy ? t('souvera_mail', 'Starting import …') : t('souvera_mail', 'Start import now') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'

export default {
	name: 'ConfirmScreen',
	components: { NcButton, NcNoteCard, NcLoadingIcon, ArrowLeft, PlayCircleOutline },
	props: {
		form: { type: Object, required: true },
		folderPreview: { type: Object, default: null },
		startError: { type: String, default: '' },
		isBusy: { type: Boolean, default: false },
		selected: { type: Set, default: () => new Set() },
	},
	emits: ['advance', 'back'],
	computed: {
		selectedCount() { return this.selected ? this.selected.size : 0 },
	},
}
</script>

<style scoped>
.souvera-confirm {
	display: flex;
	flex-direction: column;
	gap: var(--sc-field-gap);
}
.souvera-confirm__summary {
	margin: 0;
	padding: 12px 14px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.souvera-confirm__summary > div {
	display: flex;
	align-items: baseline;
	gap: 12px;
}
.souvera-confirm__summary dt {
	flex: 0 0 90px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	font-weight: 500;
}
.souvera-confirm__summary dd {
	margin: 0;
	color: var(--color-main-text);
}
.souvera-confirm__summary code {
	font-family: var(--font-face-monospace, ui-monospace, monospace);
	font-size: 0.92rem;
	background: transparent;
	padding: 0;
}
</style>
