/*
 * Souvera Mail — "Filter nachträglich anwenden" (v0.14.37)
 *
 * ─────────────────────────────────────────────────────────────
 * WHY
 *   Sieve is an inbound-delivery filter: it only runs when a NEW
 *   message arrives at the MDA. After the operator has added a
 *   forwarding rule to their Sieve script, older messages that
 *   are already in the mailbox stay untouched — an obvious UX
 *   gap that Snappymail's stock UI does not close.
 *
 *   This enricher injects a button "Auf Ordner anwenden…" into
 *   the Sieve script editor's toolbar. Clicking it opens a modal
 *   with a folder dropdown; on submit, POSTs to
 *   `SmailSieveApplyUrl`. The backend parses the active script
 *   with a small PHP interpreter and executes the resulting
 *   actions via JMAP Email/set (moves), EmailSubmission/set
 *   (redirects), and keyword updates.
 *
 * WHERE THE BUTTON LIVES
 *   Snappymail's Sieve editor is the `PopupsSieveScript` view
 *   (see `app/templates/Views/User/PopupsSieveScript.html`).
 *   Its footer contains `.buttons` with `Speichern`/`Schließen`.
 *   We watch for that footer to appear and prepend our own
 *   button. When the popup closes, our button is removed with
 *   the DOM.
 *
 * DEFENSIVE NOTES
 *   • We only render if BOTH `SmailSieveApplyFoldersUrl` and
 *     `SmailSieveApplyUrl` are present in `rl.settings.Nextcloud`.
 *     Old backends without those endpoints just skip the feature.
 *   • The button is disabled while a request is in flight and
 *     shows an inline spinner so the operator can't fire it
 *     twice by accident (redirect actions have real-world
 *     side-effects — resent emails).
 *   • On success, a toast summarises the counters:
 *     "12 verschoben, 3 weitergeleitet, 1 verworfen".
 *   • On failure, the backend's error message is surfaced
 *     verbatim into the toast — no silent swallowing.
 * ─────────────────────────────────────────────────────────────
 */
