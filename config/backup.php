<?php

declare(strict_types=1);

return [
    'path' => env('BACKUP_PATH', 'storage/backups'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
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
