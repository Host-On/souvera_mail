/*
 * Souvera Mail — mailbox-quota display, v0.14.30 rewrite.
 *
 * ───────────────────────────────────────────────────────────────
 * WHAT
 *   • A slim quota bar at the BOTTOM of Snappymail's folder sidebar
 *     (`.b-folders`), matching the operator's 2026-02-19 request:
 *     "Unten in der Snappymail-Ordner-Sidebar".
 *   • Colour thresholds:  0-79%   NC-blue
 *                        80-94%   orange (`--color-warning`)
 *                        95-100%  red   (`--color-error`)
 *   • Poll every 5 minutes (was 60 s; operator asked for slower cadence
 *     because Stalwart JMAP is already cached server-side and hourly
 *     usage swings are the norm).
 *   • Toast warning INSIDE the Snappymail engine when usage crosses
 *     95 %  — once per browser session, not per poll.
 *   • Graceful degradation: no bar when the backend returns
 *     unavailable / 503 / no cfg.
 *
 * WHY drop the top-right pill?
 *   Two reasons. First, the operator explicitly asked to move it into
 *   the sidebar. Second, the pill overlapped the NC-native badge column
 *   in a couple of NC 34+ themes and confused users into thinking it
 *   was a NC notification. The sidebar placement is where every other
 *   webmail client parks the quota gauge (Roundcube, Rainloop, Kolab).
 * ───────────────────────────────────────────────────────────────
 */
