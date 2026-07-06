<?php

return [
    'sync_mode' => env('ZADARMA_SYNC_MODE', 'polling'),

    'api_base_url' => env('ZADARMA_API_BASE_URL', 'https://api.zadarma.com'),

    // PBX outbound routing prefix dialed before the destination number so
    // the call goes out showing the company's main caller ID (mirrors what
    // the team already does manually when dialing from the mobile app).
    'outbound_prefix' => env('ZADARMA_OUTBOUND_PREFIX', '0001'),
];
