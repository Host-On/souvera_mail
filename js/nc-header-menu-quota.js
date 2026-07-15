/* global t */

/*
 * Souvera Mail — Nextcloud Header-Dropdown Quota entry.
 *
 * Injects a line "Mail storage: 12 MB / 5 GB" into the Nextcloud
 * user menu (the popover that opens when the user clicks their avatar
 * in the top-right corner of every NC page). Loaded by NC on every
 * request via BeforeTemplateRenderedEvent → Util::addScript, so the
 * menu entry is available across the whole product, not just inside
 * the Souvera Mail app.
 *
 * Design notes:
 *   • MutationObserver-based — NC 34+ renders the header menu lazily
 *     via Vue, so the DOM only appears after the user opens it.
 *   • Read-only: the entry is not clickable. Rationale: clicking it
 *     from a foreign NC app (Files, Calendar, …) would have to open
 *     Souvera Mail which is a noticeably heavier route; better to
 *     leave the click semantics of the existing menu untouched and
 *     give the user just the information they asked for.
 *   • Fetches through the same-origin cookie session, no extra auth.
 *   • Fails silent: if the endpoint returns 401/503 (e.g. mail quota
 *     integration not yet configured), no menu entry is added.
 *   • Cache: browser's HTTP cache (server sends `no-store` from the
 *     controller side). We fetch once per document load. That is
 *     good enough because opening the menu after 5 min of activity
 *     forces a fresh page navigation for most users.
 */
(function () {
    'use strict';

    // The endpoint is baked into an inline JSON <script> tag by the
    // NcHeaderMenuQuotaListener so this file works even when Souvera
    // Mail's settings.js isn't loaded (that only ships inside the
    // mail app itself). We read `#souvera-mail-quota-config` on
    // load; if it's missing, this script quietly no-ops.
    const configTag = document.getElementById('souvera-mail-quota-config');
    let endpoint = '';
    if (configTag) {
        try {
            const cfg = JSON.parse(configTag.textContent || '{}');
            endpoint = cfg.endpoint || '';
        } catch (e) { /* malformed → no-op */ }
    }
    if (!endpoint) return;

    const ENTRY_ID = 'souvera-mail-quota-menu-entry';
    const ENTRY_TESTID = 'nc-header-menu-quota-entry';

    let cached = null;
    let fetchPromise = null;

    const loadQuota = () => {
        if (fetchPromise) return fetchPromise;
        fetchPromise = fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        })
        .then(resp => resp.ok ? resp.json() : null)
        .then(body => {
            if (body && body.status === 'ok') cached = body;
            return cached;
        })
        .catch(() => null);
        return fetchPromise;
    };

    const buildEntry = data => {
        // Match NC's own header-menu-item DOM: a <li> containing an
        // <a> with an icon + text. We use a <div>-based wrapper
        // because the entry is non-clickable (see design notes above).
        const li = document.createElement('li');
        li.id = ENTRY_ID;
        li.setAttribute('data-testid', ENTRY_TESTID);
        li.className = 'header-menu__item';
        li.setAttribute('role', 'presentation');

        const label = data.unlimited
            ? t('souvera_mail', 'Mail storage: {used} used', { used: data.formatted.used })
            : t('souvera_mail', 'Mail storage: {used} / {total}', { used: data.formatted.used, total: data.formatted.total });

        li.innerHTML = `
            <div class="header-menu__link" style="
                display:flex;
                align-items:center;
                gap:10px;
                padding:8px 14px;
                color:var(--color-main-text,#222);
                cursor:default;
                font-size:13px;
            ">
                <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"
                     style="flex:0 0 16px;fill:currentColor;opacity:0.75">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zm0 4l8 5 8-5V6l-8 5-8-5v2z"/>
                </svg>
                <span data-testid="quota-menu-label">${escapeHtml(label)}</span>
            </div>
        `.trim();
        return li;
    };

    const escapeHtml = s => String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const findMenuList = () => {
        // NC 34+: settings-menu container. Fallback selectors cover
        // NC 33 and earlier where the container id differed.
        return document.querySelector('#settings ul.menu, #expanddiv ul, #settings .menu ul, .settings-menu-list')
            || document.querySelector('.header-menu__wrapper ul')
            || document.querySelector('#user-menu ul');
    };

    const inject = () => {
        if (document.getElementById(ENTRY_ID)) return;
        const menu = findMenuList();
        if (!menu || !cached) return;

        try {
            const entry = buildEntry(cached);
            // Prepend so the entry sits near the top of the popover,
            // above generic settings / logout entries. Users treat top-
            // of-menu items as more informational.
            menu.insertBefore(entry, menu.firstChild);
        } catch (e) {
            // never let a DOM shape drift crash the page
        }
    };

    // Kick off the fetch immediately (cache stays across menu opens).
    loadQuota().then(inject);

    // Then watch for the menu opening. NC ships it lazily inside a
    // Vue-portaled popover — a MutationObserver on body catches every
    // insertion cheaply because we only do work when our own menu
    // isn't yet mounted (idempotency via id-check inside inject()).
    const mo = new MutationObserver(() => inject());
    mo.observe(document.body, { childList: true, subtree: true });

})();