(rl => {
    'use strict';

    const cfg = rl && rl.settings && rl.settings.get && rl.settings.get('Nextcloud');
    if (!cfg) {
        return;
    }

    // Safe i18n lookup — falls back to English default.
    const i18n = (key, fallback) => {
        try {
            if (rl && typeof rl.i18n === 'function') {
                const v = rl.i18n(key);
                if (v && v !== key) return v;
            }
        } catch (e) { /* silent */ }
        return fallback;
    };

    const BAR_ID       = 'souvera-mail-quota-bar';
    const REFRESH_MS   = 5 * 60 * 1000;       // 5 minutes
    const WARN_THRESHOLD  = 80;
    const ALERT_THRESHOLD = 95;

    const quotaUrl    = cfg.SmailQuotaUrl || '';
    const settingsUrl = cfg.SmailSettingsUrl
        ? (cfg.SmailSettingsUrl.split('#')[0] + '#/settings/souvera-account')
        : '';

    let alertShown = false;

    // ---------------------------------------------------------------
    // DOM helpers
    // ---------------------------------------------------------------
    const findSidebar = () =>
        document.querySelector('#rl-folder-list')
        || document.querySelector('.b-folders')
        || document.querySelector('[class*="b-folders"]');

    const ensureBar = () => {
        let el = document.getElementById(BAR_ID);
        if (el) return el;

        const sidebar = findSidebar();
        if (!sidebar) return null;

        el = document.createElement(settingsUrl ? 'a' : 'div');
        el.id = BAR_ID;
        el.setAttribute('data-testid', BAR_ID);
        if (settingsUrl) {
            el.href = settingsUrl;
            el.target = '_self';
            el.rel = 'noopener';
        }

        el.innerHTML = `
            <div class="quota-label">
                <span class="quota-title" data-testid="quota-label-title"></span>
                <span class="quota-numbers" data-testid="quota-label-numbers"></span>
            </div>
            <div class="quota-track">
                <div class="quota-fill" data-testid="quota-fill"></div>
            </div>
        `.trim();

        sidebar.appendChild(el);
        return el;
    };

    const removeBar = () => {
        const el = document.getElementById(BAR_ID);
        if (el && el.parentNode) el.parentNode.removeChild(el);
    };

    const applyState = (el, data) => {
        const fill  = el.querySelector('.quota-fill');
        const numEl = el.querySelector('.quota-numbers');

        if (data.unlimited) {
            // No bar for unlimited accounts — just the usage number
            // (operator spec 2026-02-19 → option a).
            el.setAttribute('data-quota-mode', 'unlimited');
            fill.style.width = '0%';
            numEl.textContent = i18n('QUOTA/USED_LABEL', '{used} used').replace('{used}', data.formatted.used);
            el.title = i18n('QUOTA/UNLIMITED_TITLE', 'No storage limit configured');
            return;
        }

        el.removeAttribute('data-quota-mode');
        fill.style.width = data.percentage + '%';
        numEl.textContent = `${data.formatted.used} / ${data.formatted.total}`;

        // Colour tier
        let tier = 'ok';
        if (data.percentage >= ALERT_THRESHOLD) tier = 'alert';
        else if (data.percentage >= WARN_THRESHOLD) tier = 'warn';
        el.setAttribute('data-quota-tier', tier);

        el.title = i18n('QUOTA/PERCENT_TITLE', '{p}% used').replace('{p}', data.percentage)
            + (settingsUrl ? ' · ' + i18n('QUOTA/CLICK_FOR_SETTINGS', 'Click for settings') : '');

        // Toast escalation at ≥95 % — once per session.
        if (tier === 'alert' && !alertShown) {
            alertShown = true;
            showAlertToast(data);
        }
    };

    const showAlertToast = data => {
        // Prefer Snappymail's own notification pipeline when available.
        // rl.Notification is provided by the engine and hooks into its
        // localisation + accessibility. Falls back to a lightweight
        // inline toast when the engine hasn't booted it yet.
        const msg = i18n(
            'QUOTA/ALERT_TOAST',
            'Your mailbox is {p}% full ({u} / {t}). Please delete old mail or attachments so new messages can still be accepted.'
        )
            .replace('{p}', data.percentage)
            .replace('{u}', data.formatted.used)
            .replace('{t}', data.formatted.total);

        try {
            if (rl && rl.Notification && typeof rl.Notification.showI18n === 'function') {
                rl.Notification.showI18n(msg);
                return;
            }
            if (rl && rl.Notification && typeof rl.Notification.show === 'function') {
                rl.Notification.show(msg);
                return;
            }
        } catch (e) { /* fall through to DOM toast */ }

        const toast = document.createElement('div');
        toast.className = 'souvera-quota-toast';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('data-testid', 'souvera-quota-toast');
        toast.textContent = msg;
        Object.assign(toast.style, {
            position: 'fixed',
            top: '12px',
            right: '12px',
            zIndex: '10000',
            maxWidth: '420px',
            padding: '12px 18px',
            background: 'var(--color-error, #c0392b)',
            color: 'var(--color-primary-element-text, #fff)',
            borderRadius: '10px',
            boxShadow: '0 6px 24px rgba(0,0,0,0.25)',
            font: '500 13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif',
            cursor: 'pointer',
        });
        toast.addEventListener('click', () => {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        });
        document.body.appendChild(toast);
        // Auto-dismiss after 15 s so the user isn't blocked forever.
        setTimeout(() => {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 15000);
    };

    // ---------------------------------------------------------------
    // Fetch loop
    // ---------------------------------------------------------------
    const refresh = () => {
        if (!quotaUrl) {
            removeBar();
            return;
        }
        fetch(quotaUrl, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        })
        .then(resp => resp.ok ? resp.json() : null)
        .then(body => {
            if (!body || body.status !== 'ok') {
                removeBar();
                return;
            }
            const el = ensureBar();
            if (!el) return;
            const title = el.querySelector('.quota-title');
            if (title) title.textContent = i18n('QUOTA/MAIL_STORAGE', 'Mail storage');
            applyState(el, body);
        })
        .catch(() => removeBar());
    };

    // The sidebar is a KO template — it may still be booting when this
    // script runs. Retry the initial paint until the container exists
    // (max 30 attempts × 250 ms = 7.5 s).
    let bootAttempts = 0;
    const boot = () => {
        if (findSidebar()) {
            refresh();
            setInterval(refresh, REFRESH_MS);
            return;
        }
        if (++bootAttempts < 30) {
            setTimeout(boot, 250);
        }
    };
    boot();

})(window.rl);
