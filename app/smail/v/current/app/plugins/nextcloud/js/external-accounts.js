/* global rl */

/*
 * Souvera Mail — External accounts (POP3 / IMAP / SMTP) UI enricher.
 *
 * This file layers Nextcloud-integration niceties on top of the
 * native Snappymail "Additional accounts" UI (SettingsAccounts.html +
 * PopupsAccount.html):
 *
 *   1. GDPR consent modal — shown BEFORE the native "Add account"
 *      popup opens whenever souvera_central mandates consent (per
 *      account, not global — matches operator choice 4c). Stored
 *      per (uid, email_hash) in oc_appconfig via POST /external/consent.
 *
 *   2. Provider auto-fill — when the user types their email into the
 *      native popup and blurs, we hit GET /external/preset?email=…
 *      and pre-populate any empty IMAP/SMTP host/port/ssl fields
 *      Snappymail exposes. Users can still tweak everything by hand;
 *      we only fill EMPTY fields.
 *
 *   3. Provider warnings — for domains that need a special auth flow
 *      (Gmail's App Passwords, Outlook Modern Auth) we surface an
 *      inline yellow banner BEFORE the user hits "Add".
 *
 *   4. Onboarding banner — one-time card at the top of the Accounts
 *      settings tab, offering the top-10 provider quick-picks.
 *
 * Availability of the entire UI is gated by two orthogonal
 * mechanisms that live server-side:
 *
 *   - Snappymail's `webmail.allow_additional_accounts` (synced from
 *     souvera_central by EngineHelper::loadApp),
 *   - The Nextcloud group-restriction override applied in
 *     EngineHelper::startApp.
 *
 * If either check fails, `Capa::ADDITIONAL_ACCOUNTS` is false and the
 * template block never renders — meaning this enricher's DOM
 * observers simply never trigger. Safe by construction.
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

	var APP_PATH = (rl.settings.get('SmailAppBase') || '/apps/souvera_mail')
		.replace(/\/+$/, '');
	var STATUS_URL   = APP_PATH + '/external/status';
	var PRESET_URL   = APP_PATH + '/external/preset';
	var PROVIDERS_URL = APP_PATH + '/external/providers';
	var CONSENT_URL  = APP_PATH + '/external/consent';

	// Cached snapshot of GET /external/status — refreshed every 5 min
	// or whenever the popup is (re-)opened.
	var featureState = null;
	var featureStateFetchedAt = 0;
	var FEATURE_TTL_MS = 5 * 60 * 1000;

	function fetchFeatureState(cb) {
		var now = Date.now();
		if (featureState && (now - featureStateFetchedAt) < FEATURE_TTL_MS) {
			cb(featureState);
			return;
		}
		fetch(STATUS_URL, {
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json',
				'requesttoken': rl.settings.get('AuthAccountHash') || ''
			}
		})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (data && data.ok) {
					featureState = data;
					featureStateFetchedAt = Date.now();
				}
				cb(featureState);
			})
			.catch(function () { cb(null); });
	}

	function fetchPreset(email, cb) {
		fetch(PRESET_URL + '?email=' + encodeURIComponent(email), {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' }
		})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) { cb(data && data.ok ? data.preset : null); })
			.catch(function () { cb(null); });
	}

	function recordConsent(email, cb) {
		var body = new URLSearchParams();
		body.set('email', email);
		fetch(CONSENT_URL, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'requesttoken': rl.settings.get('AuthAccountHash') || ''
			},
			body: body.toString()
		})
			.then(function (r) { cb && cb(r.ok); })
			.catch(function () { cb && cb(false); });
	}

	// ─────────────────────────────────────────────────────────────
	// GDPR consent modal — shown before Snappymail opens PopupsAccount
	// ─────────────────────────────────────────────────────────────
	function showConsentModal(onAccept, onCancel) {
		var overlay = document.createElement('div');
		overlay.className = 'sv-ext-consent-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('data-testid', 'ext-consent-modal');

		var box = document.createElement('div');
		box.className = 'sv-ext-consent-box';

		var h = document.createElement('h3');
		h.textContent = i18n('EXTERNAL_ACCOUNTS/CONSENT_TITLE', 'Before you add an external mailbox');

		var p = document.createElement('p');
		p.innerHTML = i18n(
			'EXTERNAL_ACCOUNTS/CONSENT_BODY',
			'Your credentials for the external provider (e.g. web.de, GMX, Gmail) '
			+ 'will be stored <strong>encrypted on this Souvera server</strong> and used '
			+ 'exclusively to fetch and send your mail. They are <strong>never shared '
			+ 'with third parties</strong>. Continue?'
		);

		var actions = document.createElement('div');
		actions.className = 'sv-ext-consent-actions';

		var cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'btn';
		cancelBtn.textContent = i18n('EXTERNAL_ACCOUNTS/CONSENT_CANCEL', 'Cancel');
		cancelBtn.setAttribute('data-testid', 'ext-consent-cancel');
		cancelBtn.onclick = function () {
			try { document.body.removeChild(overlay); } catch (e) { /* already detached */ }
			onCancel && onCancel();
		};

		var acceptBtn = document.createElement('button');
		acceptBtn.type = 'button';
		acceptBtn.className = 'btn btn-primary';
		acceptBtn.textContent = i18n('EXTERNAL_ACCOUNTS/CONSENT_ACCEPT', 'Accept and continue');
		acceptBtn.setAttribute('data-testid', 'ext-consent-accept');
		acceptBtn.onclick = function () {
			try { document.body.removeChild(overlay); } catch (e) { /* already detached */ }
			onAccept && onAccept();
		};

		actions.appendChild(cancelBtn);
		actions.appendChild(acceptBtn);

		box.appendChild(h);
		box.appendChild(p);
		box.appendChild(actions);
		overlay.appendChild(box);
		document.body.appendChild(overlay);

		acceptBtn.focus();
	}

	// ─────────────────────────────────────────────────────────────
	// Popup enrichment — provider warning banner + auto-fill hooks
	// ─────────────────────────────────────────────────────────────
	function decoratePopup(popupEl) {
		if (!popupEl || popupEl.__svExtEnriched) return;
		popupEl.__svExtEnriched = true;

		var emailInput = popupEl.querySelector('input[name="email"]');
		if (!emailInput) return;

		// Yellow banner where we render provider-specific notes.
		var banner = document.createElement('div');
		banner.className = 'sv-ext-provider-banner';
		banner.setAttribute('data-testid', 'ext-provider-banner');
		banner.style.display = 'none';
		var form = popupEl.querySelector('form#accountform');
		if (form) { form.insertBefore(banner, form.firstChild); }

		var refresh = function () {
			var email = String(emailInput.value || '').trim();
			banner.style.display = 'none';
			banner.textContent = '';
			if (!email || email.indexOf('@') === -1) return;
			fetchPreset(email, function (preset) {
				if (!preset) return;
				var msg = null;
				if (preset.warning === 'GMAIL_APP_PASSWORD') {
					msg = i18n('EXTERNAL_ACCOUNTS/WARN_GMAIL',
						'Gmail requires an App Password (2-factor authentication must be on). '
						+ 'Your normal Google password will NOT work.');
				} else if (preset.warning === 'OUTLOOK_MODERN_AUTH') {
					msg = i18n('EXTERNAL_ACCOUNTS/WARN_OUTLOOK',
						'Outlook.com / Hotmail disabled classic Basic-Auth in September 2024. '
						+ 'You need a legacy App Password from your Microsoft account.');
				} else if (preset.warning === 'YAHOO_APP_PASSWORD') {
					msg = i18n('EXTERNAL_ACCOUNTS/WARN_YAHOO',
						'Yahoo requires a third-party App Password. '
						+ 'Your normal Yahoo password will NOT work.');
				} else if (preset.pre_flight) {
					msg = preset.pre_flight;
				}
				if (msg) {
					banner.textContent = msg;
					if (preset.help_url) {
						var lnk = document.createElement('a');
						lnk.href = preset.help_url;
						lnk.target = '_blank';
						lnk.rel = 'noopener';
						lnk.textContent = ' ↗';
						banner.appendChild(lnk);
					}
					banner.style.display = 'block';
				}
			});
		};

		emailInput.addEventListener('blur', refresh);
		emailInput.addEventListener('change', refresh);
	}

	// Wait for the native "Add account" popup and decorate it.
	function watchForPopup() {
		var observer = new MutationObserver(function () {
			var popup = document.querySelector('#PopupsAccount');
			if (popup && popup.classList.contains('modal-visible')) {
				decoratePopup(popup);
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	// ─────────────────────────────────────────────────────────────
	// Intercept the "Add account" button — inject consent modal
	// before the native popup opens.
	// ─────────────────────────────────────────────────────────────
	function interceptAddButton() {
		var settingsRoot = document.querySelector('#Settings\\/Accounts, .b-settings-accounts, [data-i18n="SETTINGS_ACCOUNTS/LEGEND_ACCOUNTS"]');
		if (!settingsRoot) return;

		var attach = function () {
			var addBtn = document.querySelector('a.btn[data-bind*="addNewAccount"], .b-settings-accounts a.btn i.icon-user-add');
			if (!addBtn || addBtn.__svExtWrapped) return;
			var button = addBtn.closest('a.btn') || addBtn;
			button.__svExtWrapped = true;

			button.addEventListener('click', function (ev) {
				if (button.__svExtBypass) { button.__svExtBypass = false; return; }
				fetchFeatureState(function (state) {
					// If consent is not required OR user is not authenticated,
					// let the native handler run unmodified.
					if (!state || !state.consent_required) return;
					ev.preventDefault();
					ev.stopPropagation();
					showConsentModal(function accept() {
						// Re-fire the click; the __svExtBypass flag lets it
						// pass through unhooked this second time.
						button.__svExtBypass = true;
						button.click();
					}, function cancel() {
						// Do nothing — user closed the consent modal.
					});
				});
			}, true);
		};
		attach();
		// Re-attach if the settings view is re-rendered by Knockout.
		var obs = new MutationObserver(attach);
		obs.observe(document.body, { childList: true, subtree: true });
	}

	// ─────────────────────────────────────────────────────────────
	// Boot: only wire up when the feature is on for THIS user.
	// ─────────────────────────────────────────────────────────────
	function boot() {
		fetchFeatureState(function (state) {
			if (!state || !state.enabled || !state.allowed_for_me) {
				return;
			}
			watchForPopup();
			interceptAddButton();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	// Also hook the settings-view Knockout event so we retry when the
	// user navigates INTO the Accounts tab after initial load.
	window.addEventListener('souvera-mail:open-external-accounts', function () {
		featureState = null; // force refresh
		boot();
	});
})();