(rl => {
    'use strict';

    const cfg = rl && rl.settings && rl.settings.get && rl.settings.get('Nextcloud');
    if (!cfg || !cfg.SmailSieveApplyFoldersUrl || !cfg.SmailSieveApplyUrl) {
        return;
    }

    const FOLDERS_URL = cfg.SmailSieveApplyFoldersUrl;
    const APPLY_URL = cfg.SmailSieveApplyUrl;

    // v0.14.38: diagnostic breadcrumb — if you don't see the button on
    // the live app, open the browser DevTools console and look for
    // this line. Its presence proves that (a) the plugin was loaded
    // by Snappymail and (b) the two endpoint URLs made it through
    // rl.settings.Nextcloud. Absence means the plugin registration
    // (`app/plugins/nextcloud/index.php`) or the URL-injection
    // (`SmailSieveApplyUrl`) didn't reach the browser — usually a
    // stale opcode cache; run `sudo -u www-data php occ maintenance:
    // repair` or restart php-fpm.
    if (window.console && window.console.info) {
        window.console.info('[Souvera Mail] sieve-apply.js loaded, endpoints:', {
            foldersUrl: FOLDERS_URL, applyUrl: APPLY_URL
        });
    }

    // -----------------------------------------------------------
    // CSRF token — mounted at `document.head.dataset.oc-requesttoken`
    // by Nextcloud; some builds also expose it on window.
    // -----------------------------------------------------------
    const csrfToken = () =>
        (document.head && document.head.dataset && document.head.dataset.requesttoken)
        || (window.OC && window.OC.requestToken)
        || '';

    // -----------------------------------------------------------
    // Small DOM helper — avoids pulling in a whole framework.
    // -----------------------------------------------------------
    const el = (tag, attrs, children) => {
        const node = document.createElement(tag);
        if (attrs) {
            for (const k of Object.keys(attrs)) {
                const v = attrs[k];
                if (k === 'className') { node.className = v; }
                else if (k === 'onclick') { node.addEventListener('click', v); }
                else if (k === 'style') { node.setAttribute('style', v); }
                else if (v !== null && v !== undefined) { node.setAttribute(k, v); }
            }
        }
        (children || []).forEach(c => {
            if (typeof c === 'string') { node.appendChild(document.createTextNode(c)); }
            else if (c) { node.appendChild(c); }
        });
        return node;
    };

    // -----------------------------------------------------------
    // Modal — plain absolute overlay, no dependency on Snappymail's
    // popup framework (which is Knockout-based and hard to piggyback
    // without a compile step).
    // -----------------------------------------------------------
    const openModal = () => {
        const overlay = el('div', {
            className: 'souvera-sieve-apply-overlay',
            'data-testid': 'sieve-apply-modal',
            style: [
                'position:fixed', 'inset:0',
                'background:rgba(0,0,0,0.45)',
                'z-index:10000',
                'display:flex', 'align-items:center', 'justify-content:center'
            ].join(';')
        });

        const body = el('div', {
            className: 'souvera-sieve-apply-body',
            style: [
                'background:var(--color-main-background,#fff)',
                'color:var(--color-main-text,#222)',
                'border-radius:var(--border-radius,8px)',
                'padding:24px 28px',
                'width:min(480px, 90vw)',
                'box-shadow:0 10px 30px rgba(0,0,0,0.25)',
                'font-family:var(--font-face, system-ui)'
            ].join(';')
        });

        const title = el('h3', {
            style: 'margin:0 0 8px 0;font-size:18px;font-weight:600;'
        }, ['Filter nachträglich anwenden']);

        const explain = el('p', {
            style: 'margin:0 0 20px 0;font-size:13px;line-height:1.5;color:var(--color-text-maxcontrast,#666);'
        }, [
            'Das aktive Sieve-Skript wird auf die letzten 2000 Nachrichten des ',
            'gewählten Ordners angewendet — Verschieben, Weiterleiten und ',
            'Löschen werden ausgeführt. Weitergeleitete Nachrichten gehen ',
            'ERNEUT per SMTP an die im Skript hinterlegten Empfänger.'
        ]);

        const label = el('label', {
            style: 'display:block;font-size:13px;font-weight:600;margin-bottom:6px;'
        }, ['Ordner']);
        const select = el('select', {
            'data-testid': 'sieve-apply-folder-select',
            style: [
                'width:100%',
                'padding:8px 10px',
                'border:1px solid var(--color-border,#d5d5d5)',
                'border-radius:var(--border-radius,8px)',
                'background:var(--color-main-background,#fff)',
                'color:var(--color-main-text,#222)',
                'font-size:14px',
                'margin-bottom:18px'
            ].join(';')
        }, [el('option', { value: '' }, ['— Lade Ordner … —'])]);
        select.disabled = true;

        const status = el('div', {
            'data-testid': 'sieve-apply-status',
            style: 'font-size:13px;min-height:1.4em;margin-bottom:16px;color:var(--color-text-maxcontrast,#666);'
        }, ['']);

        const cancelBtn = el('button', {
            type: 'button',
            'data-testid': 'sieve-apply-cancel',
            className: 'button',
            style: 'margin-right:8px;'
        }, ['Abbrechen']);
        const applyBtn = el('button', {
            type: 'button',
            'data-testid': 'sieve-apply-run',
            className: 'button primary',
            style: [
                'background:var(--color-primary-element,#0693e3)',
                'color:var(--color-primary-element-text,#fff)',
                'border:0',
                'padding:8px 16px',
                'border-radius:var(--border-radius,8px)',
                'font-weight:600',
                'cursor:pointer'
            ].join(';')
        }, ['Anwenden']);
        applyBtn.disabled = true;

        const footer = el('div', {
            style: 'display:flex;justify-content:flex-end;align-items:center;'
        }, [cancelBtn, applyBtn]);

        body.appendChild(title);
        body.appendChild(explain);
        body.appendChild(label);
        body.appendChild(select);
        body.appendChild(status);
        body.appendChild(footer);
        overlay.appendChild(body);
        document.body.appendChild(overlay);

        const closeModal = () => {
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        };
        cancelBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', ev => {
            if (ev.target === overlay) { closeModal(); }
        });

        // Load folders.
        fetch(FOLDERS_URL, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', requesttoken: csrfToken() }
        }).then(r => r.json()).then(data => {
            if (!data || data.status !== 'ok' || !Array.isArray(data.folders)) {
                status.textContent = data && data.message
                    ? 'Ordnerliste: ' + data.message
                    : 'Ordnerliste konnte nicht geladen werden.';
                return;
            }
            select.innerHTML = '';
            data.folders.forEach(f => {
                const opt = el('option', { value: f.id }, [
                    f.name + (f.role === 'inbox' ? '  (Posteingang)' : '')
                ]);
                if (f.role === 'inbox') { opt.selected = true; }
                select.appendChild(opt);
            });
            select.disabled = false;
            applyBtn.disabled = false;
        }).catch(err => {
            status.textContent = 'Netzwerkfehler beim Laden der Ordnerliste: ' + err;
        });

        applyBtn.addEventListener('click', () => {
            if (applyBtn.disabled) return;
            applyBtn.disabled = true;
            cancelBtn.disabled = true;
            select.disabled = true;
            status.textContent = 'Wende Filter an — bitte warten …';

            const folderId = select.value;
            fetch(APPLY_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    requesttoken: csrfToken()
                },
                body: JSON.stringify({
                    folderId: folderId,
                    limit: 2000,
                    includeRedirect: true
                })
            }).then(r => r.json()).then(data => {
                if (!data || data.status !== 'ok') {
                    status.textContent = 'Fehler: ' + ((data && data.message) || 'unbekannt');
                    applyBtn.disabled = false;
                    cancelBtn.disabled = false;
                    return;
                }
                const summary = [
                    (data.scanned || 0) + ' geprüft',
                    (data.moved || 0) + ' verschoben',
                    (data.redirected || 0) + ' weitergeleitet',
                    (data.discarded || 0) + ' verworfen',
                    (data.flagged || 0) + ' markiert'
                ].join(', ');
                status.innerHTML =
                    '<span style="color:var(--color-success,#3a7))">✓ Fertig — ' + summary + '</span>';
                if (data.errors && data.errors.length) {
                    status.innerHTML +=
                        '<div style="margin-top:8px;color:var(--color-warning,#c60);">'
                        + data.errors.length + ' Warnung(en) — siehe nextcloud.log'
                        + '</div>';
                }
                cancelBtn.textContent = 'Schließen';
                cancelBtn.disabled = false;
                // Nudge Snappymail to re-read the mailbox counts so the
                // folder tree shows the new state without an F5.
                if (rl.app && rl.app.folderInformationMultiplyList) {
                    try { rl.app.folderInformationMultiplyList([folderId]); } catch (_) { /* Snappymail rejected the refresh — non-fatal */ }
                }
            }).catch(err => {
                status.textContent = 'Netzwerkfehler: ' + err;
                applyBtn.disabled = false;
                cancelBtn.disabled = false;
            });
        });
    };

    // -----------------------------------------------------------
    // Toolbar injection.
    //
    // Snappymail renders every popup as `<dialog id="V-<Name>">` (see
    // app.js buildViewModel: `createElement('dialog',{id:'V-'+id})`).
    // The Sieve editor's `viewModelTemplateID` is literally
    // `SieveScript`, so we look for `#V-SieveScript`.
    //
    // Inside the dialog the template contributes a `<footer>` element
    // (see `app/templates/Views/User/PopupsSieveScript.html:72`) that
    // holds the Save button. We prepend our own `<a class="btn">` so
    // it appears LEFT of the save action — matching Snappymail's own
    // convention (raw-script-toggle also sits on the left).
    //
    // The dialog is created ONCE on first-use and reused afterwards
    // (Knockout `open`/`close` toggles the attribute, not the element),
    // so a single injection is enough. If the dialog doesn't exist
    // yet at plugin-load time we watch for it via MutationObserver.
    // -----------------------------------------------------------
    const BUTTON_ID = 'souvera-sieve-apply-toolbar-btn';

    const injectButtonInto = (dialog) => {
        if (!dialog || dialog.querySelector('#' + BUTTON_ID)) { return true; }
        const footer = dialog.querySelector('footer') || dialog.querySelector('.buttons');
        if (!footer) { return false; }
        const btn = el('a', {
            id: BUTTON_ID,
            'data-testid': 'sieve-apply-toolbar-btn',
            href: '#',
            className: 'btn',
            title: 'Aktives Filter-Skript auf einen bereits vorhandenen Ordner anwenden.',
            style: 'margin-right:auto;'
        }, ['Auf Ordner anwenden…']);
        btn.addEventListener('click', ev => {
            ev.preventDefault();
            ev.stopPropagation();
            openModal();
        });
        footer.insertBefore(btn, footer.firstChild);
        return true;
    };

    const tryInject = () => {
        const dialog = document.querySelector('#V-SieveScript');
        return dialog ? injectButtonInto(dialog) : false;
    };

    const observer = new MutationObserver(() => tryInject());
    const start = () => {
        if (!document.body) { return; }
        // Try once immediately — the dialog may already be in the DOM
        // when we load (Snappymail bootstraps popups on-demand, but
        // some builds pre-create them).
        if (tryInject()) { return; }
        // Otherwise watch for the dialog to appear.
        observer.observe(document.body, { childList: true, subtree: true });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window.rl || {});
