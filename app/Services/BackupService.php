<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;
use GuzzleHttp\Client;

/**
 * Database backup service with local storage and optional remote upload.
 */
final class BackupService
{
    public function create(int $tenantId = 1): ?array
    {
        $path = storage_path('backups');
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filename = 'backup_' . date('Y-m-d_His') . '.sql';
        $fullPath = $path . '/' . $filename;

        $db = config('database.database');
        $host = config('database.host');
        $user = config('database.username');
        $pass = config('database.password');

        $passArg = $pass ? '-p' . escapeshellarg((string) $pass) : '';
        $cmd = sprintf(
            'mysqldump -h%s -u%s %s %s > %s 2>&1',
            escapeshellarg((string) $host),
            escapeshellarg((string) $user),
            $passArg,
            escapeshellarg((string) $db),
            escapeshellarg($fullPath)
        );

        exec($cmd, $output, $code);

        if ($code !== 0 || !file_exists($fullPath)) {
            Logger::error('Backup failed', ['output' => implode("\n", $output)]);
            return null;
        }

        $size = filesize($fullPath) ?: 0;
        $remotePath = null;

        if (config('backup.remote.enabled', false)) {
            $remotePath = $this->uploadRemote($fullPath, $filename);
        }

        $id = Database::getInstance()->insert('backups', [
            'tenant_id' => $tenantId,
            'filename' => $filename,
            'path' => $fullPath,
            'size_bytes' => $size,
            'type' => 'database',
            'status' => 'completed',
            'remote_path' => $remotePath,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        \Core\EventDispatcher::dispatch('backup.created', [
            'id' => $id,
            'filename' => $filename,
            'path' => $fullPath,
            'remote_path' => $remotePath,
        ]);

        return Database::getInstance()->fetchOne('SELECT * FROM backups WHERE id = ?', [$id]);
    }

    public function createIncremental(int $tenantId = 1): ?array
    {
        $last = Database::getInstance()->fetchOne(
            "SELECT * FROM backups WHERE tenant_id = ? AND status = 'completed' AND type IN ('database','full') ORDER BY created_at DESC LIMIT 1",
            [$tenantId]
        );

        $path = storage_path('backups');
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filename = 'incremental_' . date('Y-m-d_His') . '.sql';
        $fullPath = $path . '/' . $filename;

        $db = config('database.database');
        $host = config('database.host');
        $user = config('database.username');
        $pass = config('database.password');
        $passArg = $pass ? '-p' . escapeshellarg((string) $pass) : '';

        $where = '';
        if ($last && !empty($last['completed_at'])) {
            $since = escapeshellarg($last['completed_at']);
            $where = "--incremental since {$since}\n";
            file_put_contents($fullPath, $where);
            $tables = ['audit_logs', 'notifications', 'jobs', 'webhook_deliveries', 'server_stats'];
            foreach ($tables as $table) {
                $cmd = sprintf(
                    'mysqldump -h%s -u%s %s %s --no-create-info --where=%s %s >> %s 2>&1',
                    escapeshellarg((string) $host),
                    escapeshellarg((string) $user),
                    $passArg,
                    escapeshellarg((string) $db),
                    escapeshellarg("created_at >= '{$last['completed_at']}'"),
                    escapeshellarg($table),
                    escapeshellarg($fullPath)
                );
                exec($cmd);
            }
        } else {
            return $this->create($tenantId);
        }

        if (!file_exists($fullPath) || filesize($fullPath) === 0) {
            return null;
        }

        $size = filesize($fullPath) ?: 0;
        $id = Database::getInstance()->insert('backups', [
            'tenant_id' => $tenantId,
            'filename' => $filename,
            'path' => $fullPath,
            'size_bytes' => $size,
            'type' => 'incremental',
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        return Database::getInstance()->fetchOne('SELECT * FROM backups WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId = 1, int $limit = 50): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM backups WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ?',
            [$tenantId, $limit]
        );
    }

    public function delete(int $id): bool
    {
        $backup = Database::getInstance()->fetchOne('SELECT * FROM backups WHERE id = ?', [$id]);
        if (!$backup) {
            return false;
        }

        if (file_exists($backup['path'])) {
            unlink($backup['path']);
        }

        Database::getInstance()->query('DELETE FROM backups WHERE id = ?', [$id]);
        return true;
    }

    public function prune(int $tenantId = 1): int
    {
        $days = (int) config('backup.retention_days', 30);
        $old = Database::getInstance()->fetchAll(
            'SELECT * FROM backups WHERE tenant_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$tenantId, $days]
        );

        $count = 0;
        foreach ($old as $backup) {
            if ($this->delete((int) $backup['id'])) {
                $count++;
            }
        }

        return $count;
    }

    private function uploadRemote(string $filePath, string $filename): ?string
    {
        $driver = config('backup.remote.driver', 'webhook');

        return match ($driver) {
            's3' => $this->uploadS3($filePath, $filename),
            default => $this->uploadWebhook($filePath, $filename),
        };
    }

    private function uploadWebhook(string $filePath, string $filename): ?string
    {
        $url = config('backup.remote.webhook_url', '');
        if ($url === '') {
            return null;
        }

        $client = new Client(['timeout' => 120]);
        $response = $client->post($url, [
            'multipart' => [
                ['name' => 'file', 'contents' => fopen($filePath, 'r'), 'filename' => $filename],
                ['name' => 'source', 'contents' => 'multipanel'],
            ],
        ]);

        return $response->getStatusCode() < 300 ? $url . '/' . $filename : null;
    }

    private function uploadS3(string $filePath, string $filename): ?string
    {
        $s3 = config('backup.remote.s3', []);
        $endpoint = rtrim((string) ($s3['endpoint'] ?? ''), '/');
        $bucket = (string) ($s3['bucket'] ?? '');
        $prefix = (string) ($s3['prefix'] ?? '');
        $key = (string) ($s3['key'] ?? '');
        $secret = (string) ($s3['secret'] ?? '');

        if ($endpoint === '' || $bucket === '' || $key === '') {
            return null;
        }

        $objectKey = $prefix . $filename;
        $url = "{$endpoint}/{$bucket}/{$objectKey}";
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        $region = (string) ($s3['region'] ?? 'us-east-1');
        $payloadHash = hash('sha256', $content);
        $host = parse_url($endpoint, PHP_URL_HOST) ?: $endpoint;

        $canonical = "PUT\n/{$bucket}/{$objectKey}\n\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$date}\n\nhost;x-amz-content-sha256;x-amz-date\n{$payloadHash}";
        $scope = "{$shortDate}/{$region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n" . hash('sha256', $canonical);
        $signingKey = $this->awsSigningKey($secret, $shortDate, $region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $auth = "AWS4-HMAC-SHA256 Credential={$key}/{$scope}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$signature}";

        $client = new Client(['timeout' => 120]);
        $response = $client->put($url, [
            'headers' => [
                'Authorization' => $auth,
                'x-amz-content-sha256' => $payloadHash,
                'x-amz-date' => $date,
                'Content-Type' => 'application/sql',
            ],
            'body' => $content,
        ]);

        return $response->getStatusCode() < 300 ? $url : null;
    }

    private function awsSigningKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
