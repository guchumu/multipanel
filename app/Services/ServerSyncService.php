<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use Core\Database;
use Core\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Server synchronization and health check service.
 */
final class ServerSyncService
{
    /** @var array{imported: int, updated: int, total: int} */
    private array $lastUserSyncStats = ['imported' => 0, 'updated' => 0, 'total' => 0];

    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    public function sync(Server $server): bool
    {
        try {
            $media = MediaServerFactory::make($server);

            if (!$media->testConnection()) {
                $server->status = 'offline';
                $server->last_error = $media instanceof PlexService
                    ? ($media->getLastError() ?? 'Conexión fallida')
                    : 'Conexión fallida';
                $server->last_check_at = now()->format('Y-m-d H:i:s');
                $this->refreshDbCounts($server);
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
            $userStats = $this->syncUsers($server, $media);
            $this->lastUserSyncStats = $userStats;
            $this->recordStats($server);

            Logger::info('Server synced', ['server_id' => $server->id, 'users' => $userStats]);
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

    /** @return array{imported: int, updated: int, total: int} */
    private function syncUsers(Server $server, PlexService|JellyfinService $media): array
    {
        $db = Database::getInstance();
        $imported = 0;
        $updated = 0;

        foreach ($media->getUsers() as $remoteUser) {
            $externalId = (string) ($remoteUser['external_id'] ?? '');
            $username = trim((string) ($remoteUser['username'] ?? ''));

            if ($externalId === '' || $username === '') {
                continue;
            }

            $existing = $db->fetchOne(
                'SELECT id FROM media_users WHERE server_id = ? AND external_id = ? AND deleted_at IS NULL LIMIT 1',
                [$server->id, $externalId]
            );

            if ($existing) {
                $db->update('media_users', [
                    'username' => $username,
                    'email' => $remoteUser['email'] ?? null,
                    'display_name' => $username,
                    'avatar' => $remoteUser['thumb'] ?? null,
                ], 'id = ?', [$existing['id']]);
                $updated++;
                continue;
            }

            $db->insert('media_users', [
                'tenant_id' => $server->tenant_id,
                'uuid' => Uuid::uuid4()->toString(),
                'server_id' => $server->id,
                'external_id' => $externalId,
                'username' => $username,
                'email' => $remoteUser['email'] ?? null,
                'display_name' => $username,
                'avatar' => $remoteUser['thumb'] ?? null,
                'status' => 'active',
                'expires_at' => null,
            ]);
            $imported++;
        }

        $count = $db->fetchOne(
            'SELECT COUNT(*) AS total FROM media_users WHERE server_id = ? AND deleted_at IS NULL',
            [$server->id]
        );
        $server->total_users = (int) ($count['total'] ?? 0);
        $server->save();

        return ['imported' => $imported, 'updated' => $updated, 'total' => $server->total_users];
    }

    /** @return array{imported: int, updated: int, total: int} */
    public function lastUserSyncStats(): array
    {
        return $this->lastUserSyncStats;
    }

    public function refreshDbCounts(int|Server $server): void
    {
        if (!$server instanceof Server) {
            $server = Server::find($server);
            if ($server === null) {
                return;
            }
        }

        $db = Database::getInstance();
        $users = $db->fetchOne(
            'SELECT COUNT(*) AS total FROM media_users WHERE server_id = ? AND deleted_at IS NULL',
            [$server->id]
        );
        $libraries = $db->fetchOne(
            'SELECT COUNT(*) AS total FROM libraries WHERE server_id = ?',
            [$server->id]
        );

        $server->total_users = (int) ($users['total'] ?? 0);
        $server->total_libraries = (int) ($libraries['total'] ?? 0);
        $server->save();
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
