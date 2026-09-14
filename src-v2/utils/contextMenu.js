/**
 * Zentrales Kontextmenü für den Webmailer.
 *
 * Ein einziger, sauber aufgeräumter Implementierungspfad für ALLE
 * Rechtsklick-Menüs (Mail-Zeilen, Spam-Ansicht, Sidebar-Ordner, Anhänge):
 *  - Viewport-Clamping (Menü wird nie rechts/unten abgeschnitten)
 *  - Schließen bei Escape, Klick außerhalb, Scroll, Resize, Rechtsklick-woanders
 *  - a11y: role="menu"/"menuitem", Pfeiltasten/Home/End, Fokus-Rückgabe
 *  - Touch: Long-Press-freundlich (Klicks direkt nach dem Öffnen ignorieren)
 *  - Kein Listener-Leak: alles wird in close() entfernt
 *
 * Usage:
 *   openContextMenu({ x: ev.clientX, y: ev.clientY, opener: ev.target, header: '2 ausgewählt',
 *     items: [ { icon: SVG, label: 'Löschen', danger: true, onClick: () => … },
 *              { type: 'divider' } ] })
 *
 * Der Aufrufer entscheidet, ob er ev.preventDefault() setzt — die Regel
 * im Webmailer: nur preventDefault, wenn tatsächlich ein Menü geöffnet wird.
 */

let current = null

/**
 * @param {{
 *   x: number, y: number,
 *   opener?: Element|null,
 *   header?: string,
 *   items: Array<{ type?: 'item'|'divider'|'header', icon?: string, label?: string,
 *                  danger?: boolean, disabled?: boolean, onClick?: Function }>,
 * }} opts
 */
export function openContextMenu(opts) {
	closeContextMenu()

	const items = Array.isArray(opts?.items) ? opts.items : []
	if (items.length === 0) return null

	const el = document.createElement('div')
	el.className = 'sm-ctx-menu'
	el.setAttribute('role', 'menu')
	el.setAttribute('aria-orientation', 'vertical')

	// Optionaler Kopf (z. B. „3 ausgewählt“)
	if (opts.header) {
		const head = document.createElement('div')
		head.className = 'sm-ctx-menu__header'
		head.textContent = opts.header
		el.appendChild(head)
	}

	let firstItem = null
	for (const item of items) {
		if (item?.type === 'divider') {
			const d = document.createElement('div')
			d.className = 'sm-ctx-menu__divider'
			el.appendChild(d)
			continue
		}
		if (item?.type === 'header') {
			const h = document.createElement('div')
			h.className = 'sm-ctx-menu__section'
			h.textContent = item.label ?? ''
			el.appendChild(h)
			continue
		}
		if (item?.disabled) {
			const b = document.createElement('button')
			b.type = 'button'
			b.className = 'sm-ctx-menu__item sm-ctx-menu__item--disabled'
			b.disabled = true
			b.setAttribute('role', 'menuitem')
			appendContent(b, item)
			el.appendChild(b)
			continue
		}
		const b = document.createElement('button')
		b.type = 'button'
		b.className = 'sm-ctx-menu__item' + (item?.danger ? ' sm-ctx-menu__item--danger' : '')
		b.setAttribute('role', 'menuitem')
		b.setAttribute('tabindex', '-1')
		appendContent(b, item)
		b.addEventListener('click', () => {
			closeContextMenu()
			try {
				item?.onClick?.()
			} catch (e) {
				// Aktionen des Aufrufers dürfen das Menü-Modul nie brechen.
				console.error('[souvera-mail] context menu action failed', e)
			}
		})
		if (firstItem === null) firstItem = b
		el.appendChild(b)
	}

	// Viewport-Clamping: rechts/unten genug Platz lassen.
	const W = 232
	const estH = Math.min(window.innerHeight - 24, 26 + items.length * 38)
	el.style.left = Math.max(8, Math.min(opts.x, window.innerWidth - W - 8)) + 'px'
	el.style.top = Math.max(8, Math.min(opts.y, window.innerHeight - estH - 8)) + 'px'
	// Wenn unten kein Platz ist: nach oben aufklappen (Origin oben).
	if (opts.y + estH + 16 > window.innerHeight) {
		el.classList.add('sm-ctx-menu--up')
	}

	document.body.appendChild(el)

	// Escape / Pfeiltasten / Fokus-Verwaltung
	const opener = opts.opener ?? document.activeElement
	const focusables = () => Array.from(el.querySelectorAll('.sm-ctx-menu__item:not(:disabled)'))
	const onKeydown = (ev) => {
		if (ev.key === 'Escape') {
			ev.stopPropagation()
			closeContextMenu()
			return
		}
		if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp' && ev.key !== 'Home' && ev.key !== 'End') {
			return
		}
		ev.preventDefault()
		const list = focusables()
		if (list.length === 0) return
		const idx = list.indexOf(document.activeElement)
		let next = idx
		if (ev.key === 'ArrowDown' || (ev.key === 'Home' && idx === -1)) next = idx < 0 ? 0 : (idx + 1) % list.length
		else if (ev.key === 'ArrowUp') next = idx < 0 ? list.length - 1 : (idx - 1 + list.length) % list.length
		else if (ev.key === 'Home') next = 0
		else next = list.length - 1
		list[next]?.focus()
	}

	// Klick außerhalb (capture), Scroll, Resize, anderes Rechtsklicken
	const onPointerDown = (ev) => {
		if (!el.contains(ev.target)) closeContextMenu()
	}
	const onScroll = () => closeContextMenu()
	const onResize = () => closeContextMenu()
	const onOtherContext = (ev) => {
		if (!el.contains(ev.target)) closeContextMenu()
	}
	// Touch: ein Klick/Tap direkt nach dem Öffnen (z. B. Long-Press-„Up“)
	// darf das Menü nicht sofort schließen oder eine Aktion auslösen.
	const openedAt = Date.now()
	const onClickCapture = (ev) => {
		if (Date.now() - openedAt < 300 && !el.contains(ev.target)) {
			ev.stopPropagation()
			ev.preventDefault()
			closeContextMenu()
		}
	}

	const close = () => {
		if (current !== api) return
		current = null
		el.remove()
		document.removeEventListener('keydown', onKeydown, true)
		document.removeEventListener('pointerdown', onPointerDown, true)
		document.removeEventListener('click', onClickCapture, true)
		document.removeEventListener('scroll', onScroll, true)
		document.removeEventListener('contextmenu', onOtherContext, true)
		window.removeEventListener('resize', onResize)
		if (opener instanceof HTMLElement && document.contains(opener)) opener.focus({ preventScroll: true })
		opts.onClose?.()
	}

	const api = { close, el }
	current = api

	document.addEventListener('keydown', onKeydown, true)
	document.addEventListener('pointerdown', onPointerDown, true)
	document.addEventListener('click', onClickCapture, true)
	document.addEventListener('scroll', onScroll, true)
	document.addEventListener('contextmenu', onOtherContext, true)
	window.addEventListener('resize', onResize)

	if (firstItem) firstItem.focus({ preventScroll: true })
	return api
}

export function closeContextMenu() {
	if (current) current.close()
}

function appendContent(button, item) {
	if (item.icon) {
		const ic = document.createElement('span')
		ic.className = 'sm-ctx-menu__icon'
		ic.innerHTML = item.icon
		button.appendChild(ic)
	}
	const tx = document.createElement('span')
	tx.className = 'sm-ctx-menu__text'
	tx.textContent = item.label ?? ''
	button.appendChild(tx)
}
