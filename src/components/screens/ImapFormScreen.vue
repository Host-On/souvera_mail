<template>
	<div class="souvera-form" data-testid="wizard-screen-form">
		<p class="souvera-form__lead">
			{{ t('souvera_mail', 'Gib die IMAP-Zugangsdaten deines alten Anbieters ein. Wir prüfen die Verbindung, bevor der Import startet.') }}
		</p>

		<div class="souvera-form__grid">
			<div class="souvera-form__field">
				<NcTextField
					:value.sync="localForm.host"
					:label="t('souvera_mail', 'IMAP-Server')"
					:placeholder="'imap.beispiel.de'"
					name="host"
					autocomplete="off"
					data-testid="wizard-form-host"
					@update:value="v => update('host', v)" />
			</div>
			<div class="souvera-form__field souvera-form__field--narrow">
				<NcTextField
					:value.sync="localForm.port"
					:label="t('souvera_mail', 'Port')"
					name="port"
					type="number"
					autocomplete="off"
					data-testid="wizard-form-port"
					@update:value="v => update('port', v)" />
			</div>
			<div class="souvera-form__field souvera-form__field--full">
				<NcTextField
					:value.sync="localForm.username"
					:label="t('souvera_mail', 'Benutzername (meist die E-Mail-Adresse)')"
					:placeholder="'ich@beispiel.de'"
					name="username"
					autocomplete="off"
					data-testid="wizard-form-username"
					@update:value="v => update('username', v)" />
			</div>
			<div class="souvera-form__field souvera-form__field--full">
				<NcTextField
					:value.sync="localForm.password"
					:label="t('souvera_mail', 'Passwort')"
					name="password"
					type="password"
					autocomplete="new-password"
					data-testid="wizard-form-password"
					@update:value="v => update('password', v)" />
			</div>
			<div class="souvera-form__field souvera-form__field--full">
				<NcCheckboxRadioSwitch
					:checked="localForm.tls"
					data-testid="wizard-form-tls"
					@update:checked="v => update('tls', v)">
					{{ t('souvera_mail', 'Verschlüsselte Verbindung (TLS/SSL) — empfohlen') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<NcNoteCard
			v-if="hasTestError"
			type="error"
			:heading="t('souvera_mail', 'Verbindung fehlgeschlagen')"
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
				{{ t('souvera_mail', 'Zurück') }}
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
				{{ isBusy ? t('souvera_mail', 'Prüfe Verbindung …') : t('souvera_mail', 'Verbindung prüfen') }}
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
		localForm() { return this.form },
		hasTestError() { return this.testResult && this.testResult.ok === false },
		canSubmit() {
			return this.localForm.host && this.localForm.port && this.localForm.username && this.localForm.password
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
