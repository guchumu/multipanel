<?php

declare(strict_types=1);

return [
    'path' => env('BACKUP_PATH', 'storage/backups'),
    // Cada cuántas horas puede crear uno el cron `all` (no en cada tick de 5 min).
    'interval_hours' => (int) env('BACKUP_INTERVAL_HOURS', 6),
    // Conservar los N más recientes (~28 × 6h ≈ 7 días).
    'retention_count' => (int) env('BACKUP_RETENTION_COUNT', 28),
    // Red de seguridad por antigüedad (días); 0 = desactivar.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 8),
    'remote' => [
        'enabled' => env('BACKUP_REMOTE_ENABLED', false),
        'driver' => env('BACKUP_REMOTE_DRIVER', 'webhook'),
        'webhook_url' => env('BACKUP_WEBHOOK_URL', ''),
        's3' => [
            'endpoint' => env('BACKUP_S3_ENDPOINT', ''),
            'bucket' => env('BACKUP_S3_BUCKET', ''),
            'region' => env('BACKUP_S3_REGION', 'us-east-1'),
            'key' => env('BACKUP_S3_KEY', ''),
            'secret' => env('BACKUP_S3_SECRET', ''),
            'prefix' => env('BACKUP_S3_PREFIX', 'multipanel/'),
        ],
    ],
];
