/* global rl */

/*
 * Souvera Mail — "Abwesenheitsnotiz" (out-of-office / vacation auto-reply).
 * ------------------------------------------------------------------------
 * A simple, user-friendly form reachable from the top-right system
 * dropdown ("🌴 Abwesenheitsnotiz"), right next to "🔎 Filter auf Ordner
 * anwenden…". No detour through the Filters editor.
 *
 * The form GETs its current state and POSTs the new state to the same
 * endpoint (SmailVacationUrl, method-dispatched by VacationController).
 * The backend merges a managed `vacation` block into the user's active
 * Sieve script so existing filters keep working.
 *
 * Injection pattern (menu item + self-contained modal) is copied verbatim
 * from js/sieve-apply.js — a proven approach across screen sizes and
 * popup states.
 */
(function () {
    'use strict';

    if (!window.rl) { return; }

    const cfg = rl.settings && rl.settings.get && rl.settings.get('Nextcloud');
    if (!cfg || !cfg.SmailVacationUrl) {
        return;
    }

    const VACATION_URL = cfg.SmailVacationUrl;
    const MARKER = 'sv-vacation-menu';
    const MENU_SEL = 'menu[aria-labelledby="top-system-dropdown-id"]';
    const SIEVE_ANCHOR_SEL = '[data-testid="sieve-apply-toolbar-btn-menu"]';
    const HELP_SEL = 'a[data-i18n="GLOBAL/HELP"]';

    const i18n = (key, fallback) => {
        try {
            if (rl && typeof rl.i18n === 'function') {
                const v = rl.i18n(key);
                if (v && v !== key) { return v; }
            }
        } catch (e) { /* silent */ }
        return fallback;
    };

    const csrfToken = () =>
        (document.head && document.head.dataset && document.head.dataset.requesttoken)
        || (window.OC && window.OC.requestToken)
        || '';

    const safeJson = r => r.text().then(txt => {
        try { return JSON.parse(txt); }
        catch (_) {
            throw new Error(i18n('VACATION/NON_JSON', 'Server did not return JSON (HTTP ') + r.status + ')');
        }
    });

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

    const inputStyle = [
        'width:100%',
        'box-sizing:border-box',
        'padding:8px 10px',
        'border:1px solid var(--color-border,#d5d5d5)',
        'border-radius:var(--border-radius,8px)',
        'background:var(--color-main-background,#fff)',
        'color:var(--color-main-text,#222)',
        'font-size:14px',
        'margin-bottom:16px'
    ].join(';');

    const labelStyle = 'display:block;font-size:13px;font-weight:600;margin-bottom:6px;';

    let modalOpen = false;
    const openModal = () => {
        if (modalOpen) { return; }
        modalOpen = true;

        const overlay = el('div', {
            className: 'souvera-vacation-overlay',
            'data-testid': 'vacation-modal',
            style: [
                'position:fixed', 'inset:0',
                'background:rgba(0,0,0,0.45)',
                'z-index:100000',
                'display:flex', 'align-items:center', 'justify-content:center'
            ].join(';')
        });

        const body = el('div', {
            className: 'souvera-vacation-body',
            style: [
                'background:var(--color-main-background,#fff)',
                'color:var(--color-main-text,#222)',
                'border-radius:var(--border-radius,8px)',
                'padding:24px 28px',
                'width:min(520px, 92vw)',
                'max-height:90vh', 'overflow-y:auto',
                'box-shadow:0 10px 30px rgba(0,0,0,0.25)',
                'font-family:var(--font-face, system-ui)'
            ].join(';')
        });

        const title = el('h3', { style: 'margin:0 0 8px 0;font-size:18px;font-weight:600;' },
            [i18n('VACATION/TITLE', 'Abwesenheitsnotiz')]);
        const explain = el('p', {
            style: 'margin:0 0 20px 0;font-size:13px;line-height:1.5;color:var(--color-text-maxcontrast,#666);'
        }, [i18n('VACATION/EXPLAIN', 'Sende automatisch eine Antwort auf eingehende E-Mails, während du abwesend bist. Jeder Absender erhält die Antwort höchstens einmal pro Tag.')]);

        // Enabled toggle
        const enabledCb = el('input', { type: 'checkbox', 'data-testid': 'vacation-enabled', style: 'margin-right:8px;transform:scale(1.2);' });
        const enabledLabel = el('label', { style: 'display:flex;align-items:center;font-size:14px;font-weight:600;margin-bottom:18px;cursor:pointer;' },
            [enabledCb, i18n('VACATION/ENABLED', 'Abwesenheitsnotiz aktivieren')]);

        // Subject
        const subjectLabel = el('label', { style: labelStyle }, [i18n('VACATION/SUBJECT', 'Betreff')]);
        const subjectInput = el('input', { type: 'text', 'data-testid': 'vacation-subject', maxlength: '255', style: inputStyle });
        subjectInput.placeholder = i18n('VACATION/SUBJECT_PH', 'Abwesenheitsnotiz');

        // Message
        const messageLabel = el('label', { style: labelStyle }, [i18n('VACATION/MESSAGE', 'Nachricht')]);
        const messageInput = el('textarea', { 'data-testid': 'vacation-message', rows: '5', style: inputStyle });
        messageInput.placeholder = i18n('VACATION/MESSAGE_PH', 'Ich bin derzeit nicht erreichbar und antworte nach meiner Rückkehr.');

        // Optional date range
        const dateRow = el('div', { style: 'display:flex;gap:14px;' });
        const fromWrap = el('div', { style: 'flex:1;' });
        const fromLabel = el('label', { style: labelStyle }, [i18n('VACATION/FROM', 'Von (optional)')]);
        const fromInput = el('input', { type: 'date', 'data-testid': 'vacation-from', style: inputStyle });
        fromWrap.appendChild(fromLabel); fromWrap.appendChild(fromInput);
        const toWrap = el('div', { style: 'flex:1;' });
        const toLabel = el('label', { style: labelStyle }, [i18n('VACATION/TO', 'Bis (optional)')]);
        const toInput = el('input', { type: 'date', 'data-testid': 'vacation-to', style: inputStyle });
        toWrap.appendChild(toLabel); toWrap.appendChild(toInput);
        dateRow.appendChild(fromWrap); dateRow.appendChild(toWrap);

        const status = el('div', {
            'data-testid': 'vacation-status',
            style: 'font-size:13px;min-height:1.4em;margin-bottom:16px;color:var(--color-text-maxcontrast,#666);'
        }, ['']);

        const cancelBtn = el('button', { type: 'button', 'data-testid': 'vacation-cancel', className: 'button', style: 'margin-right:8px;' },
            [i18n('VACATION/CANCEL', 'Abbrechen')]);
        const saveBtn = el('button', {
            type: 'button', 'data-testid': 'vacation-save', className: 'button primary',
            style: [
                'background:var(--color-primary-element,#0693e3)',
                'color:var(--color-primary-element-text,#fff)',
                'border:0', 'padding:8px 16px',
                'border-radius:var(--border-radius,8px)',
                'font-weight:600', 'cursor:pointer'
            ].join(';')
        }, [i18n('VACATION/SAVE', 'Speichern')]);
        saveBtn.disabled = true;

        const footer = el('div', { style: 'display:flex;justify-content:flex-end;align-items:center;' }, [cancelBtn, saveBtn]);

        [title, explain, enabledLabel, subjectLabel, subjectInput, messageLabel, messageInput, dateRow, status, footer]
            .forEach(n => body.appendChild(n));
        overlay.appendChild(body);
        document.body.appendChild(overlay);

        const closeModal = () => {
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            modalOpen = false;
        };
        cancelBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', ev => { if (ev.target === overlay) { closeModal(); } });

        // Load current state.
        fetch(VACATION_URL, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', requesttoken: csrfToken() }
        }).then(safeJson).then(data => {
            if (!data || data.status !== 'ok') {
                status.textContent = (data && data.message)
                    ? i18n('VACATION/LOAD_PREFIX', 'Status: ') + data.message
                    : i18n('VACATION/LOAD_FAIL', 'Der aktuelle Status konnte nicht geladen werden.');
                return;
            }
            if (data.available === false) {
                status.textContent = i18n('VACATION/UNAVAILABLE', 'Serverseitige Filter (Sieve) sind nicht verfügbar — Abwesenheitsnotiz nicht möglich.');
                return;
            }
            const v = data.vacation || {};
            enabledCb.checked = !!v.enabled;
            subjectInput.value = v.subject || '';
            messageInput.value = v.message || '';
            fromInput.value = v.from || '';
            toInput.value = v.to || '';
            saveBtn.disabled = false;
        }).catch(err => {
            status.textContent = i18n('VACATION/NET_ERROR', 'Netzwerkfehler: ') + err;
        });

        saveBtn.addEventListener('click', () => {
            if (saveBtn.disabled) { return; }
            const enabled = enabledCb.checked;
            const message = messageInput.value.trim();
            if (enabled && message === '') {
                status.textContent = i18n('VACATION/NEED_MESSAGE', 'Bitte gib eine Nachricht ein.');
                messageInput.focus();
                return;
            }
            if (fromInput.value && toInput.value && fromInput.value > toInput.value) {
                status.textContent = i18n('VACATION/DATE_ORDER', 'Das Startdatum darf nicht nach dem Enddatum liegen.');
                return;
            }

            saveBtn.disabled = true;
            cancelBtn.disabled = true;
            status.textContent = i18n('VACATION/SAVING', 'Speichern …');

            fetch(VACATION_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    requesttoken: csrfToken()
                },
                body: JSON.stringify({
                    enabled: enabled,
                    subject: subjectInput.value.trim(),
                    message: message,
                    from: fromInput.value || '',
                    to: toInput.value || ''
                })
            }).then(safeJson).then(data => {
                if (!data || data.status !== 'ok') {
                    status.textContent = i18n('VACATION/ERROR_PREFIX', 'Fehler: ') + ((data && data.message) || i18n('VACATION/UNKNOWN', 'unbekannt'));
                    saveBtn.disabled = false;
                    cancelBtn.disabled = false;
                    return;
                }
                status.innerHTML = '<span style="color:var(--color-success,#3a7);">'
                    + (enabled
                        ? i18n('VACATION/DONE_ON', '✓ Abwesenheitsnotiz aktiviert.')
                        : i18n('VACATION/DONE_OFF', '✓ Abwesenheitsnotiz deaktiviert.'))
                    + '</span>';
                cancelBtn.textContent = i18n('VACATION/CLOSE', 'Schließen');
                cancelBtn.disabled = false;
            }).catch(err => {
                status.textContent = i18n('VACATION/NET_ERROR', 'Netzwerkfehler: ') + err;
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
            });
        });
    };

    window.addEventListener('souvera-mail:open-vacation', openModal);

    // ---------------------------------------------------------------
    // Dropdown-menu injection.
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
        a.setAttribute('data-icon', '🌴');
        a.setAttribute('data-testid', 'vacation-menu-btn');
        a.textContent = i18n('MENU/VACATION', 'Abwesenheitsnotiz');
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
        // Place right after the "Filter auf Ordner anwenden…" entry when
        // present, else before Help, else append.
        const sieveAnchor = menu.querySelector(SIEVE_ANCHOR_SEL);
        const sieveLi = sieveAnchor ? sieveAnchor.closest('li') : null;
        const helpLink = menu.querySelector(HELP_SEL);
        const helpLi = helpLink ? helpLink.closest('li') : null;
        if (sieveLi && sieveLi.parentNode === menu && sieveLi.nextSibling) {
            menu.insertBefore(li, sieveLi.nextSibling);
        } else if (helpLi && helpLi.parentNode === menu) {
            menu.insertBefore(li, helpLi);
        } else {
            menu.appendChild(li);
        }
    };

    const scan = () => {
        document.querySelectorAll(MENU_SEL).forEach(injectMenuInto);
    };

    let scanScheduled = false;
    const requestScan = () => {
        if (scanScheduled) { return; }
        scanScheduled = true;
        (window.requestAnimationFrame || window.setTimeout)(() => {
            scanScheduled = false;
            try { scan(); } catch (_e) { /* silent — never block engine */ }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan, { once: true });
    } else {
        scan();
    }

    const observer = new MutationObserver(() => requestScan());
    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    } else {
        document.addEventListener('DOMContentLoaded', () => {
            observer.observe(document.body, { childList: true, subtree: true });
            scan();
        }, { once: true });
    }
})();
