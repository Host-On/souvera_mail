(function () {
    'use strict';

    var form = document.getElementById('smail-dashboard-mode-form');
    if (!form) {
        return;
    }
    var status = document.getElementById('smail-dashboard-mode-status');
    var endpoint = form.dataset.endpoint;
    var labelUnread = form.dataset.labelUnread || 'Unread only';
    var labelAll = form.dataset.labelAll || 'Full inbox';
    var labelFail = form.dataset.labelFail || 'Save failed';

    form.addEventListener('change', function (event) {
        if (!event.target || event.target.name !== 'smail-dashboard-mode') {
            return;
        }
        var value = event.target.value;
        status.textContent = '\u2026';
        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'requesttoken': (typeof OC !== 'undefined' && OC.requestToken) ? OC.requestToken : ''
            },
            body: 'mode=' + encodeURIComponent(value)
        })
        .then(function (resp) {
            return resp.json().then(function (body) {
                return { ok: resp.ok, body: body };
            });
        })
        .then(function (r) {
            if (r.ok && r.body && r.body.status === 'ok') {
                status.textContent = '\u2713 ' + (r.body.mode === 'all' ? labelAll : labelUnread);
            } else {
                status.textContent = '\u2717 ' + ((r.body && r.body.message) || labelFail);
            }
        })
        .catch(function (err) {
            status.textContent = '\u2717 ' + err.message;
        });
    });
})();
