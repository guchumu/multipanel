<?php

declare(strict_types=1);

return [
    'secret' => env('JWT_SECRET', env('APP_KEY', '')),
    'ttl' => (int) env('JWT_TTL', 3600),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 604800),
    'algorithm' => 'HS256',
];
