<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use App\Services\Media\ServerEndpoint;
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

    /** @var array<string, mixed>|null */
    private ?array $lastDebug = null;

    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
        private ServerConnectionDebugService $debugService = new ServerConnectionDebugService(),
    ) {
    }

    /** @return array<string, mixed>|null */
    public function lastDebug(): ?array
    {
        return $this->lastDebug;
    }

    public function sync(Server $server): bool
    {
        try {
            $media = MediaServerFactory::make($server);

            if (!$media->testConnection()) {
                $error = $media instanceof PlexService
                    ? ($media->getLastError() ?? 'Conexión fallida')
                    : 'Conexión fallida';

                return $this->failSync($server, $error);
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
            $this->recordActiveSessions($server, $sessions);
            $this->persistDebugLight($server, true);

            Logger::info('Server synced', ['server_id' => $server->id, 'users' => $userStats]);
            return true;
        } catch (\Throwable $e) {
            return $this->failSync($server, $e->getMessage(), 'error');
        }
    }

    /** Conexión + info + sesiones activas, sin importar bibliotecas/usuarios (rápido). */
    public function syncConnectionOnly(Server $server): bool
    {
        try {
            $media = MediaServerFactory::make($server);

            if (!$media->testConnection()) {
                $error = $media instanceof PlexService
                    ? ($media->getLastError() ?? 'Conexión fallida')
                    : 'Conexión fallida';

                return $this->failSync($server, $error);
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
            $server->last_check_at = now()->format('Y-m-d H:i:s');
            $server->last_error = null;
            if ($server->last_sync_at === null) {
                $server->last_sync_at = $server->last_check_at;
            }
            $server->save();

            $this->recordActiveSessions($server, $sessions);
            $this->persistDebugLight($server, true);

            return true;
        } catch (\Throwable $e) {
            return $this->failSync($server, $e->getMessage(), 'error');
        }
    }

    private function failSync(Server $server, string $error, string $status = 'offline'): bool
    {
        $server->status = $status;
        $server->last_error = $error;
        $server->last_check_at = now()->format('Y-m-d H:i:s');
        $this->refreshDbCounts($server);
        $this->persistDebugLight($server, false, $error);
        $server->save();

        Logger::error('Server sync failed', ['server_id' => $server->id, 'error' => $error]);
        return false;
    }

    private function persistDebugLight(Server $server, bool $connected, ?string $overrideError = null): void
    {
        $debug = [
            'checked_at' => now()->format('Y-m-d H:i:s'),
            'server_id' => (int) $server->id,
            'server_name' => (string) $server->name,
            'type' => (string) $server->type,
            'status' => (string) $server->status,
            'configured_url' => $server->fullUrl(),
            'machine_id' => (string) ($server->machine_id ?? ''),
            'has_token' => trim((string) ($server->token ?? '')) !== '',
            'connected' => $connected,
            'final_error' => $overrideError ?? $server->last_error,
            'lightweight' => true,
        ];
        $this->lastDebug = $debug;
        $this->debugService->persistDebug($server, $debug);
    }

    public function runFullDiagnose(Server $server): array
    {
        $debug = $this->debugService->diagnose($server);
        $this->lastDebug = $debug;

        if (!empty($debug['connected'])) {
            $this->applyWorkingEndpoint($server, $debug);
        }

        $this->debugService->persistDebug($server, $debug);
        return $debug;
    }

    /** @param array<string, mixed> $debug */
    public function applyWorkingEndpoint(Server $server, array $debug): void
    {
        $probeUrl = (string) ($debug['working_endpoint'] ?? '');

        if ($probeUrl === '') {
            foreach ($debug['probes'] ?? [] as $probe) {
                if (!empty($probe['ok']) && !empty($probe['url'])) {
                    $probeUrl = (string) $probe['url'];
                    break;
                }
            }
        }

        $endpoint = ServerEndpoint::fromProbeUrl($probeUrl);
        if ($endpoint === null) {
            return;
        }

        if (ServerEndpoint::shouldPreferCurrentHost((string) $server->url, $endpoint)) {
            $server->port = $endpoint['port'];
            $server->ssl = $endpoint['ssl'] ? 1 : 0;
        } else {
            $server->url = $endpoint['url'];
            $server->port = $endpoint['port'];
            $server->ssl = $endpoint['ssl'] ? 1 : 0;
        }

        $server->save();
    }

    public function touchOnline(Server $server, int $activeSessions = 0): void
    {
        $server->status = 'online';
        $server->active_sessions = $activeSessions;
        $server->last_check_at = now()->format('Y-m-d H:i:s');
        $server->last_error = null;
        if ($server->last_sync_at === null) {
            $server->last_sync_at = $server->last_check_at;
        }
        $server->save();
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

            // Autoaceptar: si había una invitación pendiente con este email (sin external_id aún),
            // se vincula en vez de crear un usuario duplicado; así se detecta la aceptación sin
            // intervención manual del administrador.
            $email = trim((string) ($remoteUser['email'] ?? ''));
            $pending = $email !== '' ? $db->fetchOne(
                "SELECT id FROM media_users
                 WHERE server_id = ? AND deleted_at IS NULL AND email = ?
                   AND (external_id IS NULL OR external_id = '')
                   AND status IN ('invited', 'pending')
                 LIMIT 1",
                [$server->id, $email]
            ) : null;

            if ($pending) {
                $db->update('media_users', [
                    'external_id' => $externalId,
                    'username' => $username,
                    'display_name' => $username,
                    'avatar' => $remoteUser['thumb'] ?? null,
                    'status' => 'active',
                ], 'id = ?', [$pending['id']]);
                Logger::info('Media user invite auto-accepted', ['media_user_id' => $pending['id'], 'server_id' => $server->id, 'email' => $email]);
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

    /** @param array<int, array<string, mixed>> $sessions */
    private function recordActiveSessions(Server $server, array $sessions): void
    {
        $db = Database::getInstance();
        $now = now()->format('Y-m-d H:i:s');
        $activeKeys = [];

        foreach ($sessions as $session) {
            $sessionKey = md5(
                (string) $server->id . '|'
                . ($session['user'] ?? '') . '|'
                . ($session['title'] ?? '') . '|'
                . ($session['player'] ?? '')
            );
            $activeKeys[] = $sessionKey;

            $existing = $db->fetchOne(
                'SELECT id FROM playback_sessions WHERE server_id = ? AND external_session_id = ? AND ended_at IS NULL LIMIT 1',
                [$server->id, $sessionKey]
            );

            $mediaUserId = null;
            $username = trim((string) ($session['user'] ?? ''));
            if ($username !== '') {
                $mediaUser = $db->fetchOne(
                    'SELECT id FROM media_users WHERE server_id = ? AND deleted_at IS NULL AND (username = ? OR display_name = ?) LIMIT 1',
                    [$server->id, $username, $username]
                );
                $mediaUserId = $mediaUser['id'] ?? null;
            }

            $payload = [
                'title' => $session['title'] ?? null,
                'media_type' => $session['media_type'] ?? null,
                'player' => $session['player'] ?? null,
                'device' => $session['platform'] ?? null,
                'quality' => $session['play_method'] ?? null,
            ];

            if ($existing) {
                $db->update('playback_sessions', $payload, 'id = ?', [$existing['id']]);
                continue;
            }

            $db->insert('playback_sessions', array_merge($payload, [
                'tenant_id' => $server->tenant_id,
                'server_id' => $server->id,
                'media_user_id' => $mediaUserId,
                'external_session_id' => $sessionKey,
                'started_at' => $now,
            ]));
        }

        if ($activeKeys === []) {
            $db->query(
                'UPDATE playback_sessions SET ended_at = ?, duration_seconds = TIMESTAMPDIFF(SECOND, started_at, ?)
                 WHERE server_id = ? AND ended_at IS NULL',
                [$now, $now, $server->id]
            );
            return;
        }

        $placeholders = implode(',', array_fill(0, count($activeKeys), '?'));
        $db->query(
            "UPDATE playback_sessions SET ended_at = ?, duration_seconds = TIMESTAMPDIFF(SECOND, started_at, ?)
             WHERE server_id = ? AND ended_at IS NULL AND external_session_id NOT IN ({$placeholders})",
            array_merge([$now, $now, $server->id], $activeKeys)
        );
    }

    public function refreshStaleServers(int $tenantId, int $limit = 10): int
    {
        $attempted = 0;
        foreach ($this->servers->allByTenant($tenantId) as $server) {
            if ($attempted >= $limit) {
                break;
            }

            if (!$this->needsRefresh($server)) {
                continue;
            }

            $this->sync($server);
            $attempted++;
        }

        return $attempted;
    }

    private function needsRefresh(Server $server): bool
    {
        if ($server->last_check_at === null) {
            return true;
        }

        $interval = max(1, (int) ($server->check_interval_minutes ?? 5));
        if ($server->status !== 'online') {
            $interval = max($interval, 5);
        }

        $checkedAt = strtotime((string) $server->last_check_at);

        return $checkedAt === false || $checkedAt < (time() - ($interval * 60));
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
