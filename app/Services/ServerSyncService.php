<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\Media\MediaServerFactory;
use Core\Database;
use Core\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Server synchronization and health check service.
 */
final class ServerSyncService
{
    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    public function sync(Server $server): bool
    {
        $db = Database::getInstance();

        try {
            $media = MediaServerFactory::make($server);

            if (!$media->testConnection()) {
                $server->status = 'offline';
                $server->last_check_at = now()->format('Y-m-d H:i:s');
                $server->save();
                return false;
            }

            $info = $media->getServerInfo();
            if ($info) {
                $server->machine_id = $info['machine_id'] ?? $server->machine_id;
                $server->version = $info['version'] ?? $server->version;
                $server->name = $info['name'] ?? $server->name;
            }

            $sessions = $media->getActiveSessions();
            $server->active_sessions = count($sessions);
            $server->status = 'online';
            $server->last_sync_at = now()->format('Y-m-d H:i:s');
            $server->last_check_at = now()->format('Y-m-d H:i:s');
            $server->last_error = null;
            $server->save();

            $this->syncLibraries($server, $media->getLibraries());
            $this->recordStats($server);

            Logger::info('Server synced', ['server_id' => $server->id]);
            return true;
        } catch (\Throwable $e) {
            $server->status = 'error';
            $server->last_error = $e->getMessage();
            $server->last_check_at = now()->format('Y-m-d H:i:s');
            $server->save();

            Logger::error('Server sync failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @param array<int, array<string, mixed>> $libraries */
    private function syncLibraries(Server $server, array $libraries): void
    {
        $db = Database::getInstance();

        foreach ($libraries as $lib) {
            $existing = $db->fetchOne(
                'SELECT id FROM libraries WHERE server_id = ? AND external_id = ?',
                [$server->id, $lib['external_id']]
            );

            if ($existing) {
                $db->update('libraries', [
                    'name' => $lib['name'],
                    'type' => $lib['type'],
                    'path' => $lib['path'],
                ], 'id = ?', [$existing['id']]);
            } else {
                $db->insert('libraries', [
                    'server_id' => $server->id,
                    'external_id' => $lib['external_id'],
                    'name' => $lib['name'],
                    'type' => $lib['type'],
                    'path' => $lib['path'],
                ]);
            }
        }

        $server->total_libraries = count($libraries);
        $server->save();
    }

    private function recordStats(Server $server): void
    {
        Database::getInstance()->insert('server_stats', [
            'server_id' => $server->id,
            'cpu_usage' => $server->cpu_usage,
            'ram_usage' => $server->ram_usage,
            'disk_usage' => $server->disk_usage,
            'bandwidth_mbps' => $server->bandwidth_mbps,
            'active_sessions' => $server->active_sessions,
            'online_users' => $server->active_sessions,
            'recorded_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function syncAll(int $tenantId): int
    {
        $synced = 0;
        foreach ($this->servers->allByTenant($tenantId) as $server) {
            if ($this->sync($server)) {
                $synced++;
            }
        }
        return $synced;
    }
}
