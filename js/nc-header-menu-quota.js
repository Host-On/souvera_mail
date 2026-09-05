/**
 * Souvera Mail — quota entry in Nextcloud's global user menu.
 *
 * Loaded on every NC page (for souvera-users only, see
 * NcHeaderMenuQuotaListener). The endpoint URL is baked into the inline
 * `#souvera-mail-quota-config` JSON by the listener.
 *
 * The user menu popover is rendered lazily by NC — a MutationObserver
 * catches its first appearance and appends a stable, non-clickable
 * info entry (ENTRY_ID) showing the mail storage usage.
 */
(function () {
    'use strict'

    var ENTRY_ID = 'souvera-mail-quota-menu-entry'
    var POLL_MS = 15000
    var REFRESH_MS = 5 * 60 * 1000

    var configEl = document.getElementById('souvera-mail-quota-config')
    if (!configEl) {
        return
    }

    var endpoint
    try {
        endpoint = JSON.parse(configEl.textContent).endpoint
    } catch (e) {
        return
    }
    if (!endpoint) {
        return
    }

    function label() {
        try {
            var l10n = window.OC && window.OC.L10N
            return l10n ? l10n.translate('souvera_mail', 'Mail storage') : 'Mail storage'
        } catch (e) {
            return 'Mail storage'
        }
    }

    var lastFetched = 0
    var lastSummary = null

    function fetchQuota(cb) {
        var now = Date.now()
        if (lastSummary !== null && now - lastFetched < REFRESH_MS) {
            cb(lastSummary)
            return
        }

        var xhr = new XMLHttpRequest()
        xhr.open('GET', endpoint, true)
        xhr.setRequestHeader('Accept', 'application/json')
        xhr.onload = function () {
            lastFetched = Date.now()
            var data = null
            try {
                data = JSON.parse(xhr.responseText)
                data = data && data.ocs ? data.ocs.data : data
            } catch (e) {
                data = null
            }

            if (!data || data.status !== 'ok') {
                lastSummary = null
                cb(null)
                return
            }

            lastSummary = data
            cb(data)
        }
        xhr.onerror = function () {
            cb(null)
        }
        xhr.send()
    }

    function summaryText(data) {
        if (!data) {
            return label() + ': …'
        }
        if (data.usageKnown === false) {
            return label() + ': —'
        }
        var text = label() + ': ' + ((data.formatted && data.formatted.used) || '—')
        if (!data.unlimited && data.formatted && data.formatted.total) {
            text += ' / ' + data.formatted.total + ' (' + (data.percentage || 0) + '%)'
        }
        return text
    }

    function renderEntry(menuList) {
        var existing = document.getElementById(ENTRY_ID)
        fetchQuota(function (data) {
            if (existing && existing.parentNode === menuList && existing.dataset.summary === summaryText(data)) {
                return
            }

            var entry = document.createElement('li')
            entry.id = ENTRY_ID
            entry.setAttribute('style', 'cursor:default;user-select:none;')
            entry.setAttribute('data-summary', summaryText(data))

            var inner = document.createElement('span')
            inner.setAttribute('style', 'cursor:default;display:flex;align-items:center;gap:8px;')
            inner.textContent = summaryText(data)
            entry.appendChild(inner)

            if (existing && existing.parentNode === menuList) {
                menuList.replaceChild(entry, existing)
            } else {
                menuList.appendChild(entry)
            }
        })
    }

    function checkForMenu() {
        // NC renders the user-menu popover lazily; it lives under #settings.
        var menu = document.getElementById('settings')
        if (!menu) {
            return
        }
        var list = menu.querySelector('ul') || menu
        renderEntry(list)
    }

    // The popover appears on avatar click — observe the DOM for it.
    var observer = new MutationObserver(function () {
        checkForMenu()
    })
    try {
        observer.observe(document.body, { childList: true, subtree: true })
    } catch (e) {
        // older engines: fall back to polling only
    }

    checkForMenu()
    setInterval(checkForMenu, POLL_MS)
})()
