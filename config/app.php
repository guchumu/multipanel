<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'MultiPanel'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => rtrim(env('APP_URL', 'http://localhost'), '/'),
    'key' => env('APP_KEY', ''),
    'timezone' => env('APP_TIMEZONE', 'Europe/Madrid'),
    'locale' => env('APP_LOCALE', 'es'),
    'version' => '1.1.0',
];
