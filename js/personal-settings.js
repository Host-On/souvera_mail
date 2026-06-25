(function () {
    'use strict';

    // ─── 1. Dashboard widget mode toggle ────────────────────────────────────
    var modeForm = document.getElementById('souvera-mail-dashboard-mode-form');
    if (modeForm) {
        var modeStatus = document.getElementById('souvera-mail-dashboard-mode-status');
        var modeEndpoint = modeForm.dataset.endpoint;
        var modeLabelUnread = modeForm.dataset.labelUnread || 'Unread only';
        var modeLabelAll = modeForm.dataset.labelAll || 'Full inbox';
        var modeLabelFail = modeForm.dataset.labelFail || 'Save failed';

        modeForm.addEventListener('change', function (event) {
            if (!event.target || event.target.name !== 'souvera-mail-dashboard-mode') {
                return;
            }
            var value = event.target.value;
            modeStatus.textContent = '\u2026';
            fetch(modeEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'requesttoken': (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : ''
                },
                body: 'mode=' + encodeURIComponent(value)
            })
            .then(function (resp) { return resp.json().then(function (body) { return { ok: resp.ok, body: body }; }); })
            .then(function (r) {
                if (r.ok && r.body && r.body.status === 'ok') {
                    modeStatus.textContent = '\u2713 ' + (r.body.mode === 'all' ? modeLabelAll : modeLabelUnread);
                } else {
                    modeStatus.textContent = '\u2717 ' + ((r.body && r.body.message) || modeLabelFail);
                }
            })
            .catch(function (err) {
                modeStatus.textContent = '\u2717 ' + err.message;
            });
        });
    }

    // ─── 2. App passwords (IMAP/POP3/SMTP) ──────────────────────────────────
    var apSection = document.getElementById('souvera-mail-app-passwords-section');
    if (!apSection || apSection.dataset.available !== '1') {
        return;
    }

    var listUrl = apSection.dataset.listUrl;
    var createUrl = apSection.dataset.createUrl;
    var destroyTemplate = apSection.dataset.destroyUrlTemplate;
    var labels = {
        loadFail: apSection.dataset.labelLoadFail,
        createFail: apSection.dataset.labelCreateFail,
        revokeFail: apSection.dataset.labelRevokeFail,
        confirmRevoke: apSection.dataset.labelConfirmRevoke,
        copy: apSection.dataset.labelCopy,
        copied: apSection.dataset.labelCopied,
        empty: apSection.dataset.labelEmpty,
        revoke: apSection.dataset.labelRevoke,
        createdWarning: apSection.dataset.labelCreatedWarning
    };

    var tbody = document.getElementById('souvera-mail-app-passwords-tbody');
    var newlyCreated = document.getElementById('souvera-mail-app-password-newly-created');
    var createForm = document.getElementById('souvera-mail-app-password-create-form');

    function csrfHeaders(contentType) {
        var h = {
            'Accept': 'application/json',
            'requesttoken': (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : ''
        };
        if (contentType) {
            h['Content-Type'] = contentType;
        }
        return h;
    }

    function escapeHtml(str) {
        return String(str === null || str === undefined ? '' : str).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function formatDate(iso) {
        if (!iso) { return ''; }
        try {
            var d = new Date(iso);
            if (isNaN(d.getTime())) { return iso; }
            return d.toLocaleString();
        } catch (e) {
            return iso;
        }
    }

    function renderList(items) {
        if (!items || items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="padding:12px;color:var(--color-text-maxcontrast,#888);font-size:13px;">'
                + escapeHtml(labels.empty) + '</td></tr>';
            return;
        }
        var rows = items.map(function (it) {
            return '<tr data-id="' + escapeHtml(it.id) + '">'
                + '<td style="padding:8px;border-top:1px solid var(--color-border,#eee);">' + escapeHtml(it.description) + '</td>'
                + '<td style="padding:8px;border-top:1px solid var(--color-border,#eee);font-size:13px;color:var(--color-text-maxcontrast,#888);">'
                + escapeHtml(formatDate(it.createdAt)) + '</td>'
                + '<td style="padding:8px;border-top:1px solid var(--color-border,#eee);text-align:right;">'
                + '<button type="button" class="souvera-mail-ap-revoke" '
                + 'data-id="' + escapeHtml(it.id) + '" '
                + 'data-testid="souvera-mail-app-password-revoke-' + escapeHtml(it.id) + '" '
                + 'style="padding:4px 12px;border-radius:100px;border:1px solid var(--color-error,#c44);background:transparent;color:var(--color-error,#c44);cursor:pointer;font-size:13px;">'
                + escapeHtml(labels.revoke) + '</button></td></tr>';
        });
        tbody.innerHTML = rows.join('');

        tbody.querySelectorAll('.souvera-mail-ap-revoke').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm(labels.confirmRevoke)) { return; }
                revoke(btn.dataset.id);
            });
        });
    }

    function loadList() {
        return fetch(listUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: csrfHeaders()
        })
        .then(function (resp) { return resp.json().then(function (body) { return { ok: resp.ok, body: body }; }); })
        .then(function (r) {
            if (r.ok && r.body && r.body.status === 'ok') {
                renderList(r.body.items || []);
            } else {
                tbody.innerHTML = '<tr><td colspan="3" style="padding:12px;color:var(--color-error,#c44);font-size:13px;">'
                    + escapeHtml(labels.loadFail + ': ' + ((r.body && r.body.message) || '')) + '</td></tr>';
            }
        })
        .catch(function (err) {
            tbody.innerHTML = '<tr><td colspan="3" style="padding:12px;color:var(--color-error,#c44);font-size:13px;">'
                + escapeHtml(labels.loadFail + ': ' + err.message) + '</td></tr>';
        });
    }

    function showNewlyCreated(secret, description) {
        newlyCreated.innerHTML = ''
            + '<div style="font-weight:600;margin-bottom:4px;">' + escapeHtml(description) + '</div>'
            + '<div style="display:flex;gap:8px;align-items:center;">'
            +   '<code data-testid="souvera-mail-app-password-secret" '
            +     'style="flex:1;padding:8px 12px;border-radius:6px;background:var(--color-background-dark,#222);color:var(--color-main-text,#fff);font-family:monospace;font-size:14px;word-break:break-all;">'
            +     escapeHtml(secret) + '</code>'
            +   '<button type="button" data-testid="souvera-mail-app-password-copy" '
            +     'style="padding:8px 14px;border-radius:100px;border:0;background:var(--color-primary-element,#0077C7);color:#fff;cursor:pointer;">'
            +     escapeHtml(labels.copy) + '</button>'
            + '</div>'
            + '<div style="margin-top:8px;font-size:12px;color:var(--color-warning,#856404);">'
            +   '\u26a0 ' + escapeHtml(labels.createdWarning)
            + '</div>';
        newlyCreated.style.display = 'block';

        var copyBtn = newlyCreated.querySelector('[data-testid="souvera-mail-app-password-copy"]');
        copyBtn.addEventListener('click', function () {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(secret).then(function () {
                    copyBtn.textContent = labels.copied;
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = secret;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); copyBtn.textContent = labels.copied; }
                finally { document.body.removeChild(ta); }
            }
        });
    }

    function create(description) {
        return fetch(createUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: csrfHeaders('application/x-www-form-urlencoded'),
            body: 'description=' + encodeURIComponent(description)
        })
        .then(function (resp) { return resp.json().then(function (body) { return { ok: resp.ok, body: body }; }); })
        .then(function (r) {
            if (r.ok && r.body && r.body.status === 'ok' && r.body.created) {
                showNewlyCreated(r.body.created.secret, r.body.created.description);
                return loadList();
            }
            window.alert(labels.createFail + ': ' + ((r.body && r.body.message) || ''));
        })
        .catch(function (err) {
            window.alert(labels.createFail + ': ' + err.message);
        });
    }

    function revoke(id) {
        var url = destroyTemplate.replace('__ID__', encodeURIComponent(id));
        return fetch(url, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: csrfHeaders()
        })
        .then(function (resp) { return resp.json().then(function (body) { return { ok: resp.ok, body: body }; }); })
        .then(function (r) {
            if (r.ok && r.body && r.body.status === 'ok') {
                return loadList();
            }
            window.alert(labels.revokeFail + ': ' + ((r.body && r.body.message) || ''));
        })
        .catch(function (err) {
            window.alert(labels.revokeFail + ': ' + err.message);
        });
    }

    if (createForm) {
        createForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var input = createForm.querySelector('[name="description"]');
            var description = input ? input.value.trim() : '';
            if (description === '') { return; }
            create(description).then(function () {
                if (input) { input.value = ''; }
            });
        });
    }

    loadList();
})();
