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

	function injectInto(menu) {
		if (!menu || menu.querySelector('[data-' + MARKER + ']')) return;
		var helpLink = menu.querySelector(HELP_SEL);
		var helpLi = helpLink ? helpLink.closest('li') : null;

		var li = document.createElement('li');
		li.setAttribute('role', 'presentation');
		li.setAttribute('data-' + MARKER, '1');

		var a = document.createElement('a');
		a.setAttribute('href', '#');
		a.setAttribute('tabindex', '-1');
		a.setAttribute('data-icon', '📥');
		a.setAttribute('data-testid', 'souvera-mail-menu-migration');
		// Deutsch — no i18n placeholder because Snappymail's translation
		// engine treats data-i18n as an in-house key, not a free string.
		a.textContent = 'Alte Mails importieren';
		a.addEventListener('click', function (ev) {
			ev.preventDefault();
			// Close the dropdown by clicking its toggle a second time;
			// Bootstrap-KO honours the second click as toggle-off.
			var toggle = document.getElementById('top-system-dropdown-id');
			if (toggle && toggle.getAttribute('aria-expanded') === 'true') {
				try { toggle.click(); } catch (_e) { /* silent */ }
			}
			openMigration();
		});
		li.appendChild(a);

		if (helpLi && helpLi.parentNode === menu) {
			menu.insertBefore(li, helpLi);
		} else {
			// Fallback: append near the top so it never lands on the
			// logout row (which is the visual danger zone).
			menu.appendChild(li);
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
