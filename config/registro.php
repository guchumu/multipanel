<?php

declare(strict_types=1);

return [
    /** Max registration requests per Telegram ID in the rolling window */
    'max_requests_per_client' => (int) env('REGISTRO_MAX_REQUESTS_CLIENT', 3),
    /** Max registration requests per email in the rolling window */
    'max_requests_per_email' => (int) env('REGISTRO_MAX_REQUESTS_EMAIL', 3),
    /** Rolling window in hours */
    'rate_limit_hours' => (int) env('REGISTRO_RATE_LIMIT_HOURS', 24),
];
