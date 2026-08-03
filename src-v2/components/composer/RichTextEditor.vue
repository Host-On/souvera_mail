<template>
	<div v-if="editor" class="richtext-editor">
		<div class="richtext-editor__toolbar">
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Bold')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('bold') }"
				@click="editor.chain().focus().toggleBold().run()">
				<template #icon><FormatBold :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Italic')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('italic') }"
				@click="editor.chain().focus().toggleItalic().run()">
				<template #icon><FormatItalic :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Underline')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('underline') }"
				@click="editor.chain().focus().toggleUnderline().run()">
				<template #icon><FormatUnderline :size="18" /></template>
			</NcButton>
			<span class="richtext-editor__separator" />
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Bullet list')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('bulletList') }"
				@click="editor.chain().focus().toggleBulletList().run()">
				<template #icon><FormatListBulleted :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Numbered list')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('orderedList') }"
				@click="editor.chain().focus().toggleOrderedList().run()">
				<template #icon><FormatListNumbered :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Link')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('link') }"
				@click="toggleLink">
				<template #icon><Link :size="18" /></template>
			</NcButton>
			<span class="richtext-editor__separator" />
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Clear formatting')"
				@click="editor.chain().focus().clearNodes().unsetAllMarks().run()">
				<template #icon><FormatClear :size="18" /></template>
			</NcButton>
		</div>
		<editor-content :editor="editor" class="richtext-editor__content" />
	</div>
	<div v-else class="richtext-editor__loading">
		<span class="icon-loading" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import FormatBold from 'vue-material-design-icons/FormatBold.vue'
import FormatItalic from 'vue-material-design-icons/FormatItalic.vue'
import FormatUnderline from 'vue-material-design-icons/FormatUnderline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import Link from 'vue-material-design-icons/Link.vue'
import FormatClear from 'vue-material-design-icons/FormatClear.vue'
import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import LinkExtension from '@tiptap/extension-link'
import Underline from '@tiptap/extension-underline'
import Placeholder from '@tiptap/extension-placeholder'

export default {
	name: 'RichTextEditor',
	components: { EditorContent, NcButton, FormatBold, FormatItalic, FormatUnderline, FormatListBulleted, FormatListNumbered, Link, FormatClear },
	props: {
		modelValue: { type: String, default: '' },
		placeholder: { type: String, default: '' },
		minHeight: { type: String, default: '280px' },
	},
	emits: ['update:modelValue'],
	data() {
		return { editor: null }
	},
	watch: {
		modelValue(val) {
			if (this.editor && val !== this.editor.getHTML()) {
				this.editor.commands.setContent(val, false)
			}
		},
	},
	mounted() {
		this.editor = new Editor({
			content: this.modelValue,
			extensions: [
				StarterKit.configure({
					heading: { levels: [1, 2, 3] },
				}),
				Underline,
				LinkExtension.configure({
					openOnClick: false,
					HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer' },
				}),
				Placeholder.configure({
					placeholder: this.placeholder,
				}),
			],
			onUpdate: () => {
				this.$emit('update:modelValue', this.editor.getHTML())
			},
		})
	},
	beforeUnmount() {
		this.editor?.destroy()
	},
	methods: {
		focus() { this.editor?.commands.focus() },
		insertHtml(html) {
			this.editor?.chain().focus().insertContent(html).run()
		},
		toggleLink() {
			const prev = this.editor.getAttributes('link')
			if (prev.href) {
				this.editor.chain().focus().unsetLink().run()
			} else {
				const url = window.prompt(t('souvera_mail', 'URL'))
				if (url) {
					this.editor.chain().focus().setLink({ href: url }).run()
				}
			}
		},
	},
}
</script>

<style scoped>
.richtext-editor, .richtext-editor * { box-sizing: border-box; }

.richtext-editor {
	display: flex; flex-direction: column;
	width: 100%; height: 100%;
	background: var(--color-main-background);
	border: none;
}
.richtext-editor__toolbar {
	display: flex; align-items: center; gap: 2px;
	padding: 8px 20px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
	flex-wrap: wrap; flex-shrink: 0;
}
.richtext-editor__separator {
	width: 1px; height: 20px;
	background: var(--color-border);
	margin: 0 6px;
}
.richtext-editor__btn--active {
	background: var(--color-background-hover) !important;
	color: var(--color-primary-element) !important;
}
.richtext-editor__content {
	flex: 1;
	width: 100%;
	min-height: v-bind(minHeight);
	padding: 16px 20px;
	font-size: 14px; line-height: 1.6;
	overflow-y: auto;
	border: none;
}
.richtext-editor__content :deep(.ProseMirror) {
	outline: none;
	min-height: v-bind(minHeight);
	width: 100%;
}
.richtext-editor__content :deep(.ProseMirror p.is-editor-empty:first-child::before) {
	content: attr(data-placeholder);
	color: var(--color-text-maxcontrast);
	pointer-events: none; float: left; height: 0;
}
.richtext-editor__content :deep(blockquote) {
	margin: 0 0 0 8px; padding-left: 12px;
	border-left: 2px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}
.richtext-editor__loading { display: flex; justify-content: center; padding: 48px; }
</style>
