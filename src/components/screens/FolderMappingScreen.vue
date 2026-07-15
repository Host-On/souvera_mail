<template>
	<div class="souvera-mapping" data-testid="wizard-screen-mapping">
		<p class="souvera-mapping__lead">
			{{ t('souvera_mail', 'We found {n} folders in your old mailbox. Choose which ones to import. Folder names are kept 1:1 — standard folders like INBOX or Sent go directly into the matching Souvera folder.', { n: folders.length }) }}
		</p>

		<div class="souvera-mapping__toolbar">
			<span class="souvera-mapping__counter" data-testid="wizard-mapping-counter">
				{{ n('souvera_mail', '%n folder selected', '%n folders selected', selectedCount) }}
				<span class="souvera-mapping__counter-total"> / {{ folders.length }}</span>
			</span>
			<div class="souvera-mapping__toolbar-actions">
				<NcButton
					type="tertiary"
					data-testid="wizard-mapping-recommended"
					@click="selectRecommended">
					<template #icon><StarOutline :size="18" /></template>
					{{ t('souvera_mail', 'Recommended') }}
				</NcButton>
				<NcButton
					type="tertiary"
					data-testid="wizard-mapping-all"
					@click="selectAll">
					<template #icon><CheckAll :size="18" /></template>
					{{ t('souvera_mail', 'All') }}
				</NcButton>
				<NcButton
					type="tertiary"
					data-testid="wizard-mapping-none"
					@click="selectNone">
					<template #icon><CloseBoxOutline :size="18" /></template>
					{{ t('souvera_mail', 'None') }}
				</NcButton>
			</div>
		</div>

		<div class="souvera-mapping__list" role="list" data-testid="wizard-mapping-list">
			<label
				v-for="f in enrichedFolders"
				:key="f.path"
				class="souvera-mapping__row"
				:class="{ 'is-selected': selected.has(f.path), 'is-system': f.isSystem }"
				role="listitem"
				:data-testid="`wizard-mapping-row-${f.slug}`">
				<span class="souvera-mapping__row-check">
					<NcCheckboxRadioSwitch
						:model-value="selected.has(f.path)"
						:aria-label="t('souvera_mail', 'Import folder {p}', { p: f.path })"
						@update:model-value="v => toggle(f.path, v)" />
				</span>
				<span class="souvera-mapping__row-source">
					<component :is="f.icon" :size="18" class="souvera-mapping__row-icon" />
					<span class="souvera-mapping__row-name">{{ f.displayName }}</span>
					<span v-if="f.messages > 0" class="souvera-mapping__row-count">
						{{ n('souvera_mail', '%n mail', '%n mails', f.messages) }}
					</span>
				</span>
				<ArrowRight :size="16" class="souvera-mapping__row-arrow" />
				<span class="souvera-mapping__row-target">
					<component :is="f.icon" :size="16" class="souvera-mapping__row-icon" />
					<span class="souvera-mapping__row-name">{{ f.targetName }}</span>
				</span>
			</label>
		</div>

		<NcNoteCard v-if="selectedCount === 0" type="warning" data-testid="wizard-mapping-empty-warning">
			{{ t('souvera_mail', 'Please select at least one folder to import.') }}
		</NcNoteCard>

		<div class="souvera-actions souvera-actions--split">
			<NcButton
				type="tertiary"
				data-testid="wizard-mapping-back"
				@click="$emit('back')">
				<template #icon><ArrowLeft :size="20" /></template>
				{{ t('souvera_mail', 'Back') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="selectedCount === 0"
				data-testid="wizard-mapping-next"
				@click="onNext">
				<template #icon><ArrowRight :size="20" /></template>
				{{ t('souvera_mail', 'Next') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import CloseBoxOutline from 'vue-material-design-icons/CloseBoxOutline.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
// Folder-type icons — one per standard mailbox role.
import Inbox from 'vue-material-design-icons/Inbox.vue'
import SendOutline from 'vue-material-design-icons/SendOutline.vue'
import FileEditOutline from 'vue-material-design-icons/FileEditOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import EmailAlertOutline from 'vue-material-design-icons/EmailAlertOutline.vue'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'

/**
 * Souvera Mail v0.14.14 — FolderMappingScreen.
 *
 * Between ConfirmScreen and progress. Lists every source folder as
 * a 2-column mapping row (source → target) with per-folder checkbox.
 *
 * Intelligent auto-selection (see `enrichedFolders`):
 * - INBOX / Sent / Drafts / Trash / Junk|Spam / Archive: preselected
 * - System folders like `[Gmail]/…`, `[Google Mail]/…`, hidden `.foo`:
 *   deselected, tinted, and get a cog icon (still selectable if user
 *   really wants them)
 * - Everything else: preselected 1:1
 *
 * The `folders`-array is emitted back to the wizard on "Weiter" as a
 * flat list of source paths (`list<string>`) matching the contract
 * expected by `ProviderToolsClient::startMigration()` and the tightened
 * provider.tools v2026-02 API (`folders must be a non-empty array`).
 */

// Aliases used to detect the standard-role of a folder in any language.
// Keys are the canonical Stalwart role names; values are the alias
// strings we recognise (case-insensitive, whitespace-trimmed).
const ROLE_ALIASES = {
	inbox: ['inbox', 'posteingang', 'bandeja de entrada', 'boîte de réception'],
	sent: ['sent', 'sent items', 'sent mail', 'sent messages', 'gesendet', 'gesendete objekte', 'enviados', 'envoyés'],
	drafts: ['drafts', 'entwürfe', 'entwuerfe', 'brouillons', 'borradores'],
	trash: ['trash', 'deleted', 'deleted items', 'deleted messages', 'papierkorb', 'gelöschte objekte', 'corbeille', 'papelera'],
	junk: ['junk', 'junk e-mail', 'spam', 'werbung', 'bulk', 'unwanted'],
	archive: ['archive', 'archiv', 'archives', 'all mail', 'alle e-mails'],
}
const ROLE_ICONS = {
	inbox: Inbox,
	sent: SendOutline,
	drafts: FileEditOutline,
	trash: TrashCanOutline,
	junk: EmailAlertOutline,
	archive: ArchiveOutline,
	system: CogOutline,
	other: FolderOutline,
}
const ROLE_TARGET = {
	inbox: 'INBOX',
	sent: 'Sent',
	drafts: 'Drafts',
	trash: 'Trash',
	junk: 'Junk',
	archive: 'Archive',
}

function detectRole(path) {
	const leaf = (path.split('/').pop() || '').toLowerCase().trim()
	for (const [role, aliases] of Object.entries(ROLE_ALIASES)) {
		if (aliases.includes(leaf)) return role
	}
	return null
}

function isSystemFolder(path) {
	if (!path) return false
	const p = path.toLowerCase()
	// Gmail-style hidden containers, dotfiles, common bot dirs.
	if (p.startsWith('[gmail]') || p.startsWith('[google mail]')) return true
	if (p.startsWith('.') || p.includes('/.')) return true
	if (p === 'outbox' || p === 'postausgang') return true
	return false
}

function slugify(path) {
	return String(path).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'root'
}

export default {
	name: 'FolderMappingScreen',
	components: {
		NcButton, NcCheckboxRadioSwitch, NcNoteCard,
		ArrowLeft, ArrowRight, CheckAll, CloseBoxOutline, StarOutline,
		Inbox, SendOutline, FileEditOutline, TrashCanOutline,
		EmailAlertOutline, ArchiveOutline, FolderOutline, CogOutline,
	},
	props: {
		folders: { type: Array, required: true },   // [{path, messages}]
		selected: { type: Set, required: true },    // Set<string> of source paths
	},
	emits: ['back', 'advance', 'update:selected'],
	setup(props, { emit }) {
		const enrichedFolders = computed(() => {
			return props.folders
				.map(f => {
					const path = String(f.path || '')
					const role = detectRole(path)
					const isSystem = isSystemFolder(path)
					return {
						path,
						messages: Number(f.messages || 0),
						role,
						isSystem,
						icon: role
							? ROLE_ICONS[role]
							: (isSystem ? ROLE_ICONS.system : ROLE_ICONS.other),
						displayName: path,
						// provider.tools does NOT rename folders — target
						// mirrors source. But we DO give standard roles
						// a canonical target label so the user sees where
						// the mail will actually land in the new mailbox.
						targetName: role ? ROLE_TARGET[role] : path,
						slug: slugify(path),
					}
				})
				// Roll INBOX / roles to the top for scanability.
				.sort((a, b) => {
					const rank = (x) => {
						if (x.role === 'inbox') return 0
						if (x.role) return 1
						if (x.isSystem) return 3
						return 2
					}
					const dr = rank(a) - rank(b)
					if (dr !== 0) return dr
					return a.path.localeCompare(b.path, undefined, { numeric: true, sensitivity: 'base' })
				})
		})

		const selectedCount = computed(() => props.selected.size)

		function emitSelected(next) {
			emit('update:selected', next)
		}
		function toggle(path, v) {
			const next = new Set(props.selected)
			if (v) next.add(path); else next.delete(path)
			emitSelected(next)
		}
		function selectAll() {
			emitSelected(new Set(enrichedFolders.value.map(f => f.path)))
		}
		function selectNone() {
			emitSelected(new Set())
		}
		function selectRecommended() {
			emitSelected(new Set(
				enrichedFolders.value.filter(f => !f.isSystem).map(f => f.path)
			))
		}
		function onNext() {
			if (selectedCount.value > 0) emit('advance')
		}

		return {
			enrichedFolders,
			selectedCount,
			toggle,
			selectAll,
			selectNone,
			selectRecommended,
			onNext,
		}
	},
}
</script>

<style scoped>
.souvera-mapping {
	display: flex;
	flex-direction: column;
	gap: var(--sc-field-gap);
}
.souvera-mapping__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.55;
}
.souvera-mapping__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	flex-wrap: wrap;
}
.souvera-mapping__counter {
	font-size: 0.9rem;
	color: var(--color-main-text);
	font-weight: 500;
}
.souvera-mapping__counter-total {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}
.souvera-mapping__toolbar-actions {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
}

.souvera-mapping__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 320px;
	overflow-y: auto;
	padding: 4px;
	margin: 0 -4px;
	border-radius: var(--border-radius-large);
}
/* Soft scroll fade so the list feels finished at the bottom. */
.souvera-mapping__list::-webkit-scrollbar { width: 8px; }
.souvera-mapping__list::-webkit-scrollbar-thumb {
	background: var(--color-border);
	border-radius: 999px;
}

.souvera-mapping__row {
	display: grid;
	grid-template-columns: auto 1fr auto 1fr;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	cursor: pointer;
	transition: background 120ms, border-color 120ms, transform 80ms, opacity 120ms;
}
.souvera-mapping__row:hover {
	background: var(--color-background-hover);
	border-color: var(--color-primary-element);
}
.souvera-mapping__row.is-selected {
	background: rgba(var(--color-primary-element-rgb, 0, 130, 201), 0.06);
	border-color: var(--color-primary-element);
}
.souvera-mapping__row.is-system {
	opacity: 0.65;
}
.souvera-mapping__row.is-system.is-selected {
	opacity: 1;
}

.souvera-mapping__row-check {
	display: flex;
	align-items: center;
}
.souvera-mapping__row-source,
.souvera-mapping__row-target {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	color: var(--color-main-text);
	font-size: 0.9rem;
}
.souvera-mapping__row-target {
	color: var(--color-text-maxcontrast);
}
.souvera-mapping__row.is-selected .souvera-mapping__row-target {
	color: var(--color-main-text);
}
.souvera-mapping__row-icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}
.souvera-mapping__row.is-selected .souvera-mapping__row-icon {
	color: var(--color-primary-element);
}
.souvera-mapping__row-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-family: var(--font-face-monospace, ui-monospace, monospace);
	font-size: 0.85rem;
}
.souvera-mapping__row-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.78rem;
	white-space: nowrap;
	background: var(--color-background-hover);
	padding: 1px 8px;
	border-radius: 999px;
	margin-left: auto;
}
.souvera-mapping__row-arrow {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

@media (max-width: 620px) {
	.souvera-mapping__row {
		grid-template-columns: auto 1fr;
	}
	.souvera-mapping__row-arrow,
	.souvera-mapping__row-target {
		grid-column: 2 / -1;
		font-size: 0.8rem;
	}
	.souvera-mapping__row-target {
		padding-left: 26px; /* align under the source icon */
	}
}
</style>
