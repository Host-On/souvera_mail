/*
 * Souvera Mail — /settings/user/security hijack script.
 *
 * Injects a branded notice card ABOVE the (hidden by CSS) built-in
 * "Create new app password" form. The card points the user at the
 * Souvera Mail Security & Devices tab, which runs the combined
 * Mail + Nextcloud/DAV app-password flow.
 *
 * Only runs when the CSS also loaded (i.e. user is in
 * souvera-users). Idempotent — safe to re-run on Vue re-renders.
 */
/* global OC */
(function () {
    'use strict';

    var NOTICE_CLASS = 'souvera-mail-security-notice';
    var SOUVERA_URL = OC.generateUrl('/apps/souvera_mail/#settings-souvera-account');

    function inject() {
        // If we already injected, do nothing.
        if (document.querySelector('.' + NOTICE_CLASS)) {
            return;
        }

        // Find the "Devices & sessions" / "App passwords" section.
        // NC 33-35 renders it under #security with an <h2> that
        // contains the translated string; we anchor on the first
        // <h2> inside #security and prepend to its parent.
        var securityRoot = document.getElementById('security');
        if (!securityRoot) {
            return;
        }

        var target = securityRoot.querySelector('.section, section');
        if (!target) {
            target = securityRoot;
        }

        var notice = document.createElement('div');
        notice.className = NOTICE_CLASS;
        notice.innerHTML = ''
            + '<div class="icon" aria-hidden="true">✉️</div>'
            + '<div class="body">'
            +   '<h3>App-Passwörter werden über Souvera Mail verwaltet</h3>'
            +   '<p>'
            +     'Damit ein App-Passwort sowohl für <strong>E-Mail</strong> (Thunderbird, K-9, Apple Mail) '
            +     'als auch für <strong>Kalender &amp; Kontakte</strong> (DAVx⁵, Apple Kalender) funktioniert, '
            +     'erstellen Sie es bitte direkt in Souvera Mail. Dort wird das eine Passwort automatisch '
            +     'für beide Seiten gültig gemacht.'
            +   '</p>'
            +   '<div class="actions">'
            +     '<a class="button primary" href="' + SOUVERA_URL + '">App-Passwort für Mail &amp; Nextcloud erstellen</a>'
            +   '</div>'
            + '</div>';

        target.insertBefore(notice, target.firstChild);
    }

    // Vue components render asynchronously; run once now and again
    // after DOM mutations settle so we survive Vue re-renders.
    function attempt() {
        try { inject(); } catch (e) { /* non-fatal */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attempt);
    } else {
        attempt();
    }
    // Vue often re-renders after data loads (fetch of existing
    // tokens etc.) — poll a few times so we catch that.
    var tries = 0;
    var iv = setInterval(function () {
        attempt();
        tries += 1;
        if (tries >= 10) { clearInterval(iv); }
    }, 400);
})();
