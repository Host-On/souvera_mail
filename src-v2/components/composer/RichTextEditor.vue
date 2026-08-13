<template>
	<div v-if="editor" class="richtext-editor">
		<div class="richtext-editor__toolbar">
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Undo')"
				:disabled="!editor.can().undo()"
				@click="editor.chain().focus().undo().run()">
				<template #icon><Undo :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Redo')"
				:disabled="!editor.can().redo()"
				@click="editor.chain().focus().redo().run()">
				<template #icon><Redo :size="18" /></template>
			</NcButton>
			<span class="richtext-editor__separator" />
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
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Strikethrough')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('strike') }"
				@click="editor.chain().focus().toggleStrike().run()">
				<template #icon><FormatStrikethrough :size="18" /></template>
			</NcButton>
			<span class="richtext-editor__separator" />
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Heading 1')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('heading', { level: 1 }) }"
				@click="editor.chain().focus().toggleHeading({ level: 1 }).run()">
				<template #icon><FormatHeader1 :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Heading 2')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('heading', { level: 2 }) }"
				@click="editor.chain().focus().toggleHeading({ level: 2 }).run()">
				<template #icon><FormatHeader2 :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Heading 3')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('heading', { level: 3 }) }"
				@click="editor.chain().focus().toggleHeading({ level: 3 }).run()">
				<template #icon><FormatHeader3 :size="18" /></template>
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
				:aria-label="t('souvera_mail', 'Quote')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('blockquote') }"
				@click="editor.chain().focus().toggleBlockquote().run()">
				<template #icon><FormatQuoteClose :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Code block')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('codeBlock') }"
				@click="editor.chain().focus().toggleCodeBlock().run()">
				<template #icon><CodeTags :size="18" /></template>
			</NcButton>
			<span class="richtext-editor__separator" />
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Link')"
				:class="{ 'richtext-editor__btn--active': editor.isActive('link') }"
				@click="toggleLink">
				<template #icon><Link :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Horizontal line')"
				@click="editor.chain().focus().setHorizontalRule().run()">
				<template #icon><Minus :size="18" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('souvera_mail', 'Clear formatting')"
				@click="editor.chain().focus().clearNodes().unsetAllMarks().run()">
				<template #icon><FormatClear :size="18" /></template>
			</NcButton>
		</div>
		<editor-content :editor="editor" class="richtext-editor__content" @click="focusEditor" />
		<slot name="footer" />
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
import FormatStrikethrough from 'vue-material-design-icons/FormatStrikethrough.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import FormatHeader1 from 'vue-material-design-icons/FormatHeader1.vue'
import FormatHeader2 from 'vue-material-design-icons/FormatHeader2.vue'
import FormatHeader3 from 'vue-material-design-icons/FormatHeader3.vue'
import FormatQuoteClose from 'vue-material-design-icons/FormatQuoteClose.vue'
import Link from 'vue-material-design-icons/Link.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import FormatClear from 'vue-material-design-icons/FormatClear.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import Redo from 'vue-material-design-icons/Redo.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import LinkExtension from '@tiptap/extension-link'
import Underline from '@tiptap/extension-underline'
import Placeholder from '@tiptap/extension-placeholder'

