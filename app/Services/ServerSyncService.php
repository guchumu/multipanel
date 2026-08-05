<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use App\Services\Media\ServerEndpoint;
use Core\Cache;
use Core\Database;
use Core\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Server synchronization and health check service.
 */
final class ServerSyncService
{
    /** @var array{imported: int, updated: int, missing: int, restored: int, total: int, warning: ?string} */
    private array $lastUserSyncStats = [
        'imported' => 0,
        'updated' => 0,
        'missing' => 0,
        'restored' => 0,
        'total' => 0,
        'warning' => null,
    ];

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
            $this->forgetSoftSyncCache((int) $server->tenant_id);

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

    /**
     * Reconsulta la lista real de usuarios del servidor (Plex/Jellyfin), importa/actualiza
     * y marca quién sigue en la biblioteca (`on_server`) sin borrar filas del panel.
     *
     * @return array{imported: int, updated: int, missing: int, restored: int, total: int, warning: ?string}
     */
    private function syncUsers(Server $server, PlexService|JellyfinService $media): array
    {
        $db = Database::getInstance();
        $this->ensureMembershipColumns();
        $imported = 0;
        $updated = 0;
        $restored = 0;
        $seenExternalIds = [];
        $now = now()->format('Y-m-d H:i:s');
        $hasMembershipCols = $this->hasMembershipColumns();

        foreach ($media->getUsers() as $remoteUser) {
            $externalId = (string) ($remoteUser['external_id'] ?? '');
            $username = trim((string) ($remoteUser['username'] ?? ''));

            if ($externalId === '' || $username === '') {
                continue;
            }

            $seenExternalIds[$externalId] = true;

            $existing = $db->fetchOne(
                $hasMembershipCols
                    ? 'SELECT id, on_server, email, display_name, avatar FROM media_users WHERE server_id = ? AND external_id = ? AND deleted_at IS NULL LIMIT 1'
                    : 'SELECT id, email, display_name, avatar FROM media_users WHERE server_id = ? AND external_id = ? AND deleted_at IS NULL LIMIT 1',
                [$server->id, $externalId]
            );

            if ($existing) {
                // Nunca pisar email/display_name/avatar/expires/telegram locales con
                // valores vacíos de la API (Jellyfin no devuelve email → antes lo
                // dejaba a NULL en cada "Forzar sincronización").
                $payload = self::mergeRemoteIntoLocalUser($remoteUser, $existing, $username);
                if ($hasMembershipCols) {
                    if ((int) ($existing['on_server'] ?? -1) === 0) {
                        $restored++;
                    }
                    $payload['on_server'] = 1;
                    $payload['membership_synced_at'] = $now;
                }
                if ($payload !== []) {
                    $db->update('media_users', $payload, 'id = ?', [$existing['id']]);
                }
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
                $pendingRow = $db->fetchOne(
                    'SELECT id, email, display_name, avatar FROM media_users WHERE id = ? LIMIT 1',
                    [$pending['id']]
                ) ?: ['id' => $pending['id'], 'email' => $email, 'display_name' => null, 'avatar' => null];
                $payload = array_merge(
                    [
                        'external_id' => $externalId,
                        'status' => 'active',
                    ],
                    self::mergeRemoteIntoLocalUser($remoteUser, $pendingRow, $username)
                );
                if ($hasMembershipCols) {
                    $payload['on_server'] = 1;
                    $payload['membership_synced_at'] = $now;
                }
                $db->update('media_users', $payload, 'id = ?', [$pending['id']]);
                Logger::info('Media user invite auto-accepted', ['media_user_id' => $pending['id'], 'server_id' => $server->id, 'email' => $email]);
                $updated++;
                continue;
            }

            $remoteEmail = trim((string) ($remoteUser['email'] ?? ''));
            $insert = [
                'tenant_id' => $server->tenant_id,
                'uuid' => Uuid::uuid4()->toString(),
                'server_id' => $server->id,
                'external_id' => $externalId,
                'username' => $username,
                'email' => $remoteEmail !== '' ? $remoteEmail : null,
                'display_name' => $username,
                'avatar' => $remoteUser['thumb'] ?? null,
                'status' => 'active',
                'expires_at' => null,
            ];
            if ($hasMembershipCols) {
                $insert['on_server'] = 1;
                $insert['membership_synced_at'] = $now;
            }
            $db->insert('media_users', $insert);
            $imported++;
        }

        $missing = 0;
        $warning = null;

        // Lista vacía es ambigua (API fallida vs servidor sin shares): no marcar ausentes.
        if ($hasMembershipCols && $seenExternalIds !== []) {
            $placeholders = implode(',', array_fill(0, count($seenExternalIds), '?'));
            $params = array_merge([$now, $server->id], array_keys($seenExternalIds));
            $db->query(
                "UPDATE media_users
                 SET on_server = 0, membership_synced_at = ?
                 WHERE server_id = ?
                   AND deleted_at IS NULL
                   AND external_id IS NOT NULL AND external_id != ''
                   AND external_id NOT IN ({$placeholders})
                   AND (on_server IS NULL OR on_server = 1)",
                $params
            );

            $missingRow = $db->fetchOne(
                'SELECT COUNT(*) AS total FROM media_users
                 WHERE server_id = ? AND deleted_at IS NULL AND on_server = 0
                   AND external_id IS NOT NULL AND external_id != \'\'',
                [$server->id]
            );
            $missing = (int) ($missingRow['total'] ?? 0);
        } elseif ($hasMembershipCols && $seenExternalIds === []) {
            $warning = 'El servidor no devolvió usuarios: no se marcaron ausentes (evita falsos positivos).';
        }

        $count = $db->fetchOne(
            'SELECT COUNT(*) AS total FROM media_users WHERE server_id = ? AND deleted_at IS NULL',
            [$server->id]
        );
        $server->total_users = (int) ($count['total'] ?? 0);
        $server->save();

        return [
            'imported' => $imported,
            'updated' => $updated,
            'missing' => $missing,
            'restored' => $restored,
            'total' => $server->total_users,
            'warning' => $warning,
        ];
    }

