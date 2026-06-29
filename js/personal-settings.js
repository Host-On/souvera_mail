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

    // ─── 3. Connected devices (Nextcloud sessions) ──────────────────────────
    var cdSection = document.getElementById('souvera-mail-connected-devices-section');
    if (!cdSection) {
        return;
    }
    var cdTbody = document.getElementById('souvera-mail-connected-devices-tbody');
    var cdSignOutBtn = document.getElementById('souvera-mail-sign-out-others-btn');
    var cdListUrl = cdSection.dataset.listUrl;
    var cdDestroyTpl = cdSection.dataset.destroyUrlTemplate;
    var cdSignOutOthersUrl = cdSection.dataset.signOutOthersUrl;
    var cdLabels = {
        loadFail: cdSection.dataset.labelLoadFail,
        revokeFail: cdSection.dataset.labelRevokeFail,
        signOutOthersFail: cdSection.dataset.labelSignOutOthersFail,
        confirmRevoke: cdSection.dataset.labelConfirmRevoke,
        confirmSignOutOthers: cdSection.dataset.labelConfirmSignOutOthers,
        revoke: cdSection.dataset.labelRevoke,
        signOutOthers: cdSection.dataset.labelSignOutOthers,
        current: cdSection.dataset.labelCurrent,
        empty: cdSection.dataset.labelEmpty,
        revokedCount: cdSection.dataset.labelRevokedCount,
        typeBrowser: cdSection.dataset.labelTypeBrowser,
        typeApp: cdSection.dataset.labelTypeApp
    };

    function formatRelative(epochSeconds) {
        if (!epochSeconds) { return ''; }
        var d = new Date(epochSeconds * 1000);
        if (isNaN(d.getTime())) { return ''; }
        var diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 60) { return 'just now'; }
        if (diff < 3600) { return Math.floor(diff / 60) + 'm ago'; }
        if (diff < 86400) { return Math.floor(diff / 3600) + 'h ago'; }
        if (diff < 604800) { return Math.floor(diff / 86400) + 'd ago'; }
        return d.toLocaleString();
    }

    function renderConnectedDevices(items) {
        if (!items || items.length === 0) {
            cdTbody.innerHTML = '<tr><td colspan="3" style="padding:12px;color:var(--color-text-maxcontrast,#888);font-size:13px;">'
                + escapeHtml(cdLabels.empty) + '</td></tr>';
            return;
        }
        var rows = items.map(function (it) {
            var typeBadge = it.type === 'app'
                ? '<span style="display:inline-block;padding:2px 8px;border-radius:100px;background:var(--color-primary-light,#e8f0fe);color:var(--color-primary-element,#0077C7);font-size:11px;font-weight:600;letter-spacing:0.02em;text-transform:uppercase;margin-right:6px;">'
                  + escapeHtml(cdLabels.typeApp) + '</span>'
                : '<span style="display:inline-block;padding:2px 8px;border-radius:100px;background:var(--color-background-hover,#f0f0f0);color:var(--color-text-maxcontrast,#666);font-size:11px;font-weight:600;letter-spacing:0.02em;text-transform:uppercase;margin-right:6px;">'
                  + escapeHtml(cdLabels.typeBrowser) + '</span>';
            var currentBadge = it.current
                ? ' <span style="display:inline-block;padding:2px 8px;border-radius:100px;background:var(--color-success-hover,#e8f5e9);color:var(--color-success,#388e3c);font-size:11px;font-weight:600;letter-spacing:0.02em;margin-left:6px;">'
                  + escapeHtml(cdLabels.current) + '</span>'
                : '';
            var actionsCell = it.current
                ? '<td style="padding:8px;border-top:1px solid var(--color-border,#eee);text-align:right;color:var(--color-text-maxcontrast,#aaa);font-size:12px;font-style:italic;">—</td>'
                : '<td style="padding:8px;border-top:1px solid var(--color-border,#eee);text-align:right;">'
                  + '<button type="button" class="souvera-mail-cd-revoke" '
                  + 'data-id="' + escapeHtml(String(it.id)) + '" '
                  + 'data-testid="souvera-mail-cd-revoke-' + escapeHtml(String(it.id)) + '" '
                  + 'style="padding:4px 12px;border-radius:100px;border:1px solid var(--color-error,#c44);background:transparent;color:var(--color-error,#c44);cursor:pointer;font-size:13px;">'
                  + escapeHtml(cdLabels.revoke) + '</button></td>';
            return '<tr data-id="' + escapeHtml(String(it.id)) + '">'
                + '<td style="padding:8px;border-top:1px solid var(--color-border,#eee);">'
                + typeBadge + escapeHtml(it.name) + currentBadge + '</td>'
                + '<td style="padding:8px;border-top:1px solid var(--color-border,#eee);font-size:13px;color:var(--color-text-maxcontrast,#888);">'
                + escapeHtml(formatRelative(it.lastActivity)) + '</td>'
                + actionsCell
                + '</tr>';
        });
        cdTbody.innerHTML = rows.join('');

        cdTbody.querySelectorAll('.souvera-mail-cd-revoke').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm(cdLabels.confirmRevoke)) { return; }
                cdRevoke(btn.dataset.id);
            });
        });
    }

    function cdLoadList() {
        return fetch(cdListUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: csrfHeaders()
        })
        .then(function (resp) { return resp.json().then(function (body) { return { ok: resp.ok, body: body }; }); })
        .then(function (r) {
            if (r.ok && r.body && r.body.status === 'ok') {
                renderConnectedDevices(r.body.items || []);
            } else {
                cdTbody.innerHTML = '<tr><td colspan="3" style="padding:12px;color:var(--color-error,#c44);font-size:13px;">'
                    + escapeHtml(cdLabels.loadFail + ': ' + ((r.body && r.body.message) || '')) + '</td></tr>';
            }
        })
        .catch(function (err) {
            cdTbody.innerHTML = '<tr><td colspan="3" style="padding:12px;color:var(--color-error,#c44);font-size:13px;">'
                + escapeHtml(cdLabels.loadFail + ': ' + err.message) + '</td></tr>';
        });
    }

    function cdRevoke(id) {
        var url = cdDestroyTpl.replace('__ID__', encodeURIComponent(id));
        return fetch(url, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: csrfHeaders()
        })
        .then(function (resp) { return resp.json().then(function (body) { return { ok: resp.ok, body: body }; }); })
        .then(function (r) {
            if (r.ok && r.body && r.body.status === 'ok') {
                return cdLoadList();
            }
            window.alert(cdLabels.revokeFail + ': ' + ((r.body && r.body.message) || ''));
        })
        .catch(function (err) {
            window.alert(cdLabels.revokeFail + ': ' + err.message);
        });
    }

    function cdSignOutOthers() {
        return fetch(cdSignOutOthersUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: csrfHeaders('application/x-www-form-urlencoded'),
            body: ''
        })
        .then(function (resp) { return resp.json().then(function (body) { return { ok: resp.ok, body: body }; }); })
        .then(function (r) {
            if (r.ok && r.body && r.body.status === 'ok') {
                var revoked = (r.body.revoked !== undefined) ? r.body.revoked : '?';
                if (revoked === 0) {
                    window.alert(cdLabels.empty);
                } else {
                    window.alert(revoked + ' ' + cdLabels.revokedCount);
                }
                return cdLoadList();
            }
            window.alert(cdLabels.signOutOthersFail + ': ' + ((r.body && r.body.message) || ''));
        })
        .catch(function (err) {
            window.alert(cdLabels.signOutOthersFail + ': ' + err.message);
        });
    }

    if (cdSignOutBtn) {
        cdSignOutBtn.addEventListener('click', function () {
            if (!window.confirm(cdLabels.confirmSignOutOthers)) { return; }
            cdSignOutOthers();
        });
    }

    cdLoadList();

})();
