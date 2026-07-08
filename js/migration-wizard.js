/**
 * Souvera Mail v0.14.10 — "Alte Mails importieren" wizard.
 *
 * Loaded via OCP\Util::addScript('souvera_mail', 'migration-wizard') from
 * PageController::index(). The wizard is a plain vanilla-JS overlay
 * layered ON TOP of Snappymail's own DOM — deliberately NOT a Snappymail
 * KO-Popup View, because:
 *   - the bundled Snappymail engine can be refreshed independently and
 *     hooking into its KO wiring would break on every upgrade,
 *   - our overlay stays alive across Snappymail state transitions,
 *   - a plain overlay is smaller (~15 KB min), faster to load and
 *     trivially removable.
 *
 * Backend contract: 7 REST endpoints at /apps/souvera_mail/migration/
 * (see MigrationController.php). This file speaks JSON, respects the
 * NC requesttoken CSRF header on POSTs and never touches sensitive
 * credentials beyond the fetch call itself.
 *
 * State machine (single active `wizardState` string):
 *   HIDDEN        — nothing on screen
 *   WELCOME       — welcome splash with "Import starten" / "Später"
 *   FORM          — old-provider IMAP creds form
 *   TESTING       — pre-flight test-connection running
 *   CONFIRM       — "12 folders, 8'432 messages, KEIN Cancel möglich"
 *   STARTING      — POST /migration/start running
 *   PROGRESS      — active migration, polled every 5s
 *   DONE_OK       — completed splash, auto-closes after 5s
 *   DONE_FAIL     — failed splash, stays until user clicks OK
 *
 * The floating pill in the bottom-right corner is ALWAYS visible when
 * Snappymail's main UI is up. It re-opens the wizard on click, and
 * pulses when there's a running migration.
 */
