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
            // v0.18.0 — Native-client Login-Flow endpoint.
            //
            // Bit-compatible JSON with NC's /login/v2/poll (`server`,
            // `loginName`, `appPassword`) so Souvera-Android/iOS/Desktop
            // clients can drop-in replace the stock Nextcloud login-flow
            // with a single POST and get a credential that unlocks BOTH
            // mail (IMAP/SMTP/Sieve, Stalwart) AND Nextcloud/DAV
            // (WebDAV/CalDAV/CardDAV, NC auth-token) — because Stalwart
            // 0.16 refuses caller-supplied secrets, this endpoint is the
            // ONLY way for a headless client to receive a single paired
            // credential without going through Souvera Mail's web UI.
            //
            // See docs/LOGIN_FLOW_CLIENT_INTEGRATION.txt for the client
            // integration guide (Android / iOS / Desktop examples).
            'name' => 'loginFlow#create',
            'url' => '/app-passwords/login-flow',
            'verb' => 'POST'
        ],
        [
            // v0.18.2 — Post-Login Upgrade endpoint.
            //
            // Client obtained an NC-only app-password `X` via NC's stock
            // `/login/v2/*` flow (SSO / OIDC / Basic-Auth — all three
            // work). Now it wants a paired mail+DAV credential `Y`
            // AND wants X to be cleaned up atomically so it does not
            // linger in the connected-devices list.
            //
            // Auth MUST be Basic-Auth with X — we read PHP_AUTH_PW to
            // identify which NC token to invalidate after Y is created.
            //
            // See docs/CLIENT_UPGRADE_PATTERN.txt for the client-agent
            // playbook (why this exists, when to prefer it over
            // /login-flow, exact Kotlin / Swift / Rust examples).
            'name' => 'loginFlow#upgrade',
            'url' => '/app-passwords/upgrade',
            'verb' => 'POST'
        ],
        [
            // v0.18.1 — Identity + server-hint endpoint.
            //
            // Returns { uid, loginName, displayName, email, server,
            // rotation: {enabled, days, hint}, serverTime }.
            //
            // Used by native clients right after the login-flow call to:
            //   - resolve the SASL user for IMAP/SMTP (may differ from
            //     `loginName`);
            //   - display "Signed in as Philip Grassegger" in the UI;
            //   - schedule automatic password rotation according to the
            //     server-side `rotation_days` app config (see
            //     docs/PASSWORD_ROTATION.txt for the admin playbook).
            'name' => 'me#show',
            'url' => '/me',
            'verb' => 'GET'
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
            // Out-of-office / "Abwesenheitsnotiz" — read current state.
            // GET /apps/souvera_mail/vacation
            //   → { status, available, vacation:{enabled,subject,message,from,to} }
            'name' => 'vacation#index',
            'url' => '/vacation',
            'verb' => 'GET',
        ],
        [
            // Out-of-office — save/enable/disable. POST /apps/souvera_mail/vacation
            //   Body: { enabled, subject, message, from?, to? }
            'name' => 'vacation#save',
            'url' => '/vacation',
            'verb' => 'POST',
        ],
        [
            // Out-of-office — standalone user-friendly form (no webmail needed).
            // GET /apps/souvera_mail/vacation/form → HTML page
            'name' => 'vacation#form',
            'url' => '/vacation/form',
            'verb' => 'GET',
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
        [
            // v0.19.0 — Android FCM push: register/list the current
            // user's device tokens. GET /apps/souvera_mail/devices
            //   → { status, items:[{id,platform,createdAt,lastSeenAt}] }
            'name' => 'deviceToken#index',
            'url' => '/devices',
            'verb' => 'GET',
        ],
        [
            // POST /apps/souvera_mail/devices  Body: {fcmToken, platform}
            //   → { status, id }
            'name' => 'deviceToken#register',
            'url' => '/devices',
            'verb' => 'POST',
        ],
        [
            'name' => 'deviceToken#unregister',
            'url' => '/devices/{id}',
            'verb' => 'DELETE',
            'requirements' => ['id' => '\d+'],
        ],
        [
            // v0.19.0 — Stalwart new-mail webhook (server-to-server,
            // shared-secret auth). See StalwartWebhookController.php for
            // the full contract.
            'name' => 'stalwartWebhook#push',
            'url' => '/webhooks/stalwart',
            'verb' => 'POST',
        ],

        // v2 API — pure JMAP, no IMAP/SnappyMail
        [
            'name' => 'v2_mailbox#list',
            'url' => '/api/v2/mailboxes',
            'verb' => 'GET',
        ],
        [
            'name' => 'v2_mailbox#emails',
            'url' => '/api/v2/emails',
            'verb' => 'GET',
        ],
        [
            'name' => 'v2_mailbox#detail',
            'url' => '/api/v2/emails/{id}',
            'verb' => 'GET',
        ],
        [
            'name' => 'v2_mailbox#markRead',
            'url' => '/api/v2/emails/{id}/read',
            'verb' => 'POST',
        ],
    ],
];
