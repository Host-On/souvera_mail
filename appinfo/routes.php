<?php

return [
    'routes' => [
        [
            'name' => 'page#index',
            'url' => '/',
            'verb' => 'GET'
        ],
        [
            'name' => 'page#indexPost',
            'url' => '/',
            'verb' => 'POST'
        ],
        [
            // v0.17.0 — standalone / embedded entry point for WebView
            // wrappers. Same auth middleware as page#index, but the
            // response is rendered with `renderAs('base')` — no
            // Nextcloud header, no app-menu, no rounded #content
            // shell. Prefer this over `/?embedded=1` for hard-coded
            // URLs (no query-string quoting to worry about in shells).
            'name' => 'page#embed',
            'url' => '/embed',
            'verb' => 'GET'
        ],
        [
            // v0.17.1 — POST twin of page#embed. SnappyMail's client
            // makes relative AJAX-POSTs against the current page URL,
            // so `/embed` must accept POST too — otherwise every
            // SnappyMail JSON call from the standalone view 404s and
            // the client renders "Please refresh the page".
            'name' => 'page#embedPost',
            'url' => '/embed',
            'verb' => 'POST'
        ],
        [
            'name' => 'page#appGet',
            'url' => '/app/',
            'verb' => 'GET'
        ],
        [
            'name' => 'page#appPost',
            'url' => '/app/',
            'verb' => 'POST'
        ],
        [
            'name' => 'settings#index',
            'url' => '/settings',
            'verb' => 'GET'
        ],
        [
            'name' => 'preference#setDashboardMode',
            'url' => '/preferences/dashboard-mode',
            'verb' => 'POST'
        ],
        [
            'name' => 'appPassword#index',
            'url' => '/app-passwords',
            'verb' => 'GET'
        ],
        [
            'name' => 'appPassword#create',
            'url' => '/app-passwords',
            'verb' => 'POST'
        ],
        [
            'name' => 'appPassword#destroy',
            'url' => '/app-passwords/{id}',
            'verb' => 'DELETE'
        ],
        [
            'name' => 'quota#index',
            'url' => '/quota',
            'verb' => 'GET'
        ],
        [
            'name' => 'connectedDevices#index',
            'url' => '/connected-devices',
            'verb' => 'GET'
        ],
        [
            'name' => 'connectedDevices#destroy',
            'url' => '/connected-devices/{id}',
            'verb' => 'DELETE',
            'requirements' => ['id' => '\d+']
        ],
        [
            'name' => 'connectedDevices#signOutOthers',
            'url' => '/connected-devices/sign-out-others',
            'verb' => 'POST'
        ],
        [
            'name' => 'migration#welcomeState',
            'url' => '/migration/welcome-state',
            'verb' => 'GET'
        ],
        [
            'name' => 'migration#dismissWelcome',
            'url' => '/migration/dismiss-welcome',
            'verb' => 'POST'
        ],
        [
            'name' => 'migration#testConnection',
            'url' => '/migration/test-connection',
            'verb' => 'POST'
        ],
        [
            'name' => 'migration#listFolders',
            'url' => '/migration/list-folders',
            'verb' => 'POST'
        ],
        [
            'name' => 'migration#start',
            'url' => '/migration/start',
            'verb' => 'POST'
        ],
        [
            'name' => 'migration#status',
            'url' => '/migration/status',
            'verb' => 'GET'
        ],
        [
            'name' => 'migration#dismissJob',
            'url' => '/migration/dismiss/{jobId}',
            'verb' => 'POST',
            'requirements' => ['jobId' => '\d+']
        ],
        [
            // v0.14.16 — user-initiated cancel of a job that is still
            // in the provider.tools queue (STATUS_PENDING). See
            // MigrationService::cancelJobForUser() for the semantics.
            'name' => 'migration#cancelJob',
            'url' => '/migration/cancel/{jobId}',
            'verb' => 'POST',
            'requirements' => ['jobId' => '\d+']
        ],
        [
            // v0.14.19 — user-facing "Postfach neu synchronisieren".
            // Server just records an audit trail; the actual sync
            // effect happens client-side (Snappymail localStorage
            // clear + full reload). See StalwartController.php.
            'name' => 'stalwart#resync',
            'url' => '/stalwart/resync',
            'verb' => 'POST',
        ],
        [
            // v0.14.37 — "Filter nachträglich anwenden" — pop the
            // folder list for the picker dropdown.
            'name' => 'sieveApply#folders',
            'url' => '/sieve/apply/folders',
            'verb' => 'GET',
        ],
        [
            // v0.14.37 — run the active Sieve script against
            // messages already in a target folder. Body:
            // { folderId, limit?, includeRedirect? }
            'name' => 'sieveApply#apply',
            'url' => '/sieve/apply',
            'verb' => 'POST',
        ],
        [
            // v0.15.0 — External accounts feature-status endpoint.
            // GET /apps/souvera_mail/external/status → { enabled, allowed_for_me,
            //   max_per_user, current_count, consent_required, consent_given }
            'name' => 'externalAccounts#status',
            'url' => '/external/status',
            'verb' => 'GET',
        ],
        [
            // v0.15.0 — Provider preset lookup. GET
            // /apps/souvera_mail/external/preset?email=me@web.de →
            //   { display, imap:{host,port,ssl}, pop3, smtp, warning, help_url }
            'name' => 'externalAccounts#preset',
            'url' => '/external/preset',
            'verb' => 'GET',
        ],
        [
            // v0.15.0 — Return the full provider directory (used by
            // the picker in the "Add external account" onboarding
            // card). GET /apps/souvera_mail/external/providers
            //   → { "web.de": "WEB.DE", "gmail.com": "Google Mail", … }
            'name' => 'externalAccounts#providers',
            'url' => '/external/providers',
            'verb' => 'GET',
        ],
        [
            // v0.15.0 — Record the user's GDPR consent (called by the
            // Vue modal on every account add when consent_required is
            // true). POST /apps/souvera_mail/external/consent
            //   Body: { email: "…@web.de" }
            'name' => 'externalAccounts#recordConsent',
            'url' => '/external/consent',
            'verb' => 'POST',
        ],
    ]
];
