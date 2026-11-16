<?php

return [
    // APP_ENV=develop (also local/development/testing) never contacts live providers.
    'site_url' => env('SITE_URL', 'http://localhost:3000'),
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        // Storefront prices are in toman; Zarinpal receives rial.
        'amount_multiplier' => 10,
    ],
    'kavenegar' => [
        'api_key' => env('KAVENEGAR_API_KEY'),
        'sender' => env('KAVENEGAR_SENDER'),
    ],
];
