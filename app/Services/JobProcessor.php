<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Notifications\NotificationService;
use Core\Database;
use Core\Logger;

/**
 * Processes queued background jobs.
 */
final class JobProcessor
{
    public function process(int $limit = 50): int
    {
        $jobs = Database::getInstance()->fetchAll(
            "SELECT * FROM jobs WHERE status = 'pending' AND (scheduled_at IS NULL OR scheduled_at <= NOW()) ORDER BY id ASC LIMIT ?",
            [$limit]
        );

        $processed = 0;
        foreach ($jobs as $job) {
            if ($this->processJob($job)) {
                $processed++;
            }
        }

        return $processed;
    }

    /** @param array<string, mixed> $job */
    private function processJob(array $job): bool
    {
        $db = Database::getInstance();
        $id = (int) $job['id'];
        $attempts = (int) $job['attempts'] + 1;

        $db->update('jobs', [
            'status' => 'processing',
            'started_at' => date('Y-m-d H:i:s'),
            'attempts' => $attempts,
        ], 'id = ?', [$id]);

        try {
            $payload = json_decode($job['payload'], true) ?? [];
            $this->handle($job['type'], $payload, (int) ($job['tenant_id'] ?? 1));

            $db->update('jobs', [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error' => null,
            ], 'id = ?', [$id]);

            \Core\EventDispatcher::dispatch('job.completed', $job);
            return true;
        } catch (\Throwable $e) {
            $maxAttempts = (int) ($job['max_attempts'] ?? 3);
            $status = $attempts >= $maxAttempts ? 'failed' : 'pending';

            $db->update('jobs', [
                'status' => $status,
                'error' => $e->getMessage(),
                'completed_at' => $status === 'failed' ? date('Y-m-d H:i:s') : null,
            ], 'id = ?', [$id]);

            Logger::error('Job failed', ['id' => $id, 'type' => $job['type'], 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    private function handle(string $type, array $payload, int $tenantId): void
    {
        match ($type) {
            'send_notification' => (new NotificationService())->notify(
                $payload['type'] ?? 'system',
                $payload['subject'] ?? 'MultiPanel',
                $payload['body'] ?? '',
                [$payload['channel'] ?? 'email'],
                $payload['data'] ?? [],
                $payload['user_id'] ?? null,
                $tenantId
            ),
            'sync_server' => $this->syncServer($payload),
            'run_automation' => (new AutomationEngine())->runAll($tenantId),
            'create_backup' => (new BackupService())->create($tenantId),
            default => throw new \RuntimeException("Unknown job type: {$type}"),
        };
    }

    /** @param array<string, mixed> $payload */
    private function syncServer(array $payload): void
    {
        $uuid = $payload['server_uuid'] ?? '';
        if ($uuid === '') {
            throw new \InvalidArgumentException('Missing server_uuid');
        }

        $row = Database::getInstance()->fetchOne('SELECT * FROM servers WHERE uuid = ?', [$uuid]);
        if (!$row) {
            throw new \RuntimeException('Server not found');
        }

        $server = new \App\Models\Server($row);
        (new ServerSyncService())->sync($server);
    }

    public static function dispatch(string $type, array $payload, int $tenantId = 1, ?\DateTimeInterface $scheduledAt = null): int
    {
        return (int) Database::getInstance()->insert('jobs', [
            'tenant_id' => $tenantId,
            'queue' => 'default',
            'type' => $type,
            'payload' => json_encode($payload),
            'status' => 'pending',
            'scheduled_at' => $scheduledAt?->format('Y-m-d H:i:s'),
        ]);
    }
}
