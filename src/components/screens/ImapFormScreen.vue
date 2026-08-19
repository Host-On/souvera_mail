<template>
	<div class="souvera-form" data-testid="wizard-screen-form">
		<p class="souvera-form__lead">
			{{ t('souvera_mail', 'Enter the IMAP credentials from your old provider. We will test the connection before starting the import.') }}
		</p>

		<div class="souvera-form__grid">
			<div class="souvera-form__field">
				<NcTextField
					:model-value="form.host"
					:label="t('souvera_mail', 'IMAP server')"
					placeholder="imap.example.com"
					name="host"
					autocomplete="off"
					data-testid="wizard-form-host"
					@update:model-value="v => update('host', v)" />
			</div>
			<div class="souvera-form__field souvera-form__field--narrow">
				<NcTextField
					:model-value="String(form.port)"
					:label="t('souvera_mail', 'Port')"
					name="port"
					type="number"
					autocomplete="off"
					data-testid="wizard-form-port"
					@update:model-value="v => update('port', Number(v) || 0)" />
			</div>
			<div class="souvera-form__field souvera-form__field--full">
				<NcTextField
					:model-value="form.username"
					:label="t('souvera_mail', 'Username (usually your email address)')"
					placeholder="me@example.com"
					name="username"
					autocomplete="off"
					data-testid="wizard-form-username"
					@update:model-value="v => update('username', v)" />
			</div>
			<div class="souvera-form__field souvera-form__field--full">
				<NcTextField
					:model-value="form.password"
					:label="t('souvera_mail', 'Password')"
					name="password"
					type="password"
					autocomplete="new-password"
					data-testid="wizard-form-password"
					@update:model-value="v => update('password', v)" />
			</div>
			<div class="souvera-form__field souvera-form__field--full">
				<NcCheckboxRadioSwitch
					:model-value="form.tls"
					data-testid="wizard-form-tls"
					@update:model-value="v => update('tls', v)">
					{{ t('souvera_mail', 'Encrypted connection (TLS/SSL) — recommended') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<NcNoteCard
			v-if="hasTestError"
			type="error"
			:heading="t('souvera_mail', 'Connection failed')"
			data-testid="wizard-form-error">
			{{ testResult && testResult.error }}
		</NcNoteCard>

		<div class="souvera-actions souvera-actions--split">
			<NcButton
				type="tertiary"
				data-testid="wizard-form-back"
				@click="$emit('back')">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('souvera_mail', 'Back') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!canSubmit || isBusy"
				data-testid="wizard-form-next"
				@click="$emit('advance')">
				<template #icon>
					<NcLoadingIcon v-if="isBusy" :size="20" />
					<ArrowRight v-else :size="20" />
				</template>
				{{ isBusy ? t('souvera_mail', 'Testing connection …') : t('souvera_mail', 'Test connection') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'

/**
 * v0.14.12 — v-model bindings adapted to @nextcloud/vue v9 Vue-3 API.
 *
 * v9 collapsed every input surface onto Vue 3's `v-model` standard →
 * every `NcTextField` / `NcCheckboxRadioSwitch` emits `update:modelValue`
 * (not `update:value` / `update:checked` as in v2/legacy). Using the
 * old Vue-2 `:value.sync` OR the legacy event names silently breaks
 * two-way binding: the field renders once, the user's typing never
 * reaches `form`, the derived `canSubmit` computed stays false → the
 * "Verbindung prüfen" button never enables. Same root cause hits the
 * TLS-Checkbox and the port default.
 *
 * Fix: explicit one-way `:model-value` + `@update:model-value` handler
 * that forwards the fresh value through `update:form` up to the
 * MigrationWizard's reactive `form`. Kept explicit (no `v-model`)
 * because `form` is a prop, not a local ref — the wizard owns state.
 */
export default {
	name: 'ImapFormScreen',
	components: { NcButton, NcTextField, NcCheckboxRadioSwitch, NcNoteCard, NcLoadingIcon, ArrowLeft, ArrowRight },
	props: {
		form: { type: Object, required: true },
		isBusy: { type: Boolean, default: false },
		testResult: { type: Object, default: null },
	},
	emits: ['advance', 'back', 'update:form'],
	computed: {
		hasTestError() { return this.testResult && this.testResult.ok === false },
		canSubmit() {
			return this.form.host
				&& this.form.port > 0
				&& this.form.username
				&& this.form.password
		},
	},
	methods: {
		update(key, value) {
			this.$emit('update:form', { [key]: value })
		},
	},
}
</script>

<style scoped>
.souvera-form {
	display: flex;
	flex-direction: column;
	gap: var(--sc-field-gap);
}
.souvera-form__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.55;
}
.souvera-form__grid {
	display: grid;
	grid-template-columns: 1fr 120px;
	gap: 14px 12px;
}
.souvera-form__field--full { grid-column: 1 / -1; }
.souvera-form__field--narrow { grid-column: 2 / 3; }

@media (max-width: 540px) {
	.souvera-form__grid { grid-template-columns: 1fr; }
	.souvera-form__field--narrow { grid-column: 1 / -1; }
}
</style>