    /** @return array{imported: int, updated: int, missing: int, restored: int, total: int, warning: ?string} */
    public function lastUserSyncStats(): array
    {
        return $this->lastUserSyncStats;
    }

    /**
     * Force-sync membership for a single media user against their server user list.
     *
     * @return array{success: bool, on_server: ?bool, message: string, users?: array<string, mixed>}
     */
    public function syncMediaUserMembership(\App\Models\MediaUser $user): array
    {
        if (!$user->server_id) {
            return ['success' => false, 'on_server' => null, 'message' => 'El usuario no tiene servidor asignado.'];
        }

        $server = Server::find((int) $user->server_id);
        if ($server === null) {
            return ['success' => false, 'on_server' => null, 'message' => 'Servidor no encontrado.'];
        }

        $ok = $this->sync($server);
        $stats = $this->lastUserSyncStats;
        if (!$ok) {
            return [
                'success' => false,
                'on_server' => null,
                'message' => 'Sync fallido: ' . ($server->last_error ?? 'no se pudo conectar al servidor.'),
                'users' => $stats,
            ];
        }

        $fresh = Database::getInstance()->fetchOne(
            'SELECT on_server, membership_synced_at, status, external_id FROM media_users WHERE id = ? LIMIT 1',
            [(int) $user->id]
        );
        $onServer = isset($fresh['on_server']) ? ((int) $fresh['on_server'] === 1) : null;
        if ($fresh && ($fresh['external_id'] ?? '') === '') {
            $onServer = false;
            $label = 'aún no está en el servidor (invitación pendiente o sin external_id)';
        } else {
            $label = $onServer === true
                ? 'está en la biblioteca / lista del servidor'
                : ($onServer === false ? 'NO está en el servidor' : 'estado desconocido');
        }

        return [
            'success' => true,
            'on_server' => $onServer,
            'message' => sprintf(
                'Sincronización forzada: el usuario %s. (%d nuevos, %d actualizados, %d ausentes, %d restaurados)',
                $label,
                $stats['imported'],
                $stats['updated'],
                $stats['missing'],
                $stats['restored']
            ),
            'users' => $stats,
            'membership_synced_at' => $fresh['membership_synced_at'] ?? null,
            'status' => $fresh['status'] ?? $user->status,
        ];
    }

    private function forgetSoftSyncCache(int $tenantId): void
    {
        Cache::forget('stats_snapshot_synced_' . $tenantId);
    }

    private function ensureMembershipColumns(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if (!$this->hasMembershipColumns()) {
            try {
                (new \Core\Updater())->runMigrations();
            } catch (\Throwable) {
                // Fall through to direct ALTER.
            }
        }

        if (!$this->hasMembershipColumns()) {
            try {
                Database::getInstance()->pdo()->exec(
                    'ALTER TABLE `media_users`
                     ADD COLUMN `on_server` TINYINT(1) NULL DEFAULT NULL AFTER `external_id`,
                     ADD COLUMN `membership_synced_at` DATETIME NULL AFTER `on_server`'
                );
            } catch (\Throwable $e) {
                if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                    Logger::warning('Could not add media_users membership columns', ['error' => $e->getMessage()]);
                }
            }
        }

        $ensured = true;
    }

    private function hasMembershipColumns(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS total
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                ['media_users', 'on_server']
            );
            $cached = ((int) ($row['total'] ?? 0)) > 0;
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
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
        $aggregate = [
            'imported' => 0,
            'updated' => 0,
            'missing' => 0,
            'restored' => 0,
            'total' => 0,
            'warning' => null,
        ];

        foreach ($this->servers->allByTenant($tenantId) as $server) {
            if ($this->sync($server)) {
                $synced++;
                $stats = $this->lastUserSyncStats;
                $aggregate['imported'] += (int) ($stats['imported'] ?? 0);
                $aggregate['updated'] += (int) ($stats['updated'] ?? 0);
                $aggregate['missing'] += (int) ($stats['missing'] ?? 0);
                $aggregate['restored'] += (int) ($stats['restored'] ?? 0);
                $aggregate['total'] += (int) ($stats['total'] ?? 0);
                if (!empty($stats['warning'])) {
                    $aggregate['warning'] = (string) $stats['warning'];
                }
            }
        }

        $this->lastUserSyncStats = $aggregate;
        $this->forgetSoftSyncCache($tenantId);

        return $synced;
    }

    /**
     * Campos seguros para UPDATE al sincronizar con Plex/Jellyfin.
     * Solo escribe email/avatar cuando la API trae un valor real; no toca
     * expires_at ni telegram_chat_id (datos del panel).
     *
     * @param array<string, mixed> $remoteUser
     * @param array<string, mixed> $localUser
     * @return array<string, mixed>
     */
    public static function mergeRemoteIntoLocalUser(array $remoteUser, array $localUser, string $username): array
    {
        $payload = ['username' => $username];

        $remoteEmail = trim((string) ($remoteUser['email'] ?? ''));
        if ($remoteEmail !== '') {
            $payload['email'] = $remoteEmail;
        }

        $localDisplay = trim((string) ($localUser['display_name'] ?? ''));
        if ($localDisplay === '') {
            $payload['display_name'] = $username;
        }

        $remoteThumb = $remoteUser['thumb'] ?? null;
        if (is_string($remoteThumb) && trim($remoteThumb) !== '') {
            $payload['avatar'] = trim($remoteThumb);
        }

        return $payload;
    }
}
