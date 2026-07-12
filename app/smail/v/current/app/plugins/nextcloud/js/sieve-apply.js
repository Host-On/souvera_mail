/* global rl */

/*
 * Souvera Mail — Menu-Item „Filter auf Ordner anwenden…" im Snappymail
 * SystemDropDown (Top-Right User-Dropdown).
 * ----------------------------------------------------------------
 * Why THIS injection point (v0.14.39 rewrite)
 * -------------------------------------------
 * v0.14.37 tried to inject the button into the <footer> of the
 * <dialog id="V-SieveScript"> popup. That approach was fragile
 * because:
 *   1. The dialog is created lazily by Snappymail's buildViewModel
 *      on first `showModal()` — timing races with our observer
 *   2. On mobile browsers the dialog's DOM structure has extra
 *      wrapping <div>s that broke our querySelector('footer')
 *   3. Even when injection succeeded, the button was cut off by
 *      the popup's `max-height + overflow: hidden` on small screens
 *
 * The dropdown-menu injection (already used by
 * `js/dropdown-menu.js` for „Alte Mails importieren") is a proven
 * pattern that works across every screen size and popup state.
 * The operator finds the entry in the top-right ⋮ menu, next to
 * „Alte Mails importieren" — same neighbourhood, same UX.
 *
 * Order in the menu (after this file loads):
 *   Konten
 *   Konto hinzufügen
 *   Kontakte
 *   Einstellungen ⚙
 *   🔄 Postfach neu synchronisieren
 *   📥 Alte Mails importieren
 *   🔎 Filter auf Ordner anwenden…     ← NEW
 *   🛈 Hilfe
 *   ⏻ Ausloggen
 * ----------------------------------------------------------------
 */
