<template>
	<NcDialog :name="editId ? t('souvera_mail', 'Edit filter') : t('souvera_mail', 'New filter')"
		:open.sync="open"
		size="large"
		@update:open="$emit('close')">
		<div class="sieve-editor">
			<div class="sieve-editor__field">
				<label>{{ t('souvera_mail', 'Filter name') }}</label>
				<NcTextField :value="filterName" :placeholder="t('souvera_mail', 'My filter')"
					@update:value="filterName = $event" />
			</div>

			<div class="sieve-editor__templates">
				<label>{{ t('souvera_mail', 'Quick insert') }}</label>
				<div class="sieve-editor__chips">
					<NcButton variant="tertiary" v-for="tpl in templates" :key="tpl.label"
						@click="insertTemplate(tpl.code)" :title="tpl.desc">
						{{ tpl.label }}
					</NcButton>
				</div>
			</div>

			<div class="sieve-editor__field">
				<label>{{ t('souvera_mail', 'Sieve script') }}
					<a href="https://en.wikipedia.org/wiki/Sieve_(mail_filtering_language)" target="_blank" class="sieve-editor__help">(Sieve RFC 5228)</a>
				</label>
				<textarea ref="editor" v-model="scriptBody" class="sieve-editor__textarea"
					:placeholder="defaultScript"
					spellcheck="false" rows="12" />
			</div>

			<div class="sieve-editor__actions">
				<NcButton variant="tertiary" @click="validate" :disabled="validating">
					{{ validating ? t('souvera_mail', 'Validating…') : t('souvera_mail', 'Validate') }}
				</NcButton>
				<span v-if="validationResult !== null" class="sieve-editor__validation"
					:class="{ 'sieve-editor__validation--ok': validationResult === true, 'sieve-editor__validation--err': validationResult !== true }">
					{{ validationResult === true ? t('souvera_mail', 'Valid') : String(validationResult) }}
				</span>
				<div class="spacer" />
				<NcButton variant="secondary" @click="$emit('close')">{{ t('souvera_mail', 'Cancel') }}</NcButton>
				<NcButton variant="primary" @click="save" :disabled="saving">
					{{ saving ? t('souvera_mail', 'Saving…') : t('souvera_mail', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'SieveFilterEditor',
	components: { NcDialog, NcButton, NcTextField },
	props: {
		editId: { type: String, default: '' },
		editName: { type: String, default: '' },
		editBody: { type: String, default: '' },
		open: { type: Boolean, default: true },
	},
	emits: ['close', 'saved'],
	data() {
		return {
			filterName: this.editName || '',
			scriptBody: this.editBody || '',
			validating: false,
			saving: false,
			validationResult: null,
		}
	},
	computed: {
		defaultScript() {
			return [
				'require ["fileinto", "imap4flags"];',
				'',
				'if header :contains "subject" "SPAM" {',
				'    fileinto "Junk";',
				'    stop;',
				'}',
			].join('\n')
		},
		templates() {
			return [
				{ label: this.t('souvera_mail', 'Move to folder'), code: 'fileinto "FOLDER";', desc: this.t('souvera_mail', 'fileinto "FolderName"') },
				{ label: this.t('souvera_mail', 'Subject contains'), code: 'if header :contains "subject" "" {\n    \n}', desc: this.t('souvera_mail', 'If subject contains…') },
				{ label: this.t('souvera_mail', 'From contains'), code: 'if header :contains "from" "" {\n    \n}', desc: this.t('souvera_mail', 'If sender contains…') },
				{ label: this.t('souvera_mail', 'Mark as read'), code: 'addflag "\\\\Seen";', desc: 'addflag "\\\\Seen"' },
				{ label: this.t('souvera_mail', 'Discard'), code: 'discard;', desc: 'discard' },
				{ label: this.t('souvera_mail', 'Stop'), code: 'stop;', desc: 'stop' },
				{ label: this.t('souvera_mail', 'Redirect'), code: 'redirect "user@domain.de";', desc: this.t('souvera_mail', 'Forward to…') },
				{ label: this.t('souvera_mail', 'Require'), code: 'require ["fileinto", "imap4flags", "envelope"];', desc: 'require' },
				{ label: this.t('souvera_mail', 'If-elsif'), code: 'if header :contains "subject" "A" {\n    \n} elsif header :contains "subject" "B" {\n    \n}', desc: 'if/elsif' },
			]
		},
	},
	methods: {
		insertTemplate(code) {
			const el = this.$refs.editor
			const pos = el.selectionStart
			const before = this.scriptBody.substring(0, pos)
			const after = this.scriptBody.substring(el.selectionEnd)
			this.scriptBody = before + code + after
			this.$nextTick(() => {
				el.focus()
				el.selectionStart = el.selectionEnd = pos + code.length
			})
		},
		async validate() {
			const { useSieveClient } = await import('../composables/useSieveClient.js')
			const { validateScript } = useSieveClient()
			this.validating = true
			this.validationResult = null
			try {
				const res = await validateScript(this.scriptBody)
				this.validationResult = res.valid ? true : (res.error || 'Invalid')
			} catch (e) {
				this.validationResult = String(e)
			} finally {
				this.validating = false
			}
		},
		async save() {
			if (!this.filterName.trim()) {
				showError(this.t('souvera_mail', 'Name is required'))
				return
			}
			if (!this.scriptBody.trim()) {
				showError(this.t('souvera_mail', 'Sieve script is required'))
				return
			}
			this.saving = true
			try {
				const { useSieveClient } = await import('../composables/useSieveClient.js')
				const { saveScript } = useSieveClient()
				await saveScript(this.filterName.trim(), this.scriptBody.trim())
				showSuccess(this.t('souvera_mail', 'Filter saved'))
				this.$emit('saved')
				this.$emit('close')
			} catch (e) {
				showError(e?.response?.data?.error || this.t('souvera_mail', 'Failed to save filter'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.sieve-editor { display: flex; flex-direction: column; gap: 12px; padding: 8px 0; }
.sieve-editor__field { display: flex; flex-direction: column; gap: 4px; }
.sieve-editor__field label { font-size: 13px; font-weight: 600; color: var(--color-text-maxcontrast); }
.sieve-editor__textarea {
	width: 100%; min-height: 260px; padding: 10px; border: 1px solid var(--color-border);
	border-radius: 6px; background: var(--color-main-background); color: var(--color-main-text);
	font-family: monospace; font-size: 13px; line-height: 1.5; resize: vertical;
	tab-size: 2;
}
.sieve-editor__chips { display: flex; flex-wrap: wrap; gap: 4px; }
.sieve-editor__help { font-weight: 400; font-size: 11px; opacity: 0.6; }
.sieve-editor__actions { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.sieve-editor__validation { font-size: 12px; padding: 2px 8px; border-radius: 4px; }
.sieve-editor__validation--ok { color: #2e7d32; background: #e8f5e9; }
.sieve-editor__validation--err { color: #c62828; background: #ffebee; }
.spacer { flex: 1; }
</style>
