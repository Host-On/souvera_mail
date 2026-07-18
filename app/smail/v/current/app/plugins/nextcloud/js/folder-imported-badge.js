/* global rl */

/*
 * Souvera Mail — "NEW" badge for freshly imported folders (v0.16.0, P1).
 * -----------------------------------------------------------------
 * After a mailbox migration completes (see useMigration.js), the
 * imported folder names are stashed into `localStorage` under the
 * key `souvera-mail:imported-folders` as a `{name: expiryTs}` map.
 * This enricher walks Snappymail's folder-list sidebar every render
 * and adds a small green "NEW" badge next to any folder whose
 * (case-insensitive) name matches an unexpired entry. Entries older
 * than 24 h are pruned on every scan.
 *
 * Zero server state — the whole feature is browser-side + per-device.
 * Purposefully non-fatal: any error along the way silently skips the
 * badge; no click / focus / render behaviour of Snappymail is ever
 * intercepted.
 *
 * The badge is a plain <span class="sv-folder-new-badge">. Styling
 * lives in `plugins/nextcloud/css/external-accounts.css`. The scan
 * loop is rAF-throttled so it never competes with Snappymail's own
 * folder-list rendering pipeline.
 * ----------------------------------------------------------------- */
(function () {
    'use strict';

    if (!window.rl) { return; }

    const STORAGE_KEY = 'souvera-mail:imported-folders';
    const BADGE_CLASS = 'sv-folder-new-badge';
    const MARKER = '__svImportedBadge';

    // Safe i18n lookup — falls back to English default when the key or
    // engine is not present (early boot, missing plugin lang).
    const i18n = (key, fallback) => {
        try {
            if (rl && typeof rl.i18n === 'function') {
                const v = rl.i18n(key);
                if (v && v !== key) { return v; }
            }
        } catch (_e) { /* silent */ }
        return fallback;
    };

    // Read the storage map; prune expired entries silently.
    const readAndPruneMap = () => {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) { return {}; }
            const map = JSON.parse(raw) || {};
            if (typeof map !== 'object') { return {}; }
            const now = Date.now();
            const alive = {};
            let mutated = false;
            for (const name of Object.keys(map)) {
                const ts = Number(map[name]);
                if (ts && ts > now) {
                    alive[name] = ts;
                } else {
                    mutated = true;
                }
            }
            if (mutated) {
                if (Object.keys(alive).length === 0) {
                    window.localStorage.removeItem(STORAGE_KEY);
                } else {
                    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(alive));
                }
            }
            return alive;
        } catch (_e) {
            return {};
        }
    };

    // Case-insensitive lookup — Snappymail may localise / prettify
    // folder display names. We compare against the RAW folder name
    // exposed via `data-bind="text: fullName"` etc. Store keys are
    // whatever startMigration() persisted (source-side names).
    const buildLookup = (map) => {
        const lc = {};
        for (const name of Object.keys(map)) {
            lc[name.toLowerCase()] = true;
        }
        return lc;
    };

    // Find every folder-list row Snappymail renders. The selector must
    // stay generous — Snappymail's SIDEBAR template uses
    // `.b-folders .b-folders__item` in the current smail bundle, but
    // older bundles emit `li.folder-item`. We accept both.
    const findFolderRows = () => {
        return document.querySelectorAll(
            'li.b-folders__item, .b-folders li.e-item, ul.b-folder-list li, li.folder-item, [data-testid="folder-list-item"]'
        );
    };

    // Extract the folder name from a rendered folder row. Prefer
    // Snappymail's internal `name`/`fullName` when accessible via
    // Knockout data-bind, fall back to the visible text.
    const getFolderName = (row) => {
        const nameEl = row.querySelector('[data-bind*="name"], .b-folders__name, .folder-name');
        const raw = (nameEl ? nameEl.textContent : row.textContent) || '';
        return raw.trim();
    };

    const buildBadge = () => {
        const span = document.createElement('span');
        span.className = BADGE_CLASS;
        span.setAttribute('data-testid', 'sv-folder-new-badge');
        span.textContent = i18n('FOLDERS/NEW_BADGE', 'NEW');
        span.setAttribute('title', i18n(
            'FOLDERS/NEW_BADGE_TITLE',
            'Recently imported — badge disappears automatically after 24 hours.'
        ));
        return span;
    };

    const decorateRow = (row, lookup) => {
        const name = getFolderName(row);
        if (!name) { return; }
        const already = row.querySelector('.' + BADGE_CLASS);
        const shouldBadge = !!lookup[name.toLowerCase()];
        if (shouldBadge && !already) {
            row[MARKER] = true;
            const anchor = row.querySelector('a, .b-folders__name') || row;
            anchor.appendChild(buildBadge());
        } else if (!shouldBadge && already) {
            already.remove();
        }
    };

    let scanScheduled = false;
    const scan = () => {
        const map = readAndPruneMap();
        const empty = Object.keys(map).length === 0;
        const rows = findFolderRows();
        if (empty) {
            // Fast path: remove any stale badges (e.g. after 24h expiry
            // OR after the user has revisited the tab days later).
            rows.forEach((row) => {
                const b = row.querySelector('.' + BADGE_CLASS);
                if (b) { b.remove(); }
            });
            return;
        }
        const lookup = buildLookup(map);
        rows.forEach((row) => decorateRow(row, lookup));
    };
    const requestScan = () => {
        if (scanScheduled) { return; }
        scanScheduled = true;
        (window.requestAnimationFrame || window.setTimeout)(() => {
            scanScheduled = false;
            try { scan(); } catch (_e) { /* silent */ }
        });
    };

    // React immediately when a migration completes — the Vue composable
    // dispatches this event with the imported folder list already in
    // localStorage. We just refresh the badge now, no polling needed.
    window.addEventListener('souvera-mail:migration-completed', () => {
        requestScan();
    });

    // Cover the "user reopens the tab hours later" case: scan every
    // 5 min so expired entries disappear on their own.
    window.setInterval(requestScan, 5 * 60 * 1000);

    // Cover Snappymail's own re-renders of the folder tree.
    const observer = new MutationObserver(() => requestScan());
    const attach = () => {
        if (!document.body) { return false; }
        observer.observe(document.body, { childList: true, subtree: true });
        return true;
    };
    if (!attach()) {
        document.addEventListener('DOMContentLoaded', () => {
            attach();
            requestScan();
        }, { once: true });
    } else {
        requestScan();
    }
})();
