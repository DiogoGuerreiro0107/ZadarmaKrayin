<?php

return [
    [
        'key' => 'zadarma',
        'name' => 'zadarma::app.configuration.title',
        'info' => 'zadarma::app.configuration.info',
        'sort' => 10,
    ], [
        'key' => 'zadarma.settings',
        'name' => 'zadarma::app.configuration.settings.title',
        'info' => 'zadarma::app.configuration.settings.info',
        'icon' => 'icon-setting',
        'sort' => 1,
    ], [
        'key' => 'zadarma.settings.credentials',
        'name' => 'zadarma::app.configuration.settings.title',
        'info' => 'zadarma::app.configuration.settings.info',
        'sort' => 1,
        'fields' => [
            [
                'name' => 'active',
                'title' => 'zadarma::app.configuration.settings.active',
                'type' => 'boolean',
            ], [
                'name' => 'api_key',
                'title' => 'zadarma::app.configuration.settings.api-key',
                'type' => 'password',
                'depends' => 'active:1',
                'validation' => 'required_if:active,1',
            ], [
                'name' => 'api_secret',
                'title' => 'zadarma::app.configuration.settings.api-secret',
                'type' => 'password',
                'depends' => 'active:1',
                'validation' => 'required_if:active,1',
            ], [
                'name' => 'caller_extension',
                'title' => 'zadarma::app.configuration.settings.caller-extension',
                'type' => 'text',
                'depends' => 'active:1',
            ], [
                'name' => 'sync_mode',
                'title' => 'zadarma::app.configuration.settings.sync-mode',
                'type' => 'text',
                'default' => config('zadarma.sync_mode'),
                'info' => 'zadarma::app.configuration.settings.sync-mode-info',
            ],
        ],
    ],
];