(function () {
    'use strict';

    if (!window.rl) { return; }

    const cfg = rl.settings && rl.settings.get && rl.settings.get('Nextcloud');
    if (!cfg || !cfg.SmailSieveApplyFoldersUrl || !cfg.SmailSieveApplyUrl) {
        return;
    }

    const FOLDERS_URL = cfg.SmailSieveApplyFoldersUrl;
    const APPLY_URL = cfg.SmailSieveApplyUrl;
    const MARKER = 'sv-sieve-apply-menu';
    const MENU_SEL = 'menu[aria-labelledby="top-system-dropdown-id"]';
    // Anchor: place the item RIGHT BEFORE the migration entry so the
    // order reads „🔄 Sync → 📥 Import → 🔎 Filter → 🛈 Hilfe".
    const MIG_ANCHOR_SEL = '[data-sv-mig-menu]';
    const HELP_SEL = 'a[data-i18n="GLOBAL/HELP"]';

    // Diagnostic breadcrumb — appears once per page-load in the browser
    // console. If you don't see this, the plugin didn't reach the JS
    // layer (usually stale opcode cache or plugin config not loaded).
    if (window.console && window.console.info) {
        window.console.info('[Souvera Mail] sieve-apply.js loaded; endpoints:', {
            foldersUrl: FOLDERS_URL,
            applyUrl: APPLY_URL
        });
    }

    // ---------------------------------------------------------------
    // CSRF token — mounted at `document.head.dataset.requesttoken` by
    // Nextcloud; some builds also expose it on window.
    // ---------------------------------------------------------------
    const csrfToken = () =>
        (document.head && document.head.dataset && document.head.dataset.requesttoken)
        || (window.OC && window.OC.requestToken)
        || '';

    // ---------------------------------------------------------------
    // Small DOM helper.
    // ---------------------------------------------------------------
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

    // ---------------------------------------------------------------
    // The modal — same UX as v0.14.37 but self-contained (no popup-
    // context assumption). Called both from the dropdown menu entry
    // and from the custom event `souvera-mail:open-sieve-apply`
    // (so the Vue overlay can also open it).
    // ---------------------------------------------------------------
    let modalOpen = false;
    const openModal = () => {
        if (modalOpen) { return; }
        modalOpen = true;

        const overlay = el('div', {
            className: 'souvera-sieve-apply-overlay',
            'data-testid': 'sieve-apply-modal',
            style: [
                'position:fixed', 'inset:0',
                'background:rgba(0,0,0,0.45)',
                'z-index:100000',
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
                'max-height:90vh', 'overflow-y:auto',
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
            modalOpen = false;
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
            if (applyBtn.disabled) { return; }
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
                body: JSON.stringify({ folderId: folderId, limit: 2000, includeRedirect: true })
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
                    '<span style="color:var(--color-success,#3a7);">✓ Fertig — ' + summary + '</span>';
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
                    try {
                        rl.app.folderInformationMultiplyList([folderId]);
                    } catch (_) { /* Snappymail rejected the refresh — non-fatal */ }
                }
            }).catch(err => {
                status.textContent = 'Netzwerkfehler: ' + err;
                applyBtn.disabled = false;
                cancelBtn.disabled = false;
            });
        });
    };

    // Publish the opener under a custom event so the Vue overlay can
    // trigger the modal without touching Snappymail internals.
    window.addEventListener('souvera-mail:open-sieve-apply', openModal);

    // ---------------------------------------------------------------
    // Popup-footer injection (v0.14.40 return to A after user request)
    //
    // The Sieve popup template `templates/Views/User/PopupsSieveScript.html`
    // is wrapped in `<!-- ko with: script -->` — meaning the `<footer>`
    // element ONLY exists in the DOM after the user has clicked either
    // an existing script name or "Skript hinzufügen". A one-shot
    // querySelector on load misses it every single time. We instead
    // watch every subtree mutation and inject whenever we spot a
    // `#V-SieveScript` dialog that ALSO has a `<footer>` inside.
    //
    // Snappymail's own toolbar buttons use `<a class="btn">…</a>` with
    // an inner `<i class="fontastic">EMOJI</i>` — we mirror that shape
    // so the button looks native (not glaringly custom-styled).
    // ---------------------------------------------------------------
    const POPUP_BTN_ID = 'souvera-sieve-apply-popup-btn';

    const buildFooterButton = () => {
        const a = document.createElement('a');
        a.id = POPUP_BTN_ID;
        a.className = 'btn';
        a.href = '#';
        a.title = 'Aktives Filter-Skript auf einen bereits vorhandenen Ordner anwenden.';
        a.setAttribute('data-testid', 'sieve-apply-toolbar-btn');
        const i = document.createElement('i');
        i.className = 'fontastic';
        i.textContent = '🔎';
        a.appendChild(i);
        const s = document.createElement('span');
        s.textContent = ' Auf Ordner anwenden…';
        a.appendChild(s);
        a.addEventListener('click', ev => {
            ev.preventDefault();
            ev.stopPropagation();
            openModal();
        });
        return a;
    };

    const injectPopupFooter = () => {
        // Snappymail may create <dialog id="V-SieveScript"> lazily; we
        // therefore look everywhere for a matching dialog. Once the
        // dialog exists AND has a `<footer>`, inject.
        const dialogs = document.querySelectorAll('dialog#V-SieveScript, #V-SieveScript');
        dialogs.forEach(dlg => {
            if (dlg.querySelector('#' + POPUP_BTN_ID)) { return; }
            const footer = dlg.querySelector('footer');
            if (!footer) { return; }
            // Insert as FIRST child so it sits LEFT of the raw-toggle
            // and Save buttons — matching Snappymail's own conventions
            // (leading actions on the left, primary Save on the right).
            footer.insertBefore(buildFooterButton(), footer.firstChild);
            if (window.console && window.console.info) {
                window.console.info('[Souvera Mail] sieve-apply popup-footer button injected');
            }
        });
    };

    // ---------------------------------------------------------------
    // Dropdown-menu injection (v0.14.39, kept as second entry point —
    // proven pattern from dropdown-menu.js).
    // ---------------------------------------------------------------
    const closeDropdown = () => {
        const toggle = document.getElementById('top-system-dropdown-id');
        if (toggle && toggle.getAttribute('aria-expanded') === 'true') {
            try { toggle.click(); } catch (_e) { /* silent */ }
        }
    };

    const buildMenuItem = () => {
        const li = document.createElement('li');
        li.setAttribute('role', 'presentation');
        li.setAttribute('data-' + MARKER, '1');
        const a = document.createElement('a');
        a.setAttribute('href', '#');
        a.setAttribute('tabindex', '-1');
        a.setAttribute('data-icon', '🔎');
        a.setAttribute('data-testid', 'sieve-apply-toolbar-btn-menu');
        a.textContent = 'Filter auf Ordner anwenden…';
        a.addEventListener('click', ev => {
            ev.preventDefault();
            closeDropdown();
            openModal();
        });
        li.appendChild(a);
        return li;
    };

    const injectMenuInto = menu => {
        if (!menu || menu.querySelector('[data-' + MARKER + ']')) { return; }
        const li = buildMenuItem();
        const migAnchor = menu.querySelector(MIG_ANCHOR_SEL);
        const helpLink = menu.querySelector(HELP_SEL);
        const helpLi = helpLink ? helpLink.closest('li') : null;
        if (migAnchor && migAnchor.nextSibling) {
            menu.insertBefore(li, migAnchor.nextSibling);
        } else if (helpLi && helpLi.parentNode === menu) {
            menu.insertBefore(li, helpLi);
        } else {
            menu.appendChild(li);
        }
    };

    // ---------------------------------------------------------------
    // Unified scan — runs once on load AND on every DOM mutation.
    // Cheap enough (querySelector short-circuits) to be idempotent-safe.
    // ---------------------------------------------------------------
    const scan = () => {
        injectPopupFooter();
        document.querySelectorAll(MENU_SEL).forEach(injectMenuInto);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan, { once: true });
    } else {
        scan();
    }

    const observer = new MutationObserver(() => scan());
    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    } else {
        // <body> not there yet — retry when DOM is ready.
        document.addEventListener('DOMContentLoaded', () => {
            observer.observe(document.body, { childList: true, subtree: true });
            scan();
        }, { once: true });
    }
})();
