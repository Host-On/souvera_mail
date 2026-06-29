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
    ]
];
