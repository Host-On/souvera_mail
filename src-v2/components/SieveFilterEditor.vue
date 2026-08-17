<template>
	<NcDialog :name="editId ? t('souvera_mail', 'Edit filter') : t('souvera_mail', 'New filter')"
		:open.sync="open"
		size="large"
		@update:open="$emit('close')">
		<div class="sf-editor">
			<!-- Filter name -->
			<div class="sf-field">
				<label>{{ t('souvera_mail', 'Filter name') }}</label>
				<NcTextField v-model="filterName" :placeholder="t('souvera_mail', 'My filter')" />
			</div>

			<!-- Conditions -->
			<div class="sf-section">
				<label class="sf-section__title">{{ t('souvera_mail', 'Conditions') }}</label>
				<div class="sf-match-type">
					<label class="sf-radio" :class="{ 'sf-radio--active': matchType === 'any' }">
						<input type="radio" v-model="matchType" value="any" class="sf-radio__input" />
						<span class="sf-radio__label">{{ t('souvera_mail', 'One condition is enough (OR)') }}</span>
					</label>
					<label class="sf-radio" :class="{ 'sf-radio--active': matchType === 'all' }">
						<input type="radio" v-model="matchType" value="all" class="sf-radio__input" />
						<span class="sf-radio__label">{{ t('souvera_mail', 'All conditions must match (AND)') }}</span>
					</label>
				</div>

				<div v-for="(c, i) in conditions" :key="i" class="sf-condition"
					draggable="true"
					@dragstart="dragStart($event, i, 'condition')"
					@dragover.prevent="dragOver($event, i, 'condition')"
					@dragenter.prevent
					@drop.prevent="drop($event, i, 'condition')"
					@dragend="dragEnd">
					<span class="sf-grip" title="Ziehen zum Umsortieren">
						<DragHorizontal :size="16" />
					</span>
					<select v-model="c.field" class="sf-select">
						<option v-for="o in fieldOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
					</select>
					<select v-model="c.operator" class="sf-select sf-select--op">
						<option v-for="o in operatorOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
					</select>
					<NcTextField v-model="c.value" :placeholder="t('souvera_mail', 'Value')" />
					<NcButton variant="tertiary" @click="conditions.splice(i, 1)" v-if="conditions.length > 1"
						:title="t('souvera_mail', 'Remove condition')">
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

				<div v-for="(a, i) in actions" :key="i" class="sf-action"
					draggable="true"
					@dragstart="dragStart($event, i, 'action')"
					@dragover.prevent="dragOver($event, i, 'action')"
					@dragenter.prevent
					@drop.prevent="drop($event, i, 'action')"
					@dragend="dragEnd">
					<span class="sf-grip" title="Ziehen zum Umsortieren">
						<DragHorizontal :size="16" />
					</span>
					<select v-model="a.type" class="sf-select" @change="a.value = ''">
						<option v-for="o in actionTypes" :key="o.value" :value="o.value">{{ o.label }}</option>
					</select>

					<select v-if="a.type === 'move'" v-model="a.value" class="sf-select sf-select--folder">
						<option v-for="o in mailboxOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
					</select>

					<NcTextField v-if="a.type === 'redirect'" v-model="a.value"
						:placeholder="'user@domain.de'" />

					<NcButton variant="tertiary" @click="actions.splice(i, 1)" v-if="actions.length > 1"
						:title="t('souvera_mail', 'Remove action')">
						<template #icon><Minus :size="16" /></template>
					</NcButton>
				</div>

				<NcButton variant="tertiary" @click="addAction">
					<template #icon><Plus :size="16" /></template>
					{{ t('souvera_mail', 'Add action') }}
				</NcButton>
			</div>

			<!-- Generated Sieve preview -->
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
import { NcDialog, NcButton, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import DragHorizontal from 'vue-material-design-icons/DragHorizontal.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useSieveClient } from '../composables/useSieveClient.js'

const { saveScript, validateScript, rebuild } = useSieveClient()

export default {
	name: 'SieveFilterEditor',
	components: { NcDialog, NcButton, NcTextField, Plus, Minus, DragHorizontal },
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
			dragIndex: -1,
			dragType: '',
			fieldOptions: [
				{ value: 'subject', label: this.t('souvera_mail', 'Subject') },
				{ value: 'from',    label: this.t('souvera_mail', 'From') },
				{ value: 'to',      label: this.t('souvera_mail', 'To') },
				{ value: 'body',    label: this.t('souvera_mail', 'Body') },
			],
			operatorOptions: [
				{ value: 'contains', label: this.t('souvera_mail', 'contains') },
				{ value: 'equals',   label: this.t('souvera_mail', 'equals') },
				{ value: 'starts',   label: this.t('souvera_mail', 'starts with') },
				{ value: 'ends',     label: this.t('souvera_mail', 'ends with') },
				{ value: 'regex',    label: this.t('souvera_mail', 'matches regex') },
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
		mailboxOptions() {
			return this.mailboxes
				.filter(m => m.role !== 'trash' && m.role !== 'junk')
				.map(m => ({ value: m.name, label: m.name }))
		},
		generatedSieve() { return this.buildSieve() },
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
		dragStart(e, index, type) {
			this.dragIndex = index
			this.dragType = type
			e.dataTransfer.effectAllowed = 'move'
			e.dataTransfer.setData('text/plain', String(index))
		},
		dragOver(e, index, type) {
			if (type !== this.dragType) return
			const el = e.currentTarget
			const rect = el.getBoundingClientRect()
			const mid = (rect.top + rect.bottom) / 2
			el.classList.remove('sf-drag--over', 'sf-drag--before')
			el.classList.add(e.clientY < mid ? 'sf-drag--before' : 'sf-drag--over')
		},
		drop(e, index, type) {
			const list = type === 'condition' ? this.conditions : this.actions
			const from = this.dragIndex
			if (from === index) return
			const item = list.splice(from, 1)[0]
			list.splice(from < index ? index - 1 : index, 0, item)
		},
		dragEnd(e) {
			this.dragIndex = -1
			this.dragType = ''
			for (const el of e.currentTarget?.parentElement?.querySelectorAll('.sf-drag--over,.sf-drag--before') || []) {
				el.classList.remove('sf-drag--over', 'sf-drag--before')
			}
		},
		buildSieve() {
			const lines = []
			const needed = new Set()
			if (this.actions.some(a => a.type === 'move')) needed.add('"fileinto"')
			if (this.actions.some(a => a.type === 'markread' || a.type === 'markflag')) needed.add('"imap4flags"')
			if (needed.size > 0) lines.push(`require [${[...needed].join(', ')}];`)
			lines.push('')

			const conds = this.conditions.filter(c => c.field && c.value)
			if (conds.length === 0) return ''

			const op = this.matchType === 'all' ? 'allof' : 'anyof'
			const tests = conds.map(c => {
				let op = ':contains'; let val = c.value
				if (c.operator === 'equals') { op = ':is'; val = `"${val}"` }
				else if (c.operator === 'starts') { op = ':matches'; val = `"${this.escapeRegex(val)}*"` }
				else if (c.operator === 'ends') { op = ':matches'; val = `"*${this.escapeRegex(val)}"` }
				else if (c.operator === 'regex') { op = ':regex'; val = `"${val}"` }
				else { val = `"${val}"` }
				return `header ${op} "${c.field}" ${val}`
			})

			if (tests.length === 1) lines.push(`if ${tests[0]} {`)
			else { lines.push(`if ${op} (`); lines.push('    ' + tests.join(',\n    ')); lines.push(`) {`) }

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
		escapeRegex(str) { return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') },
		parseFromSieve(body) {
			this.conditions = []; this.actions = []
			try {
				const m = body.match(/if ((?:allof|anyof)\s*\(|header\s+\S+\s+"([^"]+)"\s+"([^"]*)"\s*)/s)
				if (m) { this.matchType = (m[0] && m[0].includes('allof')) ? 'all' : 'any' }
				const re = /header\s+(:contains|:is|:matches|:regex)\s+"([^"]+)"\s+"([^"]+)"/g
				let h; while ((h = re.exec(body)) !== null) {
					let op = 'contains'
					if (h[1] === ':is') op = 'equals'
					else if (h[1] === ':regex') op = 'regex'
					else if (h[1] === ':matches') {
						const v = h[3]; if (v.endsWith('*"') && !v.startsWith('"*')) op = 'starts'; else if (v.startsWith('"*') && !v.endsWith('*"')) op = 'ends'
					}
					this.conditions.push({ field: h[2], operator: op, value: h[3].replace(/^"|"$/g, '').replace(/^\*|\*$/g, '') })
				}
				if (this.conditions.length === 0) this.conditions = [{ field: 'subject', operator: 'contains', value: '' }]
				if (body.includes('fileinto')) this.actions.push({ type: 'move', value: (body.match(/fileinto\s+"([^"]+)"/) || [])[1] || '' })
				if (body.includes('addflag "\\\\Seen"') || body.includes('addflag "\\Seen"')) this.actions.push({ type: 'markread', value: '' })
				if (body.includes('addflag "\\\\Flagged"')) this.actions.push({ type: 'markflag', value: '' })
				if (body.includes('redirect')) this.actions.push({ type: 'redirect', value: (body.match(/redirect\s+"([^"]+)"/) || [])[1] || '' })
				if (body.includes('discard')) this.actions.push({ type: 'discard', value: '' })
				if (body.includes('stop')) this.actions.push({ type: 'stop', value: '' })
				if (this.actions.length === 0) this.actions = [{ type: 'move', value: 'Junk' }]
			} catch { this.conditions = [{ field: 'subject', operator: 'contains', value: '' }]; this.actions = [{ type: 'move', value: 'Junk' }] }
		},
		async save() {
			if (!this.filterName.trim()) { showError(this.t('souvera_mail', 'Name is required')); return }
			const body = this.buildSieve()
			if (!body) { showError(this.t('souvera_mail', 'No conditions defined')); return }
			this.saving = true
			try {
				await saveScript(this.filterName.trim(), body)
				// Saving alone does not make Stalwart run the filter —
				// the combined main script must be rebuilt and activated.
				try {
					await rebuild()
				} catch (e2) {
					console.error('Sieve rebuild error', e2)
					showError(this.t('souvera_mail', 'Filter saved, but activation failed') + ': ' + (e2?.response?.data?.error || e2?.message || ''))
				}
				showSuccess(this.t('souvera_mail', 'Filter saved'))
				this.$emit('saved'); this.$emit('close')
			} catch (e) {
				const msg = e?.response?.data?.error || e?.response?.data?.message || e?.message || this.t('souvera_mail', 'Failed to save filter')
				console.error('Sieve save error', e)
				showError(msg)
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
.sf-match-type { display: flex; gap: 0; margin-bottom: 4px; border-radius: 8px; overflow: hidden; border: 2px solid var(--color-border); }
.sf-radio { flex: 1; cursor: pointer; }
.sf-radio__input { position: absolute; opacity: 0; width: 0; height: 0; }
.sf-radio__label { display: block; padding: 8px 12px; text-align: center; font-size: 13px; font-weight: 500;
	background: var(--color-main-background); color: var(--color-text-maxcontrast);
	border-right: 1px solid var(--color-border); transition: all 0.15s; }
.sf-radio:last-child .sf-radio__label { border-right: none; }
.sf-radio:hover .sf-radio__label { background: var(--color-background-hover); }
.sf-radio--active .sf-radio__label { background: var(--color-primary-element); color: #fff; font-weight: 600; }
.sf-condition { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.sf-condition[draggable="true"] { cursor: grab; }
.sf-action { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.sf-action[draggable="true"] { cursor: grab; }
.sf-grip { display: flex; align-items: center; padding: 2px; border-radius: 4px; border: 1px dashed var(--color-border); cursor: grab; flex-shrink: 0; opacity: 0.5; transition: opacity 0.15s; }
.sf-grip:hover { opacity: 1; background: var(--color-background-hover); }
.sf-drag--over { border-top: 2px solid var(--color-primary-element) !important; }
.sf-drag--before { border-bottom: 2px solid var(--color-primary-element) !important; }
.sf-select {
	padding: 6px 8px; border: 1px solid var(--color-border); border-radius: 6px;
	background: var(--color-main-background); color: var(--color-main-text); font-size: 13px;
	height: 34px; min-width: 100px; outline: none;
}
.sf-select:focus { border-color: var(--color-primary-element); }
.sf-select--op { max-width: 130px; }
.sf-select--folder { max-width: 180px; }
.sf-preview { margin-top: 8px; }
.sf-preview summary { font-size: 12px; color: var(--color-text-maxcontrast); cursor: pointer; }
.sf-preview__code { margin-top: 4px; padding: 8px; background: var(--color-background-dark); border-radius: 6px; font-size: 12px; font-family: monospace; white-space: pre-wrap; max-height: 200px; overflow: auto; }
.sf-buttons { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }
</style>
