/* global rl */

/*
 * Souvera Mail — Hilfe-Modal Enricher
 * ----------------------------------------------------------------
 * The vendored Snappymail popup `PopupsKeyboardShortcutsHelp`
 * has been extended in-place with three Souvera-specific tabs:
 *   1. Mail-Client (IMAP/POP3/SMTP/Sieve)
 *   2. Kalender & Kontakte (CalDAV/CardDAV)
 *   3. Shield & Apps
 *
 * The template uses static `data-smail-help="<KEY>"` placeholders
 * (rendered as "—") which THIS file fills at runtime from the
 * `Nextcloud` FilterAppData payload
 * (`rl.settings.get('Nextcloud').SmailHelp*`).
 *
 * We attach ONCE per popup instance via a MutationObserver on the
 * document body — the popup only lives in the DOM after
 * `showScreenPopup(KeyboardShortcutsHelpPopupView)` triggers its
 * lazy `buildViewModel()`. Once we find the popup DOM we run the
 * enrichment on every `open` toggle so the values stay fresh if the
 * user changes their App-Passwort / Shield URL and reopens the help.
 * ----------------------------------------------------------------
 */
(function () {
	'use strict';

	if (!window.rl || !rl.settings || typeof rl.settings.get !== 'function') {
		return;
	}

	var i18n = function (key, fallback) {
		try {
			if (rl && typeof rl.i18n === 'function') {
				var v = rl.i18n(key);
				if (v && v !== key) return v;
			}
		} catch (e) { /* silent */ }
		return fallback;
	};

	var POPUP_ID = 'V-PopupsKeyboardShortcutsHelp';
	var enriched = false;

	function cfg() {
		return rl.settings.get('Nextcloud') || {};
	}

	function valueOrDash(v) {
		var s = (v === undefined || v === null) ? '' : String(v);
		return s === '' ? '—' : s;
	}

	function copy(text) {
		if (!text || text === '—') { return; }
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(String(text));
		} else {
			try {
				var ta = document.createElement('textarea');
				ta.value = String(text);
				ta.style.position = 'fixed'; ta.style.opacity = '0';
				document.body.appendChild(ta);
				ta.select();
				document.execCommand('copy');
				document.body.removeChild(ta);
			} catch (e) { /* silent — no clipboard permissions */ }
		}
	}

	function flashCopied(btn) {
		if (!btn) { return; }
		var original = btn.textContent;
		btn.textContent = i18n('HELP_MODAL/COPIED', '✓ Copied');
		btn.classList.add('sv-help-copy-done');
		setTimeout(function () {
			btn.textContent = original;
			btn.classList.remove('sv-help-copy-done');
		}, 1400);
	}

	function enrichPopup(popupEl) {
		if (!popupEl) { return; }
		var c = cfg();

		// 1a. Fill every element carrying `data-smail-help-i18n="KEY"` with
		// the translated string (used by the static help template for
		// section headings, table headers, tips, etc.). Kept idempotent
		// via `__svI18n` sentinel so we don't re-translate on each open.
		var i18nEls = popupEl.querySelectorAll('[data-smail-help-i18n]');
		Array.prototype.forEach.call(i18nEls, function (el) {
			var key = el.getAttribute('data-smail-help-i18n');
			if (!key) { return; }
			var v = i18n(key, el.getAttribute('data-smail-help-default') || el.textContent);
			if (v && v !== el.textContent) { el.textContent = v; }
		});
		// 1b. Same for elements whose HTML (with markup) is translated —
		// used for paragraphs that mix strong/em/kbd tags.
		var i18nHtmlEls = popupEl.querySelectorAll('[data-smail-help-i18n-html]');
		Array.prototype.forEach.call(i18nHtmlEls, function (el) {
			var key = el.getAttribute('data-smail-help-i18n-html');
			if (!key) { return; }
			var v = i18n(key, null);
			if (v) { el.innerHTML = v; }
		});

		// 1. Fill every <span/code data-smail-help="KEY"> placeholder
		var slots = popupEl.querySelectorAll('[data-smail-help]');
		Array.prototype.forEach.call(slots, function (el) {
			var key = el.getAttribute('data-smail-help');
			el.textContent = valueOrDash(c[key]);
		});

		// 2. Shield block: show if URL present, hide entirely otherwise.
		// End users must never see the missing-Shield fallback (no
		// occ commands in a customer-facing UI).
		var shieldUrl = String(c.SmailHelpShieldUrl || '');
		var shieldBlock = popupEl.querySelector('[data-smail-help-shield-block]');
		var shieldLink = popupEl.querySelector('[data-smail-help-shield-link]');
		if (shieldBlock) {
			if (shieldUrl) {
				shieldBlock.hidden = false;
				if (shieldLink) { shieldLink.setAttribute('href', shieldUrl); }
			} else {
				shieldBlock.hidden = true;
			}
		}

		// 3. Wire clipboard copy buttons (idempotent — attach once via marker)
		var copyBtns = popupEl.querySelectorAll('[data-smail-help-copy], [data-smail-help-copy-pair]');
		Array.prototype.forEach.call(copyBtns, function (btn) {
			if (btn.dataset.smailHelpWired === '1') { return; }
			btn.dataset.smailHelpWired = '1';
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				var single = btn.getAttribute('data-smail-help-copy');
				var pair = btn.getAttribute('data-smail-help-copy-pair');
				var text = '';
				if (single) {
					text = String(cfg()[single] || '');
				} else if (pair) {
					var keys = pair.split(',').map(function (s) { return s.trim(); });
					var vals = keys.map(function (k) { return String(cfg()[k] || ''); }).filter(function (s) { return s !== ''; });
					text = vals.join(':');
				}
				if (text) {
					copy(text);
					flashCopied(btn);
				}
			});
		});
	}

	function attachToPopup(popupEl) {
		enriched = true;
		// Initial enrichment (in case popup is already visible when we attach)
		enrichPopup(popupEl);
		// Re-enrich each time the dialog transitions to open — cheap + keeps
		// values fresh across settings changes without polling.
		var mo = new MutationObserver(function () {
			if (popupEl.hasAttribute('open')) {
				enrichPopup(popupEl);
			}
		});
		mo.observe(popupEl, { attributes: true, attributeFilter: ['open'] });
	}

	function scan() {
		if (enriched) { return; }
		var el = document.getElementById(POPUP_ID);
		if (el) { attachToPopup(el); }
	}

	// Popup is created lazily on first F1 / menu click — watch the body
	// until it appears, then stop observing.
	if (document.body) {
		scan();
		if (!enriched) {
			var bodyObserver = new MutationObserver(function () {
				scan();
				if (enriched) { bodyObserver.disconnect(); }
			});
			bodyObserver.observe(document.body, { childList: true, subtree: true });
		}
	} else {
		document.addEventListener('DOMContentLoaded', function () {
			scan();
			if (!enriched) {
				var bodyObserver = new MutationObserver(function () {
					scan();
					if (enriched) { bodyObserver.disconnect(); }
				});
				bodyObserver.observe(document.body, { childList: true, subtree: true });
			}
		});
	}

})();
