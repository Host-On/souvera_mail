<?php
/**
 * Standalone out-of-office / "Abwesenheitsnotiz" form.
 * No webmail dependency — works in any browser.
 *
 * @var string $_['apiUrl']      JSON endpoint (GET/POST /apps/souvera_mail/vacation)
 * @var string $_['requestToken'] Nextcloud CSRF token
 */
$apiUrl = $_['apiUrl'];
$token = $_['requestToken'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Abwesenheitsnotiz — Souvera Mail</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--color-main-background, #f5f5f5);
            color: var(--color-main-text, #222);
            padding: 24px;
        }
        .card {
            max-width: 520px;
            margin: 0 auto;
            background: var(--color-background-dark, #fff);
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            padding: 24px;
        }
        h1 { font-size: 1.25rem; margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 4px; font-size: .875rem; }
        input, textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--color-border, #ccc);
            border-radius: 4px;
            font: inherit;
            font-size: .875rem;
            margin-bottom: 14px;
        }
        textarea { min-height: 100px; resize: vertical; }
        .row { display: flex; gap: 12px; }
        .row > * { flex: 1; }
        .toggle-row { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
        .toggle-row label { margin-bottom: 0; cursor: pointer; }
        .toggle-row input[type=checkbox] {
            width: 18px; height: 18px; margin-bottom: 0; cursor: pointer;
        }
        button {
            background: var(--color-primary, #0082c9);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            font: inherit;
            font-size: .875rem;
            cursor: pointer;
        }
        button:hover { opacity: .9; }
        .msg { margin-top: 12px; font-size: .875rem; }
        .msg.ok { color: var(--color-success, #2b7); }
        .msg.err { color: var(--color-error, #c22); }
        .spinner { display: none; margin-left: 8px; }
        body.loading .spinner { display: inline-block; }
    </style>
</head>
<body>
<div class="card">
    <h1>Abwesenheitsnotiz</h1>
    <form id="vacation-form">
        <div class="toggle-row">
            <input type="checkbox" id="enabled">
            <label for="enabled">Automatische Antwort aktivieren</label>
        </div>
        <label for="subject">Betreff</label>
        <input type="text" id="subject" placeholder="Abwesenheitsnotiz" maxlength="200">
        <label for="message">Nachricht</label>
        <textarea id="message" placeholder="Ich bin derzeit nicht im Büro…" maxlength="5000"></textarea>
        <div class="row">
            <div>
                <label for="from">Von (optional)</label>
                <input type="date" id="from">
            </div>
            <div>
                <label for="to">Bis (optional)</label>
                <input type="date" id="to">
            </div>
        </div>
        <button type="submit">Speichern</button>
        <span class="spinner" id="spinner">⏳</span>
    </form>
    <div class="msg" id="msg"></div>
</div>
<script>
(function () {
    var api = <?php echo json_encode($apiUrl); ?>;
    var csrf = <?php echo json_encode($token); ?>;
    var form = document.getElementById('vacation-form');
    var msg = document.getElementById('msg');
    var spinner = document.getElementById('spinner');

    function showMsg(text, ok) {
        msg.textContent = text;
        msg.className = 'msg ' + (ok ? 'ok' : 'err');
    }

    function setLoading(on) {
        document.body.classList.toggle('loading', on);
    }

    // Load current state
    setLoading(true);
    fetch(api, {
        headers: {
            'Accept': 'application/json',
            'requesttoken': csrf,
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        setLoading(false);
        if (data.status !== 'ok' || !data.available) {
            showMsg('Abwesenheitsnotiz ist auf diesem Server nicht verfügbar.', false);
            return;
        }
        var v = data.vacation;
        document.getElementById('enabled').checked = v.enabled;
        document.getElementById('subject').value = v.subject;
        document.getElementById('message').value = v.message;
        document.getElementById('from').value = v.from;
        document.getElementById('to').value = v.to;
    })
    .catch(function () {
        setLoading(false);
        showMsg('Fehler beim Laden.', false);
    });

    // Save
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        setLoading(true);
        showMsg('', false);

        var enabled = document.getElementById('enabled').checked;
        var subject = document.getElementById('subject').value.trim();
        var message = document.getElementById('message').value.trim();

        var body = new URLSearchParams();
        body.append('enabled', enabled ? '1' : '0');
        body.append('subject', subject);
        body.append('message', message);
        var from = document.getElementById('from').value;
        var to = document.getElementById('to').value;
        if (from) body.append('from', from);
        if (to) body.append('to', to);

        fetch(api, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'requesttoken': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            setLoading(false);
            if (data.status === 'ok') {
                showMsg(enabled ? 'Abwesenheitsnotiz aktiviert.' : 'Abwesenheitsnotiz deaktiviert.', true);
            } else {
                showMsg(data.message || 'Fehler beim Speichern.', false);
            }
        })
        .catch(function () {
            setLoading(false);
            showMsg('Fehler beim Speichern.', false);
        });
    });
})();
</script>
</body>
</html>