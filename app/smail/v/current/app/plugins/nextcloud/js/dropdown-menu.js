/* global rl */

/*
 * Souvera Mail — Menu-Item „Alte Mails importieren" im Snappymail
 * SystemDropDown (Top-Right User-Dropdown).
 * ----------------------------------------------------------------
 * The vendored Snappymail SystemDropDown.html has these entries:
 *
 *   Konten (accounts)
 *   Konto hinzufügen        ← visible if allowAccounts
 *   Kontakte                ← visible if allowContacts
 *   Einstellungen           ⚙
 *   Hilfe                   🛈       ← ← from the operator screenshot
 *   Ausloggen               ⏻
 *
 * The operator asked us (2026-02-19) to add „Alte Mails importieren"
 * to THIS dropdown (not the Nextcloud user-menu — we removed that
 * entry from `Application.php` in the same commit).
 *
 * Injection strategy — same pattern as `help-modal.js`:
 *
 *   1. MutationObserver on document.body watches for the dropdown menu
 *      surfaced by Snappymail on first click.
 *   2. When we find `menu[aria-labelledby="top-system-dropdown-id"]`,
 *      we insert a new <li> RIGHT BEFORE the "Hilfe"-item so the
 *      operator sees it in the same visual neighbourhood.
 *   3. Click dispatches `window` event `souvera-mail:open-migration`
 *      which the Vue app (App.vue) listens on and treats identically
 *      to the `?openMigration=1` URL parameter — i.e. force-opens the
 *      wizard even if the user has previously dismissed the welcome.
 *
 * Idempotent: we tag the injected <li> with `data-sv-mig-menu="1"`
 * and never inject a second one, no matter how often Snappymail
 * re-renders the dropdown.
 * ----------------------------------------------------------------
 */
(function () {
	'use strict';

	if (!window.rl) return;

	var MARKER = 'sv-mig-menu';
	var RESYNC_MARKER = 'sv-resync-menu';
	var MENU_SEL = 'menu[aria-labelledby="top-system-dropdown-id"]';
	var HELP_SEL = 'a[data-i18n="GLOBAL/HELP"]';

	function openMigration() {
		try {
			window.dispatchEvent(new CustomEvent('souvera-mail:open-migration'));
		} catch (e) {
			// Very old browser fallback — reload the page with the
			// query param the Vue bootstrap already knows about.
			var u = new URL(window.location.href);
			u.searchParams.set('openMigration', '1');
			window.location.href = u.toString();
		}
	}

	function openResync() {
		try {
			window.dispatchEvent(new CustomEvent('souvera-mail:open-resync'));
		} catch (e) { /* very old browser — silent no-op */ }
	}

	function closeDropdown() {
		var toggle = document.getElementById('top-system-dropdown-id');
		if (toggle && toggle.getAttribute('aria-expanded') === 'true') {
			try { toggle.click(); } catch (_e) { /* silent */ }
		}
	}

	function buildItem(marker, icon, label, testid, onClick) {
		var li = document.createElement('li');
		li.setAttribute('role', 'presentation');
		li.setAttribute('data-' + marker, '1');
		var a = document.createElement('a');
		a.setAttribute('href', '#');
		a.setAttribute('tabindex', '-1');
		a.setAttribute('data-icon', icon);
		a.setAttribute('data-testid', testid);
		a.textContent = label;
		a.addEventListener('click', function (ev) {
			ev.preventDefault();
			closeDropdown();
			onClick();
		});
		li.appendChild(a);
		return li;
	}

	function injectInto(menu) {
		if (!menu) return;
		var helpLink = menu.querySelector(HELP_SEL);
		var helpLi = helpLink ? helpLink.closest('li') : null;

		// v0.14.17 — migration entry (idempotent)
		if (!menu.querySelector('[data-' + MARKER + ']')) {
			var migLi = buildItem(
				MARKER, '📥', 'Alte Mails importieren',
				'souvera-mail-menu-migration', openMigration
			);
			if (helpLi && helpLi.parentNode === menu) {
				menu.insertBefore(migLi, helpLi);
			} else {
				menu.appendChild(migLi);
			}
		}

		// v0.14.19 — resync entry (idempotent), sits above the migration
		// entry so the order is: Einstellungen ⚙ · ↻ Postfach sync ·
		// 📥 Import · 🛈 Hilfe · ⏻ Ausloggen.
		//
		// v0.14.41 (operator screenshot 2026-02-19): the color-emoji 🔄
		// stood out against Snappymail's native icons (⚙ 🛈 ⏻). Switched
		// to ↻ (U+21BB CLOCKWISE OPEN CIRCLE ARROW) which renders as a
		// plain text glyph everywhere and matches the vanilla entries.
		if (!menu.querySelector('[data-' + RESYNC_MARKER + ']')) {
			var resyncLi = buildItem(
				RESYNC_MARKER, '↻', 'Postfach neu synchronisieren',
				'souvera-mail-menu-resync', openResync
			);
			var migAnchor = menu.querySelector('[data-' + MARKER + ']');
			if (migAnchor && migAnchor.parentNode === menu) {
				menu.insertBefore(resyncLi, migAnchor);
			} else if (helpLi && helpLi.parentNode === menu) {
				menu.insertBefore(resyncLi, helpLi);
			} else {
				menu.appendChild(resyncLi);
			}
		}
	}

	function scanOnce() {
		var menus = document.querySelectorAll(MENU_SEL);
		Array.prototype.forEach.call(menus, injectInto);
	}

	// Watch the DOM once — Snappymail materialises the dropdown
	// lazily on first click. childList observation on <body> is
	// cheap because we short-circuit fast on non-menu nodes.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scanOnce, { once: true });
	} else {
		scanOnce();
	}

	var observer = new MutationObserver(function (records) {
		for (var i = 0; i < records.length; i++) {
			var added = records[i].addedNodes;
			for (var j = 0; j < added.length; j++) {
				var n = added[j];
				if (n && n.nodeType === 1) {
					if (n.matches && n.matches(MENU_SEL)) {
						injectInto(n);
					} else if (n.querySelector) {
						var m = n.querySelector(MENU_SEL);
						if (m) injectInto(m);
					}
				}
			}
		}
	});
	observer.observe(document.body, { childList: true, subtree: true });
})();
