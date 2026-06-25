/*
 * Souvera Mail — live mailbox-quota pill.
 *
 * Reads the URL of the quota JSON endpoint from the FilterAppData payload
 * (`rl.settings.get('Nextcloud').SmailQuotaUrl`), fetches it on engine
 * startup and after every mailbox switch, then renders a small pill in
 * the top-right corner of the engine UI.
 *
 * Gracefully hides on any failure (503 service unavailable, network
 * error, missing config) — so users running without souvera_central /
 * H2CK/oidc / Stalwart still get the regular mail UI.
 */
(rl => {
	'use strict';

	const cfg = rl && rl.settings && rl.settings.get && rl.settings.get('Nextcloud');
	if (!cfg || !cfg.SmailQuotaUrl) {
		return;
	}

	const PILL_ID = 'smail-quota-pill';
	const REFRESH_MS = 60000; // server caches for 60 s anyway

	const fmt = data => {
		if (data.unlimited) {
			return `${data.formatted.used} used`;
		}
		return `${data.formatted.used} / ${data.formatted.total}`;
	};

	const pillStyle = pct => {
		const warn = pct >= 90;
		const high = pct >= 75 && !warn;
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
			'cursor:default',
			'backdrop-filter:blur(8px)',
			'-webkit-backdrop-filter:blur(8px)'
		].join(';');
	};

	const ensurePill = () => {
		let el = document.getElementById(PILL_ID);
		if (!el) {
			el = document.createElement('div');
			el.id = PILL_ID;
			el.setAttribute('role', 'status');
			el.setAttribute('aria-live', 'polite');
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

	const render = data => {
		const el = ensurePill();
		el.style.cssText = pillStyle(data.percentage);
		el.textContent = fmt(data);
		if (!data.unlimited) {
			el.title = data.percentage + '% used';
		} else {
			el.title = 'No quota limit configured';
		}
	};

	const refresh = () => {
		fetch(cfg.SmailQuotaUrl, {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' },
			cache: 'no-store'
		})
		.then(resp => {
			if (!resp.ok) {
				removePill();
				return null;
			}
			return resp.json();
		})
		.then(body => {
			if (!body || body.status !== 'ok') {
				removePill();
				return;
			}
			render(body);
		})
		.catch(() => removePill());
	};

	// Initial fetch shortly after engine boot to avoid racing the login flow.
	setTimeout(refresh, 1500);
	// Periodic refresh (server-side cache keeps this cheap).
	setInterval(refresh, REFRESH_MS);

})(window.rl);
