/*
 * Souvera Mail — live mailbox-quota pill + in-app settings entry-point.
 *
 * Reads two URLs from the FilterAppData payload:
 *   - rl.settings.get('Nextcloud').SmailQuotaUrl     → JSON quota endpoint
 *   - rl.settings.get('Nextcloud').SmailSettingsUrl  → in-app settings page
 *
 * Renders a small pill in the top-right corner of the engine UI. Clicking
 * the pill (or its fallback ⚙ icon when quota is unavailable) opens the
 * Souvera Mail in-app settings page (App Passwords, Dashboard widget mode)
 * in a new tab. Falls back gracefully when the quota endpoint returns 503.
 */
(rl => {
	'use strict';

	const cfg = rl && rl.settings && rl.settings.get && rl.settings.get('Nextcloud');
	if (!cfg) {
		return;
	}

	const PILL_ID = 'souvera-mail-quota-pill';
	const REFRESH_MS = 60000;

	const settingsUrl = cfg.SmailSettingsUrl
		? (cfg.SmailSettingsUrl.split('#')[0] + '#/settings/souvera-account')
		: '';
	const quotaUrl = cfg.SmailQuotaUrl || '';

	const fmtPill = data => {
		if (!data) {
			return '\u2699'; // gear glyph as fallback when no quota
		}
		if (data.unlimited) {
			return `${data.formatted.used} used`;
		}
		return `${data.formatted.used} / ${data.formatted.total}`;
	};

	const pillStyle = (pct, hasQuota) => {
		const warn = hasQuota && pct >= 90;
		const high = hasQuota && pct >= 75 && !warn;
		const bg = warn ? '#c0392b' : (high ? '#e67e22' : 'rgba(0,0,0,0.55)');
		return [
			'position:fixed',
			'top:8px',
			'right:12px',
			'z-index:9999',
			'padding:4px 12px',
			'border-radius:100px',
			'background:' + bg,
			'color:#fff',
			'font:500 12px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif',
			'box-shadow:0 2px 8px rgba(0,0,0,0.18)',
			'pointer-events:auto',
			'user-select:none',
			'cursor:' + (settingsUrl ? 'pointer' : 'default'),
			'backdrop-filter:blur(8px)',
			'-webkit-backdrop-filter:blur(8px)',
			'text-decoration:none',
			'display:inline-flex',
			'align-items:center',
			'gap:6px',
			'transition:transform 100ms ease, box-shadow 100ms ease'
		].join(';');
	};

	const ensurePill = () => {
		let el = document.getElementById(PILL_ID);
		if (!el) {
			el = document.createElement(settingsUrl ? 'a' : 'div');
			el.id = PILL_ID;
			el.setAttribute('role', 'status');
			el.setAttribute('aria-live', 'polite');
			el.setAttribute('data-testid', 'souvera-mail-quota-pill');
			if (settingsUrl) {
				el.href = settingsUrl;
				// Same-tab navigation: the URL is the engine itself with a
				// hash route. If the user is already on the mailbox page,
				// it is a hash-only change (no reload). If they opened the
				// pill from another NC app, the engine takes over the tab.
				el.target = '_self';
				el.rel = 'noopener';
				el.title = 'Open Souvera Mail settings';
				el.addEventListener('mouseenter', function () {
					el.style.transform = 'translateY(-1px)';
					el.style.boxShadow = '0 4px 14px rgba(0,0,0,0.25)';
				});
				el.addEventListener('mouseleave', function () {
					el.style.transform = '';
					el.style.boxShadow = '0 2px 8px rgba(0,0,0,0.18)';
				});
			}
			document.body.appendChild(el);
		}
		return el;
	};

	const removePill = () => {
		const el = document.getElementById(PILL_ID);
		if (el && el.parentNode) {
			el.parentNode.removeChild(el);
		}
	};

	const renderQuota = data => {
		const el = ensurePill();
		el.style.cssText = pillStyle(data.percentage, true);
		el.textContent = fmtPill(data);
		if (settingsUrl) {
			// re-set after textContent wipe
			el.title = `${data.percentage}% used · Click for settings`;
		} else if (!data.unlimited) {
			el.title = data.percentage + '% used';
		} else {
			el.title = 'No quota limit configured';
		}
	};

	const renderSettingsOnly = () => {
		// 0.13.5: there is no longer a separate "Settings" landing — the
		// in-engine Snappymail tab "Sicherheit & Geräte" is reachable
		// directly from the engine's own settings cog. When quota is
		// unavailable we simply hide the pill instead of degrading to
		// a redundant "⚙ Settings" entry-point that confused users
		// after the consolidation.
		removePill();
	};

	const refresh = () => {
		if (!quotaUrl) {
			renderSettingsOnly();
			return;
		}
		fetch(quotaUrl, {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' },
			cache: 'no-store'
		})
		.then(resp => {
			if (!resp.ok) {
				renderSettingsOnly();
				return null;
			}
			return resp.json();
		})
		.then(body => {
			if (!body || body.status !== 'ok') {
				renderSettingsOnly();
				return;
			}
			renderQuota(body);
		})
		.catch(() => renderSettingsOnly());
	};

	setTimeout(refresh, 1500);
	setInterval(refresh, REFRESH_MS);

})(window.rl);
