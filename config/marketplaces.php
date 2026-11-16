<?php

return [
    'auto_sync' => (bool) env('MARKETPLACE_AUTO_SYNC', false),
    'torob' => [
        'enabled' => (bool) env('TOROB_ENABLED', false),
        'fee_model' => env('TOROB_FEE_MODEL', 'cpc'),
        'commission_percent' => env('TOROB_COMMISSION_PERCENT'),
        'public_key' => env('TOROB_PUBLIC_KEY', base64_encode(substr(base64_decode('MCowBQYDK2VwAyEAt6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0='), -32))),
    ],
    'basalam' => [
        'enabled' => (bool) env('BASALAM_ENABLED', false),
        'access_token' => env('BASALAM_ACCESS_TOKEN'),
        'vendor_id' => env('BASALAM_VENDOR_ID'),
        'fee_model' => env('BASALAM_FEE_MODEL', 'commission'),
        'commission_percent' => env('BASALAM_COMMISSION_PERCENT'),
        'amount_multiplier' => (int) env('BASALAM_AMOUNT_MULTIPLIER', 10),
    ],
    'digikala' => [
        'enabled' => (bool) env('DIGIKALA_ENABLED', false),
        'access_token' => env('DIGIKALA_ACCESS_TOKEN'),
        'seller_id' => env('DIGIKALA_SELLER_ID'),
        'fee_model' => env('DIGIKALA_FEE_MODEL', 'commission'),
        'commission_percent' => env('DIGIKALA_COMMISSION_PERCENT'),
        // Official account-specific publishing contract is required; no guessed endpoints.
    ],
    // Optional integration gateway for any other marketplace. It must implement MARKETPLACES.md's contract.
    'custom' => [
        'enabled' => (bool) env('MARKETPLACE_CUSTOM_ENABLED', false),
        'name' => env('MARKETPLACE_CUSTOM_NAME', 'پلتفرم دیگر'),
        'url' => env('MARKETPLACE_CUSTOM_URL'),
        'token' => env('MARKETPLACE_CUSTOM_TOKEN'),
        'fee_model' => env('MARKETPLACE_CUSTOM_FEE_MODEL', 'unknown'),
        'commission_percent' => env('MARKETPLACE_CUSTOM_COMMISSION_PERCENT'),
    ],
];
