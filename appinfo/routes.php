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
    ]
];
