<template>
	<Teleport to="body">
		<div v-if="open" class="row-context-menu" :style="menuStyle"
			@click.stop @contextmenu.stop.prevent>
			<div class="row-context-menu__head">{{ email?.subject || '' }}</div>
			<button class="row-context-menu__item" @click="onToggleRead">
				{{ email?.isRead ? t('souvera_mail', 'Mark as unread') : t('souvera_mail', 'Mark as read') }}
			</button>
			<button class="row-context-menu__item" @click="onSpam">{{ t('souvera_mail', 'Spam') }}</button>
			<div class="row-context-menu__label">{{ t('souvera_mail', 'Move to folder') }}</div>
			<button v-for="mb in mailboxes" :key="'rcm-' + mb.id"
				class="row-context-menu__item row-context-menu__item--indent"
				@click="onMove(mb.id)">{{ mailboxDisplayName(mb) }}</button>
			<button class="row-context-menu__item row-context-menu__item--danger" @click="onDelete">
				{{ t('souvera_mail', 'Delete') }}
			</button>
		</div>
	</Teleport>
</template>

<script>
import { mailboxDisplayName } from '../utils/mailboxNames.js'

/**
 * Rechtsklick-Kontextmenü für Mail-Listenzeilen.
 *
 * Bewusst vollständig eigenständig: Das Menü verwaltet seinen Zustand
 * intern, sodass das Öffnen/Schließen NIE die MailHomeView (und damit die
 * Liste) neu rendert. Aktionen laufen über das stabile `handlers`-Objekt,
 * das die MailHomeView einmalig bereitstellt.
 */
export default {
	name: 'RowContextMenu',

	props: {
		mailboxes: { type: Array, default: () => [] },
		handlers: { type: Object, default: () => ({}) },
	},

	data() {
		return { open: false, x: 0, y: 0, email: null }
	},

	computed: {
		menuStyle() {
			const width = 260
			const height = 320
			let x = this.x
			let y = this.y
			if (typeof window !== 'undefined') {
				if (x + width > window.innerWidth - 8) x = Math.max(8, window.innerWidth - width - 8)
				if (y + height > window.innerHeight - 8) y = Math.max(8, window.innerHeight - height - 8)
			}
			return { left: x + 'px', top: y + 'px' }
		},
	},

	mounted() {
		this._onClick = () => this.close()
		this._onKey = (ev) => { if (ev.key === 'Escape') this.close() }
		this._onScroll = () => this.close()
		// Öffnen über den Fenster-Event-Bus — bewusst OHNE $refs zwischen
		// Eltern- und Kind-Komponente (unabhängig von Instanz-Exposition).
		this._onOpenEvent = (ev) => {
			const d = ev && ev.detail
			if (d && d.email) this.show(d.x, d.y, d.email)
		}
		window.addEventListener('click', this._onClick)
		window.addEventListener('keydown', this._onKey)
		window.addEventListener('scroll', this._onScroll, true)
		window.addEventListener('souvera-row-menu-open', this._onOpenEvent)
	},

	beforeUnmount() {
		window.removeEventListener('click', this._onClick)
		window.removeEventListener('keydown', this._onKey)
		window.removeEventListener('scroll', this._onScroll, true)
		window.removeEventListener('souvera-row-menu-open', this._onOpenEvent)
	},

	methods: {
		/** Öffnet das Menü an der Mausposition für die übergebene Mail. */
		show(x, y, email) {
			this.x = x
			this.y = y
			this.email = email
			this.open = true
		},
		close() {
			this.open = false
			this.email = null
		},
		onToggleRead() {
			const email = this.email
			this.close()
			if (email && this.handlers.toggleRead) this.handlers.toggleRead(email)
		},
		onSpam() {
			const email = this.email
			this.close()
			if (email && this.handlers.spam) this.handlers.spam(email)
		},
		onMove(mailboxId) {
			const email = this.email
			this.close()
			if (email && this.handlers.move) this.handlers.move(email, mailboxId)
		},
		onDelete() {
			const email = this.email
			this.close()
			if (email && this.handlers.remove) this.handlers.remove(email)
		},
	},
}
</script>

<style scoped>
.row-context-menu {
	position: fixed;
	z-index: 10000;
	width: 260px;
	max-height: 320px;
	overflow-y: auto;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
	padding: 6px;
}
.row-context-menu__head {
	font-size: .78rem;
	color: var(--color-text-maxcontrast);
	padding: 6px 10px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 4px;
}
.row-context-menu__label {
	font-size: .72rem;
	text-transform: uppercase;
	letter-spacing: .04em;
	color: var(--color-text-maxcontrast);
	padding: 8px 10px 4px;
}
.row-context-menu__item {
	display: block;
	width: 100%;
	text-align: left;
	background: none;
	border: none;
	border-radius: var(--border-radius, 8px);
	padding: 8px 10px;
	font-size: .9rem;
	color: var(--color-main-text);
	cursor: pointer;
}
.row-context-menu__item:hover { background: var(--color-background-hover); }
.row-context-menu__item--indent { padding-left: 24px; }
.row-context-menu__item--danger { color: var(--color-error); }
.row-context-menu__item--danger:hover { background: var(--color-error-light, rgba(200, 60, 60, 0.08)); }
</style>