(function () {
    'use strict';

    // ─── Config ────────────────────────────────────────────────
    const API_BASE = (typeof OC !== 'undefined' && OC.generateUrl)
        ? OC.generateUrl('/apps/souvera_mail/migration')
        : '/apps/souvera_mail/migration';
    const POLL_INTERVAL_MS = 5000;
    const AUTO_CLOSE_SUCCESS_MS = 8000;

    // ─── State ─────────────────────────────────────────────────
    let wizardState = 'HIDDEN';
    let pollTimer = null;
    let currentJob = null;
    let welcomeDismissedRemote = false;

    /**
     * Form field values kept OUT of the DOM element cache. Password is
     * cleared after every POST that hands it off to provider.tools.
     */
    const form = { host: '', port: 993, user: '', password: '', secure: true };

    /** DOM roots — created lazily on first show. */
    let overlayEl = null;
    let pillEl = null;

    // ─── Bootstrap ─────────────────────────────────────────────
    function boot() {
        if (document.getElementById('smail-migration-overlay')) return;
        ensurePillMounted();
        fetchWelcomeState()
            .then((state) => {
                if (!state || !state.available) return;
                welcomeDismissedRemote = !!state.welcomeDismissed;
                if (state.activeJob) {
                    currentJob = state.activeJob;
                    showProgress();
                    return;
                }
                if (state.lastJob && state.lastJob.status && !state.lastJob.isTerminal) {
                    currentJob = state.lastJob;
                    showProgress();
                    return;
                }
                if (!welcomeDismissedRemote) {
                    showWelcome();
                }
            })
            .catch((err) => {
                console.debug('[souvera-mail migration] welcome-state check failed:', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(boot, 900));
    } else {
        setTimeout(boot, 900);
    }

    // ─── HTTP helpers ──────────────────────────────────────────
    function requestToken() {
        return (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : '';
    }

    async function api(path, method, body) {
        const opts = {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        };
        const token = requestToken();
        if (token) opts.headers.requesttoken = token;
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        const resp = await fetch(API_BASE + path, opts);
        let data = null;
        try { data = await resp.json(); } catch (e) { data = null; }
        if (!resp.ok) {
            const msg = (data && (data.message || data.error)) || ('HTTP ' + resp.status);
            const err = new Error(msg);
            err.status = resp.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    async function fetchWelcomeState() {
        const r = await api('/welcome-state', 'GET');
        return r && r.state;
    }

    async function apiDismissWelcome() {
        try { await api('/dismiss-welcome', 'POST'); } catch (e) { /* non-fatal */ }
        welcomeDismissedRemote = true;
    }

    async function apiDismissJob(jobId) {
        try { await api('/dismiss/' + encodeURIComponent(jobId), 'POST'); } catch (e) {}
    }

    // ─── DOM helpers ───────────────────────────────────────────
    function h(tag, attrs, ...children) {
        const el = document.createElement(tag);
        if (attrs) {
            for (const [k, v] of Object.entries(attrs)) {
                if (k === 'class') el.className = v;
                else if (k === 'html') el.innerHTML = v;
                else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
                else if (v !== false && v !== null && v !== undefined) el.setAttribute(k, v === true ? '' : String(v));
            }
        }
        for (const c of children) {
            if (c == null || c === false) continue;
            if (typeof c === 'string') el.appendChild(document.createTextNode(c));
            else el.appendChild(c);
        }
        return el;
    }

    function ensureOverlayMounted() {
        if (overlayEl) return overlayEl;
        overlayEl = h('div', { id: 'smail-migration-overlay', 'aria-hidden': 'true', role: 'dialog' });
        document.body.appendChild(overlayEl);
        return overlayEl;
    }

    function ensurePillMounted() {
        if (pillEl) return pillEl;
        pillEl = h('button', {
            id: 'smail-migration-pill',
            type: 'button',
            title: 'Alte Mails importieren',
            'data-state': 'idle',
            onclick: () => onPillClick(),
        }, h('span', { class: 'smail-pill-icon', html: '&#10148;' }), h('span', { class: 'smail-pill-text' }, 'Alte Mails importieren'));
        document.body.appendChild(pillEl);
        return pillEl;
    }

    function updatePill() {
        if (!pillEl) return;
        const label = pillEl.querySelector('.smail-pill-text');
        if (currentJob && currentJob.isActive) {
            pillEl.setAttribute('data-state', 'running');
            if (label) label.textContent = 'Import läuft…';
        } else if (currentJob && currentJob.status === 'completed') {
            pillEl.setAttribute('data-state', 'done');
            if (label) label.textContent = 'Import fertig';
        } else if (currentJob && currentJob.status === 'failed') {
            pillEl.setAttribute('data-state', 'fail');
            if (label) label.textContent = 'Import fehlgeschlagen';
        } else {
            pillEl.setAttribute('data-state', 'idle');
            if (label) label.textContent = 'Alte Mails importieren';
        }
    }

    async function onPillClick() {
        // Fetch fresh state so the pill is a true reopen (not just a
        // cached-view resurrection).
        try {
            const state = await fetchWelcomeState();
            if (!state || !state.available) {
                alert('Import-Dienst ist auf dieser Instanz nicht aktiviert. Bitte den Administrator kontaktieren.');
                return;
            }
            if (state.activeJob) { currentJob = state.activeJob; showProgress(); return; }
            if (state.lastJob && state.lastJob.status && !state.lastJob.isTerminal) { currentJob = state.lastJob; showProgress(); return; }
            showForm(); // Always take the direct route to the form on re-open.
        } catch (e) {
            alert('Import-Dienst nicht erreichbar: ' + (e.message || e));
        }
    }

    function render(inner, state) {
        wizardState = state;
        ensureOverlayMounted();
        overlayEl.setAttribute('aria-hidden', 'false');
        overlayEl.innerHTML = '';
        const backdrop = h('div', { class: 'smail-mig-backdrop', onclick: () => { if (canDismissByBackdrop()) hide(); } });
        const modal = h('div', { class: 'smail-mig-modal', role: 'document' });
        modal.appendChild(inner);
        overlayEl.appendChild(backdrop);
        overlayEl.appendChild(modal);
    }

    function canDismissByBackdrop() {
        return ['WELCOME', 'FORM', 'CONFIRM', 'DONE_OK', 'DONE_FAIL'].includes(wizardState);
    }

    function hide() {
        wizardState = 'HIDDEN';
        if (overlayEl) {
            overlayEl.setAttribute('aria-hidden', 'true');
            overlayEl.innerHTML = '';
        }
        stopPolling();
        updatePill();
    }

    // ─── Screens ───────────────────────────────────────────────
    function showWelcome() {
        const body = h('div', { class: 'smail-mig-body' },
            h('div', { class: 'smail-mig-icon-lg', html: '&#128231;' }),
            h('h2', {}, 'Alte Mails übernehmen?'),
            h('p', { class: 'smail-mig-lead' },
                'Willkommen bei Souvera Mail. Wir können deine E-Mails vom bisherigen ',
                'Anbieter automatisch in dein neues Postfach kopieren — kein manuelles ',
                'Umziehen, kein Datenverlust.'),
            h('ul', { class: 'smail-mig-usp' },
                h('li', {}, 'Kein Passwort für dein neues Postfach nötig — wir erzeugen automatisch ein Einmal-Passwort'),
                h('li', {}, 'Der Import läuft im Hintergrund, du kannst weiterarbeiten'),
                h('li', {}, 'Fortschritt siehst du oben — der Button unten rechts bleibt auch nach dem Schließen erreichbar'),
            ),
        );
        const footer = h('div', { class: 'smail-mig-footer' },
            h('button', { type: 'button', class: 'smail-btn smail-btn-secondary', onclick: () => onWelcomeDismiss(true) }, 'Nicht mehr zeigen'),
            h('button', { type: 'button', class: 'smail-btn smail-btn-primary', onclick: showForm }, 'Import starten'),
        );
        const inner = h('div', {}, closeBtn(), body, footer);
        render(inner, 'WELCOME');
    }

    async function onWelcomeDismiss(hard) {
        if (hard) await apiDismissWelcome();
        hide();
    }

    function showForm() {
        const body = h('div', { class: 'smail-mig-body' },
            h('h2', {}, 'Zugangsdaten deines alten Postfachs'),
            h('p', { class: 'smail-mig-lead' },
                'Gib die IMAP-Zugangsdaten deines bisherigen Anbieters ein. ',
                'Wir prüfen die Verbindung im nächsten Schritt.'),

            fieldRow('IMAP-Server', h('input', { type: 'text', id: 'smail-mig-host', placeholder: 'imap.example.com', value: form.host, autocomplete: 'off', spellcheck: 'false' })),
            fieldRow('Port', h('input', { type: 'number', id: 'smail-mig-port', min: '1', max: '65535', value: String(form.port), autocomplete: 'off' })),
            fieldRow('Benutzername / E-Mail', h('input', { type: 'text', id: 'smail-mig-user', placeholder: 'max@example.com', value: form.user, autocomplete: 'off', spellcheck: 'false' })),
            fieldRow('Passwort', h('input', { type: 'password', id: 'smail-mig-password', placeholder: '••••••••', value: form.password, autocomplete: 'new-password' })),
            fieldRow('', h('label', { class: 'smail-mig-inline' },
                h('input', { type: 'checkbox', id: 'smail-mig-secure', checked: form.secure ? true : false }),
                h('span', {}, ' Verschlüsselte Verbindung (empfohlen, Port 993)'))),

            h('div', { id: 'smail-mig-form-err', class: 'smail-mig-err', hidden: true }),
        );
        const footer = h('div', { class: 'smail-mig-footer' },
            h('button', { type: 'button', class: 'smail-btn smail-btn-secondary', onclick: hide }, 'Abbrechen'),
            h('button', { type: 'button', class: 'smail-btn smail-btn-primary', onclick: onFormSubmit, id: 'smail-mig-form-submit' }, 'Verbindung prüfen'),
        );
        const inner = h('div', {}, closeBtn(), body, footer);
        render(inner, 'FORM');
        setTimeout(() => { const el = document.getElementById('smail-mig-host'); if (el) el.focus(); }, 50);
    }

    function fieldRow(label, input) {
        return h('div', { class: 'smail-mig-field' },
            label ? h('label', { class: 'smail-mig-field-label' }, label) : null,
            input,
        );
    }

    function readForm() {
        form.host = (document.getElementById('smail-mig-host').value || '').trim();
        form.port = parseInt(document.getElementById('smail-mig-port').value, 10) || 993;
        form.user = (document.getElementById('smail-mig-user').value || '').trim();
        form.password = document.getElementById('smail-mig-password').value || '';
        form.secure = !!document.getElementById('smail-mig-secure').checked;
    }

    function showFormError(msg) {
        const el = document.getElementById('smail-mig-form-err');
        if (!el) return;
        if (msg) { el.textContent = msg; el.hidden = false; }
        else { el.textContent = ''; el.hidden = true; }
    }

    function setBusy(btnId, busy, label) {
        const b = document.getElementById(btnId);
        if (!b) return;
        b.disabled = !!busy;
        if (label !== undefined) b.textContent = label;
    }

    async function onFormSubmit() {
        readForm();
        showFormError('');
        if (!form.host) { showFormError('Bitte IMAP-Server angeben.'); return; }
        if (!form.user) { showFormError('Bitte Benutzername angeben.'); return; }
        if (!form.password) { showFormError('Bitte Passwort angeben.'); return; }
        setBusy('smail-mig-form-submit', true, 'Prüfe…');
        try {
            const [testRes, listRes] = await Promise.all([
                api('/test-connection', 'POST', form),
                api('/list-folders', 'POST', form).catch(() => null),
            ]);
            const test = testRes && testRes.result;
            if (!test || !test.success) {
                showFormError('Verbindung fehlgeschlagen: ' + (test && test.message ? test.message : 'unbekannter Fehler'));
                setBusy('smail-mig-form-submit', false, 'Verbindung prüfen');
                return;
            }
            const folderInventory = listRes && listRes.result;
            showConfirm(folderInventory);
        } catch (err) {
            showFormError('Fehler: ' + (err.message || err));
            setBusy('smail-mig-form-submit', false, 'Verbindung prüfen');
        }
    }

    function showConfirm(inventory) {
        const totalMsg = inventory && inventory.totalMessages ? inventory.totalMessages : 0;
        const totalFld = inventory && inventory.totalFolders ? inventory.totalFolders : 0;
        const body = h('div', { class: 'smail-mig-body' },
            h('div', { class: 'smail-mig-icon-lg', html: '&#9989;' }),
            h('h2', {}, 'Verbindung ok — bereit zum Import'),
            h('div', { class: 'smail-mig-summary' },
                h('div', {}, h('strong', {}, form.host + ':' + form.port), ' als ', h('strong', {}, form.user)),
                inventory && inventory.success
                    ? h('div', { class: 'smail-mig-summary-count' },
                        totalFld + ' Ordner · ' + totalMsg.toLocaleString('de-DE') + ' Nachrichten')
                    : h('div', { class: 'smail-mig-summary-count' }, 'Ordner-Vorschau nicht verfügbar (kein Blocker)'),
            ),
            h('div', { class: 'smail-mig-warn' },
                h('strong', {}, 'Wichtig: '),
                'Ein gestarteter Import kann nicht mehr abgebrochen werden. ',
                'Du kannst dein neues Postfach aber während des Imports normal nutzen.'),
            h('div', { id: 'smail-mig-confirm-err', class: 'smail-mig-err', hidden: true }),
        );
        const footer = h('div', { class: 'smail-mig-footer' },
            h('button', { type: 'button', class: 'smail-btn smail-btn-secondary', onclick: showForm }, 'Zurück'),
            h('button', { type: 'button', class: 'smail-btn smail-btn-primary', id: 'smail-mig-start-btn', onclick: onStartMigration }, 'Import starten'),
        );
        const inner = h('div', {}, closeBtn(), body, footer);
        render(inner, 'CONFIRM');
    }

    async function onStartMigration() {
        setBusy('smail-mig-start-btn', true, 'Starte…');
        try {
            const r = await api('/start', 'POST', form);
            // Wipe the password from memory the moment the API has taken it.
            form.password = '';
            currentJob = r && r.job;
            showProgress();
        } catch (err) {
            const el = document.getElementById('smail-mig-confirm-err');
            if (el) { el.textContent = 'Import konnte nicht gestartet werden: ' + (err.message || err); el.hidden = false; }
            setBusy('smail-mig-start-btn', false, 'Import starten');
        }
    }

    function showProgress() {
        renderProgress();
        wizardState = 'PROGRESS';
        startPolling();
        updatePill();
    }

    function renderProgress() {
        const j = currentJob || {};
        const p = (j.progress && j.progress.progress) || (j.progress || {});
        const messagesTotal = Number(p.messagesTotal || 0);
        const messagesDone = Number(p.messagesDone || 0);
        const foldersTotal = Number(p.foldersTotal || 0);
        const foldersDone = Number(p.foldersDone || 0);
        const percent = messagesTotal > 0
            ? Math.min(100, Math.round((messagesDone / messagesTotal) * 100))
            : (j.status === 'running' ? 5 : 0);
        const queue = (j.progress && j.progress.queue) || null;
        const isPending = j.status === 'pending';

        const body = h('div', { class: 'smail-mig-body' },
            h('h2', {}, isPending ? 'In der Warteschlange' : 'Import läuft'),
            h('div', { class: 'smail-mig-summary' },
                h('div', {}, 'Von ', h('strong', {}, j.sourceHost || '—'),
                    ' als ', h('strong', {}, j.sourceUser || '—')),
            ),
            isPending && queue
                ? h('div', { class: 'smail-mig-queue' },
                    'Position ', h('strong', {}, String(queue.position || '?')),
                    ' von ', String(queue.totalInQueue || '?'))
                : null,
            h('div', { class: 'smail-mig-bar-wrap' },
                h('div', { class: 'smail-mig-bar', style: 'width:' + percent + '%' }),
            ),
            h('div', { class: 'smail-mig-progress-stats' },
                h('span', {}, foldersDone + '/' + foldersTotal + ' Ordner'),
                h('span', {}, messagesDone.toLocaleString('de-DE') + '/' + messagesTotal.toLocaleString('de-DE') + ' Nachrichten'),
                h('span', {}, percent + ' %'),
            ),
            h('p', { class: 'smail-mig-lead-small' },
                'Der Import läuft im Hintergrund weiter, auch wenn du diesen Dialog schließt. ',
                'Der Button unten rechts zeigt dir jederzeit den aktuellen Status.'),
        );
        const footer = h('div', { class: 'smail-mig-footer' },
            h('button', { type: 'button', class: 'smail-btn smail-btn-secondary', onclick: hide }, 'Schließen'),
        );
        const inner = h('div', {}, closeBtn(), body, footer);
        render(inner, wizardState || 'PROGRESS');
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(pollOnce, POLL_INTERVAL_MS);
        pollOnce();
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    async function pollOnce() {
        try {
            const r = await api('/status', 'GET');
            const active = r && r.active;
            const latest = r && r.latest;
            const next = active || latest;
            if (!next) { stopPolling(); return; }
            currentJob = next;
            if (next.isTerminal) {
                stopPolling();
                if (next.status === 'completed') showSuccess(next);
                else if (next.status === 'failed') showFailure(next);
                else hide();
                return;
            }
            renderProgress();
            updatePill();
        } catch (e) {
            // Transient network glitch — keep polling.
        }
    }

    function showSuccess(job) {
        const body = h('div', { class: 'smail-mig-body smail-mig-splash' },
            h('div', { class: 'smail-mig-icon-huge smail-mig-icon-ok', html: '&#10004;' }),
            h('h2', {}, 'Import erfolgreich!'),
            h('p', {}, 'Deine E-Mails wurden vollständig in dein neues Postfach übertragen. ',
                'Du kannst sie ab sofort im Ordnerbaum links sehen.'),
            h('p', { class: 'smail-mig-lead-small' }, 'Dieses Fenster schließt sich gleich automatisch.'),
        );
        const footer = h('div', { class: 'smail-mig-footer' },
            h('button', { type: 'button', class: 'smail-btn smail-btn-primary', onclick: () => onDismissTerminal(job) }, 'Verstanden'),
        );
        const inner = h('div', {}, closeBtn(), body, footer);
        render(inner, 'DONE_OK');
        updatePill();
        setTimeout(() => onDismissTerminal(job), AUTO_CLOSE_SUCCESS_MS);
    }

    function showFailure(job) {
        const body = h('div', { class: 'smail-mig-body smail-mig-splash' },
            h('div', { class: 'smail-mig-icon-huge smail-mig-icon-fail', html: '&#10007;' }),
            h('h2', {}, 'Import fehlgeschlagen'),
            h('p', {}, 'Beim Übertragen deiner E-Mails ist ein Problem aufgetreten. ',
                'Bitte versuche es später erneut oder wende dich an den Support.'),
            job && job.error ? h('pre', { class: 'smail-mig-err-detail' }, String(job.error)) : null,
        );
        const footer = h('div', { class: 'smail-mig-footer' },
            h('button', { type: 'button', class: 'smail-btn smail-btn-primary', onclick: () => onDismissTerminal(job) }, 'OK'),
        );
        const inner = h('div', {}, closeBtn(), body, footer);
        render(inner, 'DONE_FAIL');
        updatePill();
    }

    async function onDismissTerminal(job) {
        if (job && job.id) { await apiDismissJob(job.id); }
        currentJob = null;
        hide();
    }

    // Small "×" close button in the top-right of every screen.
    function closeBtn() {
        return h('button', {
            type: 'button', class: 'smail-mig-close', 'aria-label': 'Schließen',
            onclick: () => { if (canDismissByBackdrop()) hide(); }, html: '&times;',
        });
    }
})();
