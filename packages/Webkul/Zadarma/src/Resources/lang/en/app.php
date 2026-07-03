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
];
