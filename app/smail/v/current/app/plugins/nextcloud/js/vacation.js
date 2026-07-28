/* global rl */

/*
 * Souvera Mail — "Abwesenheitsnotiz" injected into the webmail's
 * Settings page (#/settings/souvera-account) as a section card,
 * right alongside Identities, App Passwords, and Connected Devices.
 * No dropdown menu item needed.
 */
(function () {
    'use strict';

    if (!window.rl) { return; }

    const cfg = rl.settings && rl.settings.get && rl.settings.get('Nextcloud');
    if (!cfg || !cfg.SmailVacationUrl) {
        return;
    }

    const VACATION_URL = cfg.SmailVacationUrl;
    const SETTINGS_SECTION_SEL = '.b-admin-settings-content, .b-settings-content, [data-settings-content]';
    const VACATION_MARKER = 'data-sv-vacation-section';

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
        catch (_) { throw new Error('Server returned non-JSON (HTTP ' + r.status + ')'); }
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
        'width:100%','box-sizing:border-box','padding:8px 10px',
        'border:1px solid var(--color-border,#d5d5d5)',
        'border-radius:var(--border-radius,8px)',
        'background:var(--color-main-background,#fff)',
        'color:var(--color-main-text,#222)','font-size:14px','margin-bottom:14px'
    ].join(';');

    const labelStyle = 'display:block;font-size:13px;font-weight:600;margin-bottom:4px;';

    const buildSection = () => {
        const wrapper = el('div', { 'data-sv-vacation-section': '1', style: 'margin-top:24px;' });
        const sectionTitle = el('h4', {
            style: 'font-size:15px;font-weight:600;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--color-border,#ddd);'
        }, ['🌴 ' + i18n('VACATION/TITLE', 'Abwesenheitsnotiz')]);

        const desc = el('p', {
            style: 'font-size:12px;line-height:1.5;color:var(--color-text-maxcontrast,#888);margin-bottom:16px;'
        }, [i18n('VACATION/EXPLAIN', 'Sende automatisch eine Antwort, während du abwesend bist. Jeder Absender erhält sie höchstens 1× pro Tag.')]);

        const enabledCb = el('input', { type: 'checkbox', 'data-testid': 'vacation-enabled', style: 'margin-right:8px;' });
        const enabledWrap = el('label', {
            style: 'display:flex;align-items:center;font-size:14px;font-weight:600;margin-bottom:16px;cursor:pointer;'
        }, [enabledCb, i18n('VACATION/ENABLED', 'Automatische Antwort aktivieren')]);

        const subjectLbl = el('label', { style: labelStyle }, [i18n('VACATION/SUBJECT', 'Betreff')]);
        const subjectInp = el('input', { type: 'text', 'data-testid': 'vacation-subject', maxlength: '255', placeholder: 'Abwesenheitsnotiz', style: inputStyle });

        const msgLbl = el('label', { style: labelStyle }, [i18n('VACATION/MESSAGE', 'Nachricht')]);
        const msgInp = el('textarea', { 'data-testid': 'vacation-message', rows: '4', style: inputStyle, placeholder: 'Ich bin derzeit nicht erreichbar…' });

        const dateRow = el('div', { style: 'display:flex;gap:14px;' });
        const fromWrap = el('div', { style: 'flex:1;' });
        fromWrap.appendChild(el('label', { style: labelStyle }, [i18n('VACATION/FROM', 'Von (opt.)')]));
        const fromInp = el('input', { type: 'date', 'data-testid': 'vacation-from', style: inputStyle });
        fromWrap.appendChild(fromInp);
        const toWrap = el('div', { style: 'flex:1;' });
        toWrap.appendChild(el('label', { style: labelStyle }, [i18n('VACATION/TO', 'Bis (opt.)')]));
        const toInp = el('input', { type: 'date', 'data-testid': 'vacation-to', style: inputStyle });
        toWrap.appendChild(toInp);
        dateRow.appendChild(fromWrap); dateRow.appendChild(toWrap);

        const statusDiv = el('div', {
            'data-testid': 'vacation-status',
            style: 'font-size:13px;min-height:1.4em;margin-top:8px;color:var(--color-text-maxcontrast,#666);'
        }, ['']);

        const saveBtn = el('button', {
            type: 'button', 'data-testid': 'vacation-save',
            style: 'background:var(--color-primary-element,#0693e3);color:var(--color-primary-element-text,#fff);border:0;padding:9px 18px;border-radius:var(--border-radius,8px);font-weight:600;cursor:pointer;margin-top:8px;font-size:14px;'
        }, [i18n('VACATION/SAVE', 'Speichern')]);
        saveBtn.disabled = true;

        [sectionTitle, desc, enabledWrap, subjectLbl, subjectInp, msgLbl, msgInp, dateRow, statusDiv, saveBtn]
            .forEach(n => wrapper.appendChild(n));

        // Load state
        fetch(VACATION_URL, {
            method: 'GET', credentials: 'same-origin',
            headers: { Accept: 'application/json', requesttoken: csrfToken() }
        }).then(safeJson).then(data => {
            if (!data || data.status !== 'ok' || data.available === false) {
                wrapper.style.display = 'none';
                return;
            }
            const v = data.vacation || {};
            enabledCb.checked = !!v.enabled;
            subjectInp.value = v.subject || '';
            msgInp.value = v.message || '';
            fromInp.value = v.from || '';
            toInp.value = v.to || '';
            saveBtn.disabled = false;
        }).catch(() => { /* settings will retry */ });

        saveBtn.addEventListener('click', () => {
            if (saveBtn.disabled) { return; }
            const enabled = enabledCb.checked;
            const message = msgInp.value.trim();
            if (enabled && message === '') {
                statusDiv.textContent = i18n('VACATION/NEED_MESSAGE', 'Bitte gib eine Nachricht ein.');
                msgInp.focus(); return;
            }
            if (fromInp.value && toInp.value && fromInp.value > toInp.value) {
                statusDiv.textContent = i18n('VACATION/DATE_ORDER', 'Startdatum darf nicht nach dem Enddatum liegen.');
                return;
            }
            saveBtn.disabled = true;
            statusDiv.textContent = i18n('VACATION/SAVING', 'Speichern …');
            fetch(VACATION_URL, {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json', Accept: 'application/json',
                    requesttoken: csrfToken()
                },
                body: JSON.stringify({
                    enabled: enabled,
                    subject: subjectInp.value.trim(),
                    message: message,
                    from: fromInp.value || '',
                    to: toInp.value || ''
                })
            }).then(safeJson).then(data => {
                if (!data || data.status !== 'ok') {
                    statusDiv.innerHTML = '<span style="color:var(--color-error,#c22);">' +
                        i18n('VACATION/ERROR_PREFIX', 'Fehler: ') + ((data && data.message) || 'unbekannt') + '</span>';
                    saveBtn.disabled = false;
                    return;
                }
                statusDiv.innerHTML = '<span style="color:var(--color-success,#3a7);">' +
                    (enabled ? i18n('VACATION/DONE_ON', '✓ Abwesenheitsnotiz aktiviert.')
                             : i18n('VACATION/DONE_OFF', '✓ Abwesenheitsnotiz deaktiviert.')) + '</span>';
                saveBtn.disabled = false;
            }).catch(err => {
                statusDiv.textContent = i18n('VACATION/NET_ERROR', 'Netzwerkfehler: ') + err;
                saveBtn.disabled = false;
            });
        });

        return wrapper;
    };

    const injectInto = container => {
        if (container.querySelector('[' + VACATION_MARKER + ']')) { return; }
        const section = buildSection();
        container.appendChild(section);
    };

    const scan = () => {
        document.querySelectorAll(SETTINGS_SECTION_SEL).forEach(injectInto);
    };

    let scanScheduled = false;
    const requestScan = () => {
        if (scanScheduled) { return; }
        scanScheduled = true;
        (window.requestAnimationFrame || window.setTimeout)(() => {
            scanScheduled = false;
            try { scan(); } catch (_e) { /* silent */ }
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