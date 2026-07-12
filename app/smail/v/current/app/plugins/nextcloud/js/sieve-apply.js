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
    // Toolbar injection — watches for the Sieve popup to appear
    // in the DOM. Snappymail rebuilds the popup fresh every time,
    // so we can't add the button once and forget.
    // -----------------------------------------------------------
    const BUTTON_ID = 'souvera-sieve-apply-toolbar-btn';

    const injectButton = () => {
        // Snappymail's PopupsSieveScript.html has a `.b-content` div
        // and a `.b-buttons` footer. We insert the button as the
        // first child of `.b-buttons` so it sits before the built-in
        // Save/Cancel buttons.
        const popup = document.querySelector('.b-popups-sieve-script, .popup-sieve-script, [data-view="popups/sievescript"]');
        if (!popup) { return; }
        if (popup.querySelector('#' + BUTTON_ID)) { return; }

        // Try known footer selectors — Snappymail vendors have varied.
        const footer =
            popup.querySelector('.b-buttons') ||
            popup.querySelector('.buttons') ||
            popup.querySelector('footer') ||
            popup.querySelector('.popup-footer');
        if (!footer) { return; }

        const btn = el('button', {
            id: BUTTON_ID,
            'data-testid': 'sieve-apply-toolbar-btn',
            type: 'button',
            className: 'button',
            title: 'Aktives Filter-Skript auf einen bereits vorhandenen Ordner anwenden.',
            style: 'margin-right:auto;'
        }, ['Auf Ordner anwenden…']);
        btn.addEventListener('click', ev => {
            ev.preventDefault();
            ev.stopPropagation();
            openModal();
        });
        footer.insertBefore(btn, footer.firstChild);
    };

    // MutationObserver — watch document for the popup appearing.
    const observer = new MutationObserver(() => injectButton());
    const start = () => {
        if (!document.body) { return; }
        observer.observe(document.body, { childList: true, subtree: true });
        injectButton();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window.rl || {});
