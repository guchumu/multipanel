<?php

declare(strict_types=1);

return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'secure' => env('SESSION_SECURE', false),
];