export default {
	name: 'RichTextEditor',
	components: { EditorContent, NcButton, FormatBold, FormatItalic, FormatUnderline, FormatStrikethrough, FormatListBulleted, FormatListNumbered, FormatHeader1, FormatHeader2, FormatHeader3, FormatQuoteClose, Link, Minus, FormatClear, Undo, Redo, CodeTags },
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
		focusEditor() {
			if (this.editor && !this.editor.isFocused) {
				this.editor.commands.focus('end')
			}
		},
		insertHtml(html) {
			this.editor?.chain().focus().insertContent(html).run()
		},
		setContent(html) {
			this.editor?.commands.setContent(html || '')
		},
		setCursorAtStart() {
			this.editor?.commands.setTextSelection(1)
		},
		setCursorAtEnd() {
			this.editor?.commands.setTextSelection(this.editor.state.doc.content.size)
		},
		setCursorInLastEmptyParagraph() {
			const doc = this.editor.state.doc
			let lastEmptyPos = -1
			doc.descendants((node, pos) => {
				if (node.type.name === 'paragraph' && node.childCount === 0) {
					lastEmptyPos = pos + 1
				}
			})
			if (lastEmptyPos >= 0) {
				this.editor.commands.setTextSelection(lastEmptyPos)
			} else {
				this.editor.commands.setTextSelection(doc.content.size)
			}
		},
		toggleLink() {
			const prev = this.editor.getAttributes('link')
			if (prev.href) {
				this.editor.chain().focus().unsetLink().run()
			} else {
				const url = window.prompt(this.t('souvera_mail', 'URL'))
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
	padding: 8px 16px;
	font-size: 14px; line-height: 1.6;
	overflow-y: auto;
	overflow-x: hidden;
	border: none;
}
.richtext-editor__content :deep(.ProseMirror) {
	outline: none;
	min-height: v-bind(minHeight);
	width: 100%;
	word-break: break-word;
	overflow-wrap: break-word;
}
.richtext-editor__content :deep(.ProseMirror p.is-editor-empty:first-child::before) {
	content: attr(data-placeholder);
	color: var(--color-text-maxcontrast);
	pointer-events: none; float: left; height: 0;
}

/* ── Paragraph & break spacing ─────────────────────────────────────── */
.richtext-editor__content :deep(p) { margin: 0 0 14px 0; }
.richtext-editor__content :deep(p:last-child) { margin-bottom: 0; }
.richtext-editor__content :deep(br) { line-height: 1.2; }

/* ── Headings ──────────────────────────────────────────────────────── */
.richtext-editor__content :deep(h1) {
	font-size: 22px; font-weight: 700; line-height: 1.3;
	margin: 16px 0 8px 0;
}
.richtext-editor__content :deep(h2) {
	font-size: 19px; font-weight: 700; line-height: 1.3;
	margin: 14px 0 6px 0;
}
.richtext-editor__content :deep(h3) {
	font-size: 16px; font-weight: 600; line-height: 1.3;
	margin: 12px 0 6px 0;
}

/* ── Lists — bullets/numbers are the key WYSIWYG requirement ───────── */
.richtext-editor__content :deep(ul), .richtext-editor__content :deep(ol) {
	margin: 4px 0 8px 0;
	padding-left: 26px;
}
.richtext-editor__content :deep(ul) { list-style: disc; }
.richtext-editor__content :deep(ul ul) { list-style: circle; margin: 0; }
.richtext-editor__content :deep(ul ul ul) { list-style: square; margin: 0; }
.richtext-editor__content :deep(ol) { list-style: decimal; }
.richtext-editor__content :deep(ol ol) { list-style: lower-alpha; margin: 0; }
.richtext-editor__content :deep(ol ol ol) { list-style: lower-roman; margin: 0; }
.richtext-editor__content :deep(li) { margin-bottom: 2px; }
.richtext-editor__content :deep(li p) { margin: 0; }
.richtext-editor__content :deep(li > ul), .richtext-editor__content :deep(li > ol) { margin-top: 2px; }

/* ── Quote ─────────────────────────────────────────────────────────── */
.richtext-editor__content :deep(blockquote) {
	margin: 8px 0;
	padding: 2px 0 2px 14px;
	border-left: 3px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}
.richtext-editor__content :deep(blockquote p) { margin: 0 0 4px 0; }

/* ── Code ──────────────────────────────────────────────────────────── */
.richtext-editor__content :deep(code) {
	background: var(--color-background-dark);
	border-radius: 3px;
	padding: 1px 5px;
	font-family: 'JetBrains Mono', 'Fira Code', monospace;
	font-size: 0.9em;
}
.richtext-editor__content :deep(pre) {
	background: var(--color-background-dark);
	border-radius: 6px;
	padding: 10px 12px;
	margin: 8px 0;
	overflow-x: auto;
}
.richtext-editor__content :deep(pre code) {
	background: none; padding: 0; font-size: 13px;
	display: block; white-space: pre;
}

/* ── Horizontal rule ───────────────────────────────────────────────── */
.richtext-editor__content :deep(hr) {
	border: none;
	border-top: 1px solid var(--color-border);
	margin: 12px 0;
}

/* ── Links ─────────────────────────────────────────────────────────── */
.richtext-editor__content :deep(a) {
	color: var(--color-primary-element);
	text-decoration: underline;
	cursor: pointer;
}

/* ── Inline marks ──────────────────────────────────────────────────── */
.richtext-editor__content :deep(s), .richtext-editor__content :deep(strike) {
	text-decoration: line-through;
}
.richtext-editor__content :deep(u) { text-decoration: underline; }
.richtext-editor__content :deep(strong) { font-weight: 700; }
.richtext-editor__content :deep(em) { font-style: italic; }

.richtext-editor__loading { display: flex; justify-content: center; padding: 48px; }
</style>
