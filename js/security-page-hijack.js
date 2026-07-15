/* global t */

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
        var HEADING = t('souvera_mail', 'App passwords are managed via Souvera Mail');
        var BODY = t('souvera_mail', 'So that an app password works for both mail (Thunderbird, K-9, Apple Mail) and calendar & contacts (DAVx⁵, Apple Calendar), please create it directly in Souvera Mail. There the single password is automatically made valid for both sides.');
        var BTN = t('souvera_mail', 'Create app password for Mail & Nextcloud');
        notice.innerHTML = ''
            + '<div class="icon" aria-hidden="true">✉</div>'
            + '<div class="body">'
            +   '<h3>' + HEADING + '</h3>'
            +   '<p>' + BODY + '</p>'
            +   '<div class="actions">'
            +     '<a class="button primary" href="' + SOUVERA_URL + '">' + BTN + '</a>'
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
