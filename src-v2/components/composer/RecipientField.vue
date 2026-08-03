<template>
	<div class="recipient-field">
		<div class="recipient-field__chips">
			<NcChip v-for="(r, i) in modelValue" :key="i"
				:text="formatRecipient(r)"
				:closeable="true"
				@close="removeRecipient(i)" />
			<input ref="input" class="recipient-field__input"
				v-model="inputText"
				:placeholder="placeholderText"
				@keydown="onKeydown"
				@blur="onBlur"
				@input="onInput" />
		</div>
		<ul v-if="suggestions.length > 0" class="recipient-field__suggestions">
			<li v-for="(c, i) in suggestions" :key="c.email"
				:class="{ 'suggestion--active': i === suggestionIndex }"
				@mousedown.prevent="selectContact(c)">
				<span class="suggestion-name">{{ c.name }}</span>
				<span class="suggestion-email">{{ c.email }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcChip } from '@nextcloud/vue'
import { useContactSearch } from '../../composables/useContactSearch.js'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export default {
	name: 'RecipientField',
	components: { NcChip },
	props: {
		modelValue: { type: Array, default: () => [] },
		label: { type: String, default: '' },
		placeholder: { type: String, default: '' },
	},
	emits: ['update:modelValue'],
	setup() {
		const { suggestions, searching, search, clear } = useContactSearch()
		return { suggestions, searching, search, clear }
	},
	data() {
		return { inputText: '', suggestionIndex: -1 }
	},
	computed: {
		placeholderText() {
			return this.modelValue.length === 0 ? this.placeholder || '…' : ''
		},
	},
	methods: {
		formatRecipient(r) {
			return r.name ? `"${r.name}" <${r.email}>` : r.email
		},
		commitRecipient() {
			const raw = this.inputText.trim()
			if (!raw) return
			const match = raw.match(/"?([^"]*)"?\s*<([^>]+)>/) || (EMAIL_RE.test(raw) ? [raw, '', raw] : null)
			if (match) {
				const entry = { name: match[1]?.trim() || '', email: match[2]?.trim() || raw }
				if (!this.modelValue.some(r => r.email.toLowerCase() === entry.email.toLowerCase())) {
					this.$emit('update:modelValue', [...this.modelValue, entry])
				}
			}
			this.inputText = ''
			this.clear()
		},
		removeRecipient(index) {
			const copy = [...this.modelValue]
			copy.splice(index, 1)
			this.$emit('update:modelValue', copy)
		},
		onKeydown(e) {
			if (e.key === 'Enter' || e.key === ',') {
				e.preventDefault()
				if (this.suggestionIndex >= 0 && this.suggestions[this.suggestionIndex]) {
					this.selectContact(this.suggestions[this.suggestionIndex])
				} else {
					this.commitRecipient()
				}
			} else if (e.key === 'Backspace' && !this.inputText && this.modelValue.length > 0) {
				this.removeRecipient(this.modelValue.length - 1)
			} else if (e.key === 'ArrowDown') {
				e.preventDefault()
				if (this.suggestionIndex < this.suggestions.length - 1) this.suggestionIndex++
			} else if (e.key === 'ArrowUp') {
				e.preventDefault()
				if (this.suggestionIndex > 0) this.suggestionIndex--
			} else if (e.key === 'Escape') {
				this.clear()
			}
		},
		onInput() {
			this.suggestionIndex = -1
			this.search(this.inputText)
		},
		onBlur() {
			setTimeout(() => {
				this.commitRecipient()
				this.clear()
			}, 150)
		},
		selectContact(contact) {
			const entry = { name: contact.name || '', email: contact.email }
			if (!this.modelValue.some(r => r.email.toLowerCase() === entry.email.toLowerCase())) {
				this.$emit('update:modelValue', [...this.modelValue, entry])
			}
			this.inputText = ''
			this.clear()
			this.$refs.input?.focus()
		},
	},
}
</script>

<style scoped>
.recipient-field { position: relative; }
.recipient-field__chips {
	display: flex; flex-wrap: wrap; gap: 4px;
	padding: 6px 0;
	min-height: 36px;
	align-items: center;
}
.recipient-field__input {
	flex: 1; min-width: 120px;
	border: none; outline: none;
	background: transparent;
	font: inherit; font-size: 14px;
	padding: 4px 0;
	color: var(--color-main-text);
}
.recipient-field__suggestions {
	position: absolute; top: 100%; left: 0; right: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	max-height: 200px; overflow-y: auto;
	z-index: 10; list-style: none; margin: 2px 0 0; padding: 0;
	box-shadow: var(--color-box-shadow);
}
.suggestion-item, .recipient-field__suggestions li {
	display: flex; justify-content: space-between;
	padding: 8px 12px; cursor: pointer;
}
.recipient-field__suggestions li:hover, .suggestion--active {
	background: var(--color-background-hover);
}
.suggestion-name { font-weight: 600; }
.suggestion-email { font-size: 12px; color: var(--color-text-maxcontrast); }
</style>
