<template>
	<NcDialog :name="editId ? t('souvera_mail', 'Edit filter') : t('souvera_mail', 'New filter')"
		:open.sync="open"
		size="large"
		@update:open="$emit('close')">
		<div class="sf-editor">
			<!-- Filter name -->
			<div class="sf-field">
				<label>{{ t('souvera_mail', 'Filter name') }}</label>
				<NcTextField :value="filterName" :placeholder="t('souvera_mail', 'My filter')"
					@update:value="filterName = $event" />
			</div>

			<!-- Conditions -->
			<div class="sf-section">
				<label class="sf-section__title">{{ t('souvera_mail', 'Conditions') }}</label>
				<div class="sf-match-type">
					<NcButton variant="tertiary" :type="matchType === 'any' ? 'primary' : 'tertiary'" @click="matchType = 'any'">
						{{ t('souvera_mail', 'Match ANY') }}
					</NcButton>
					<NcButton variant="tertiary" :type="matchType === 'all' ? 'primary' : 'tertiary'" @click="matchType = 'all'">
						{{ t('souvera_mail', 'Match ALL') }}
					</NcButton>
				</div>

				<div v-for="(c, i) in conditions" :key="i" class="sf-condition">
					<NcSelect :value="c.field" :options="fieldOptions" :label="t('souvera_mail', 'Field')"
						@update:value="c.field = $event" />
					<NcSelect :value="c.operator" :options="operatorOptions" style="max-width:120px"
						@update:value="c.operator = $event" />
					<NcTextField :value="c.value" :placeholder="t('souvera_mail', 'Value')"
						@update:value="c.value = $event" />
					<NcButton variant="tertiary" @click="conditions.splice(i, 1)" v-if="conditions.length > 1">
						<template #icon><Minus :size="16" /></template>
					</NcButton>
				</div>

				<NcButton variant="tertiary" @click="addCondition">
					<template #icon><Plus :size="16" /></template>
					{{ t('souvera_mail', 'Add condition') }}
				</NcButton>
			</div>

			<!-- Actions -->
			<div class="sf-section">
				<label class="sf-section__title">{{ t('souvera_mail', 'Actions') }}</label>

				<div v-for="(a, i) in actions" :key="i" class="sf-action">
					<NcSelect :value="a.type" :options="actionOptions" :label="t('souvera_mail', 'Action')"
						@update:value="onActionChange(a, $event)" />

					<!-- Move to folder: show folder picker -->
					<NcSelect v-if="a.type === 'move'" :value="a.value" :options="mailboxOptions"
						:placeholder="t('souvera_mail', 'Folder')" style="max-width:200px"
						@update:value="a.value = $event" />

					<!-- Redirect: show email input -->
					<NcTextField v-if="a.type === 'redirect'" :value="a.value"
						:placeholder="'user@domain.de'"
						@update:value="a.value = $event" />

					<NcButton variant="tertiary" @click="actions.splice(i, 1)" v-if="actions.length > 1">
						<template #icon><Minus :size="16" /></template>
					</NcButton>
				</div>

				<NcButton variant="tertiary" @click="addAction">
					<template #icon><Plus :size="16" /></template>
					{{ t('souvera_mail', 'Add action') }}
				</NcButton>
			</div>

			<!-- Generated Sieve preview (collapsed) -->
			<details class="sf-preview" v-if="generatedSieve">
				<summary>{{ t('souvera_mail', 'Show source') }}</summary>
				<pre class="sf-preview__code">{{ generatedSieve }}</pre>
			</details>

			<!-- Buttons -->
			<div class="sf-buttons">
				<NcButton variant="secondary" @click="$emit('close')">{{ t('souvera_mail', 'Cancel') }}</NcButton>
				<NcButton variant="primary" @click="save" :disabled="saving">
					{{ saving ? t('souvera_mail', 'Saving…') : t('souvera_mail', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcSelect } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SieveFilterEditor',
	components: { NcDialog, NcButton, NcTextField, NcSelect, Plus, Minus },
	props: {
		editId: { type: String, default: '' },
		editName: { type: String, default: '' },
		editBody: { type: String, default: '' },
		open: { type: Boolean, default: true },
	},
	emits: ['close', 'saved'],
	data() {
		return {
			filterName: '',
			matchType: 'any',
			conditions: [],
			actions: [],
			mailboxes: [],
			saving: false,
			fieldOptions: [
				{ value: 'subject', label: this.t('souvera_mail', 'Subject') },
				{ value: 'from',    label: this.t('souvera_mail', 'From') },
				{ value: 'to',      label: this.t('souvera_mail', 'To') },
				{ value: 'body',    label: this.t('souvera_mail', 'Body') },
			],
			operatorOptions: [
				{ value: 'contains',  label: this.t('souvera_mail', 'contains') },
				{ value: 'equals',    label: this.t('souvera_mail', 'equals') },
				{ value: 'starts',    label: this.t('souvera_mail', 'starts with') },
				{ value: 'ends',      label: this.t('souvera_mail', 'ends with') },
				{ value: 'regex',     label: this.t('souvera_mail', 'matches regex') },
			],
			actionTypes: [
				{ value: 'move',     label: this.t('souvera_mail', 'Move to folder') },
				{ value: 'markread', label: this.t('souvera_mail', 'Mark as read') },
				{ value: 'markflag', label: this.t('souvera_mail', 'Mark as flagged') },
				{ value: 'redirect', label: this.t('souvera_mail', 'Forward to') },
				{ value: 'discard',  label: this.t('souvera_mail', 'Delete / Discard') },
				{ value: 'stop',     label: this.t('souvera_mail', 'Stop processing') },
			],
		}
	},
	computed: {
		actionOptions() { return this.actionTypes },
		mailboxOptions() {
			return this.mailboxes
				.filter(m => m.role !== 'trash' && m.role !== 'junk')
				.map(m => ({ value: m.name, label: m.name }))
		},
		generatedSieve() {
			return this.buildSieve()
		},
	},
	async mounted() {
		if (this.editName) this.filterName = this.editName
		if (this.editBody) this.parseFromSieve(this.editBody)
		else { this.conditions = [{ field: 'subject', operator: 'contains', value: '' }]; this.actions = [{ type: 'move', value: 'Junk' }] }
		try {
			const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/mailboxes'))
			this.mailboxes = data.mailboxes || []
		} catch {}
	},
	methods: {
		addCondition() { this.conditions.push({ field: 'subject', operator: 'contains', value: '' }) },
		addAction() { this.actions.push({ type: 'markread', value: '' }) },
		onActionChange(action, newType) {
			action.type = newType
			action.value = ''
		},
		buildSieve() {
			const lines = []
			const needed = new Set()
			const hasMove = this.actions.some(a => a.type === 'move')
			const hasFlag = this.actions.some(a => a.type === 'markread' || a.type === 'markflag')
			if (hasMove) needed.add('"fileinto"')
			if (hasFlag) needed.add('"imap4flags"')

			if (needed.size > 0) lines.push(`require [${[...needed].join(', ')}];`)
			lines.push('')

			const conds = this.conditions.filter(c => c.field && c.value)
			if (conds.length === 0) return ''

			const op = this.matchType === 'all' ? 'allof' : 'anyof'
			const tests = conds.map(c => {
				let op = ':contains'
				let val = c.value
				if (c.operator === 'equals') {
					op = ':is'
					val = `"${val}"`
				} else if (c.operator === 'starts') {
					op = ':matches'
					val = `"${this.escapeRegex(val)}*"`
				} else if (c.operator === 'ends') {
					op = ':matches'
					val = `"*${this.escapeRegex(val)}"`
				} else if (c.operator === 'regex') {
					op = ':regex'
					val = `"${val}"`
				} else {
					val = `"${val}"`
				}
				return `header ${op} "${c.field}" ${val}`
			})

			if (tests.length === 1) {
				lines.push(`if ${tests[0]} {`)
			} else {
				lines.push(`if ${op} (`)
				lines.push('    ' + tests.join(',\n    '))
				lines.push(`) {`)
			}

			for (const a of this.actions) {
				if (a.type === 'move' && a.value) lines.push(`    fileinto "${a.value}";`)
				if (a.type === 'markread') lines.push('    addflag "\\\\Seen";')
				if (a.type === 'markflag') lines.push('    addflag "\\\\Flagged";')
				if (a.type === 'redirect' && a.value) lines.push(`    redirect "${a.value}";`)
				if (a.type === 'discard') lines.push('    discard;')
				if (a.type === 'stop') lines.push('    stop;')
			}
			lines.push('}')

			return lines.join('\n')
		},
		escapeRegex(str) {
			return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
		},
		parseFromSieve(body) {
			// Best-effort parse: extract conditions and actions from existing Sieve
			this.conditions = []
			this.actions = []
			try {
				const match = body.match(/if ((?:allof|anyof)\s*\(([^)]+)\)|header\s+\S+\s+"([^"]+)"\s+"([^"]+)"\s*)/s)
				if (match) {
					const inner = match[2] || match[0]
					if (inner.includes('anyof')) this.matchType = 'any'
					else if (inner.includes('allof')) this.matchType = 'all'

					const headerRe = /header\s+(:contains|:is|:matches|:regex)\s+"([^"]+)"\s+"([^"]+)"/g
					let h
					while ((h = headerRe.exec(body)) !== null) {
						let op = 'contains'
						if (h[1] === ':is') op = 'equals'
						else if (h[1] === ':regex') op = 'regex'
						else if (h[1] === ':matches') {
							const v = h[3]
							if (v.startsWith('"*') && v.endsWith('*"')) op = 'contains'
							else if (v.endsWith('*"')) op = 'starts'
							else if (v.startsWith('"*')) op = 'ends'
						}
						this.conditions.push({ field: h[2], operator: op, value: h[3] })
					}
				}
				if (this.conditions.length === 0) {
					this.conditions = [{ field: 'subject', operator: 'contains', value: '' }]
				}

				if (body.includes('fileinto')) this.actions.push({ type: 'move', value: (body.match(/fileinto\s+"([^"]+)"/) || [])[1] || '' })
				if (body.includes('addflag "\\\\Seen"') || body.includes('addflag "\\Seen"')) this.actions.push({ type: 'markread', value: '' })
				if (body.includes('addflag "\\\\Flagged"') || body.includes('addflag "\\Flagged"')) this.actions.push({ type: 'markflag', value: '' })
				if (body.includes('redirect')) this.actions.push({ type: 'redirect', value: (body.match(/redirect\s+"([^"]+)"/) || [])[1] || '' })
				if (body.includes('discard')) this.actions.push({ type: 'discard', value: '' })
				if (body.includes('stop')) this.actions.push({ type: 'stop', value: '' })
				if (this.actions.length === 0) this.actions = [{ type: 'move', value: 'Junk' }]
			} catch {
				this.conditions = [{ field: 'subject', operator: 'contains', value: '' }]
				this.actions = [{ type: 'move', value: 'Junk' }]
			}
		},
		async save() {
			if (!this.filterName.trim()) { showError(this.t('souvera_mail', 'Name is required')); return }
			const body = this.buildSieve()
			if (!body) { showError(this.t('souvera_mail', 'No conditions defined')); return }
			this.saving = true
			try {
				const { useSieveClient } = await import('../composables/useSieveClient.js')
				const { saveScript } = useSieveClient()
				await saveScript(this.filterName.trim(), body)
				showSuccess(this.t('souvera_mail', 'Filter saved'))
				this.$emit('saved')
				this.$emit('close')
			} catch (e) {
				showError(e?.response?.data?.error || this.t('souvera_mail', 'Failed to save filter'))
			} finally { this.saving = false }
		},
	},
}
</script>

<style scoped>
.sf-editor { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.sf-field { display: flex; flex-direction: column; gap: 4px; }
.sf-field label, .sf-section__title { font-size: 13px; font-weight: 600; color: var(--color-text-maxcontrast); }
.sf-section { display: flex; flex-direction: column; gap: 6px; }
.sf-match-type { display: flex; gap: 4px; margin-bottom: 4px; }
.sf-condition { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.sf-action { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.sf-preview { margin-top: 8px; }
.sf-preview summary { font-size: 12px; color: var(--color-text-maxcontrast); cursor: pointer; }
.sf-preview__code { margin-top: 4px; padding: 8px; background: var(--color-background-dark); border-radius: 6px; font-size: 12px; font-family: monospace; white-space: pre-wrap; max-height: 200px; overflow: auto; }
.sf-buttons { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }
</style>
