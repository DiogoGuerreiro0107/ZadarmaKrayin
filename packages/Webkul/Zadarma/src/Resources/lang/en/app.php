<?php

return [
    'configuration' => [
        'title' => 'Zadarma',
        'info' => 'Configure the Zadarma telephony integration.',
        'settings' => [
            'title' => 'Settings',
            'info' => 'API credentials and call sync settings.',
            'active' => 'Enable Zadarma integration',
            'api-key' => 'API Key',
            'api-secret' => 'API Secret',
            'caller-extension' => 'Caller Extension',
            'sync-mode' => 'Sync Mode',
            'sync-mode-info' => 'Set via the ZADARMA_SYNC_MODE environment variable (webhook or polling). This field is informational only.',
        ],
    ],

    'call' => [
        'button-title' => 'Call :number',
        'confirm' => 'Call :number now?',
        'requested' => 'Call requested — your extension will ring first.',
        'not-active' => 'The Zadarma integration is not active.',
        'failed' => 'Failed to place the call.',
    ],

    'history' => [
        'title' => 'Call History',
        'recording' => 'Download recording',
    ],

    'my-extension' => [
        'title' => 'Zadarma Extension',
        'info' => 'Set your own extension to use it instead of the shared one when you place a call.',
        'label' => 'My Extension',
        'placeholder' => 'e.g. 110',
        'save-btn' => 'Save',
        'saved' => 'Extension saved.',
        'cleared' => 'Extension cleared — the shared extension will be used instead.',
        'prefix-label' => 'My Outbound Prefix',
        'prefix-placeholder' => 'e.g. 0001',
        'prefix-info' => 'Prepended to the number before placing a call. Leave blank to use the app-wide default.',
    ],

    'reports' => [
        'title' => 'Zadarma Reports',
        'filter-user' => 'User',
        'all-users' => 'All users',
        'calls-per-day' => 'Calls per day',
        'duration-per-day' => 'Call duration per day (minutes)',
        'inbound' => 'Inbound',
        'outbound' => 'Outbound',
        'unknown-direction' => 'Unknown direction',
    ],
];
