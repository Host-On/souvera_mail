/*
 * Souvera Mail — Dashboard widget enhancer.
 * ---------------------------------------------------------------
 * Loaded on every Dashboard page render (via
 * UnreadMailWidget::load() → Util::addScript).  Does two things
 * the IAPIWidgetV2 JSON contract cannot express server-side:
 *
 *   1. Injects a Souvera-Shield-style ✓ checkmark into the
 *      NcEmptyContent icon slot of OUR widget when its item list
 *      is empty. The SVG is inlined here (no extra HTTP hop, no
 *      CSP hoop).
 *
 *   2. As a defensive belt-and-braces alongside the CSS filter
 *      rules in `dashboard-widget.css`, detects the light/dark
 *      body class ONCE at boot and stamps a `data-theme-flavour`
 *      attribute on our widget root so QA can spot theme-driven
 *      styling regressions in DOM snapshots.
 *
 * Idempotency & performance
 * ~~~~~~~~~~~~~~~~~~~~~~~~~
 * The observer scans DOM mutations on document.body but its work
 * is O(1) — a single `matches()` filter plus a marker attribute
 * so we never re-inject into the same widget. Fully idle when the
 * widget is not on screen (e.g. any non-dashboard page).
 * ---------------------------------------------------------------
 */
(function () {
    'use strict';

    // Souvera-Shield-parity checkmark. Deliberately monochrome so
    // it inherits `currentColor` from the CSS in dashboard-widget.css
    // (matches --color-text-maxcontrast for both light + dark).
    var CHECK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">'
        + '<path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" />'
        + '</svg>';

    // Widget-root selector — cover all three shapes NC 33/34/35 render.
    // We match on the widget id string appearing anywhere in an
    // element id/data attribute. The widget id `souvera_mail-unread`
    // is defined in UnreadMailWidget::WIDGET_ID.
    var WIDGET_ID = 'souvera_mail-unread';
    var WIDGET_MATCH = [
        '[data-widget="' + WIDGET_ID + '"]',
        '[data-id="' + WIDGET_ID + '"]',
        '[id*="' + WIDGET_ID + '"]',
    ].join(',');

    var INJECTED_ATTR = 'data-souvera-check-injected';

    function stampThemeFlavour(root) {
        if (root.getAttribute('data-theme-flavour')) return;
        var isDark = document.body.hasAttribute('data-theme-dark');
        root.setAttribute('data-theme-flavour', isDark ? 'dark' : 'light');
    }

    function injectCheckmark(widgetRoot) {
        if (widgetRoot.getAttribute(INJECTED_ATTR)) return;

        // NcEmptyContent renders its icon into `.empty-content__icon`.
        // We only inject if the slot is empty (NC would populate it if
        // some future widget author supplied their own icon slot via
        // NcDashboardWidget's `emptyContentIcon` slot).
        var iconSlot = widgetRoot.querySelector('.empty-content__icon');
        if (!iconSlot) return;
        if (iconSlot.querySelector('.souvera-mail-widget-empty-icon')) return;
        if (iconSlot.children.length > 0) return; // don't clobber someone else

        var wrapper = document.createElement('div');
        wrapper.className = 'souvera-mail-widget-empty-icon';
        wrapper.innerHTML = CHECK_SVG;
        iconSlot.appendChild(wrapper);

        widgetRoot.setAttribute(INJECTED_ATTR, '1');
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var widgets = scope.querySelectorAll(WIDGET_MATCH);
        widgets.forEach(function (w) {
            stampThemeFlavour(w);
            injectCheckmark(w);
        });
    }

    function boot() {
        // Initial pass — the widget may already be in the DOM if
        // Dashboard rendered before this script executed.
        scan(document);

        // Watch for later re-renders (Vue reactivity, poll refresh).
        var mo = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                for (var j = 0; j < m.addedNodes.length; j++) {
                    var node = m.addedNodes[j];
                    if (node.nodeType !== 1) continue; // Element only
                    // A widget root itself was added, or a subtree
                    // that contains one — cover both.
                    if (node.matches && node.matches(WIDGET_MATCH)) {
                        stampThemeFlavour(node);
                        injectCheckmark(node);
                    } else if (node.querySelector) {
                        scan(node);
                    }
                }
            }
        });
        mo.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
