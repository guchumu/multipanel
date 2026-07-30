<?php

declare(strict_types=1);

return [
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
    ],
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID', ''),
        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
        'sandbox' => env('PAYPAL_SANDBOX', true),
    ],
    'bizum' => [
        'phone' => env('BIZUM_PHONE', ''),
    ],
    'crypto' => [
        'wallet' => env('CRYPTO_WALLET_ADDRESS', ''),
        'network' => env('CRYPTO_NETWORK', 'BTC'),
    ],
];
