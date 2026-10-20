<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use Core\Database;
use Core\Logger;

/**
 * Corta el acceso en Plex/Jellyfin a usuarios caducados o suspendidos,
 * y suspende fichas que el sync marcó fuera del servidor (on_server=0).
 * Se ejecuta en el cron (automation / all) para alinear servidor con la BD.
 */
final class MediaUserAccessEnforcementService
{
    public function __construct(
        private MediaUserManagementService $management = new MediaUserManagementService(),
    ) {
    }

    /**
     * @return array{
     *   checked: int,
     *   marked_expired: int,
     *   revoked: int,
     *   skipped: int,
     *   errors: int,
     *   off_server_suspended?: int,
     *   off_server_skipped?: int,
     *   off_server_errors?: int,
     *   disabled?: bool
     * }
     */
    public function run(int $tenantId = 1): array
    {
        $stats = [
            'checked' => 0,
            'marked_expired' => 0,
            'revoked' => 0,
            'skipped' => 0,
            'errors' => 0,
            'off_server_suspended' => 0,
            'off_server_skipped' => 0,
            'off_server_errors' => 0,
        ];

        if ((bool) config('expiry_notifications.deactivate_on_expiry', true)) {
            $rows = Database::getInstance()->fetchAll(
                'SELECT * FROM media_users
                 WHERE tenant_id = ?
                   AND deleted_at IS NULL
                   AND server_id IS NOT NULL
                   AND (
                     status IN (\'expired\', \'suspended\')
                     OR (
                       expires_at IS NOT NULL
                       AND DATE(expires_at) < CURDATE()
                       AND status IN (\'active\', \'invited\')
                     )
                   )',
                [$tenantId]
            );

            foreach ($rows as $row) {
                $stats['checked']++;
                $user = new MediaUser($row);

                if ($user->isExpired() && in_array((string) $user->status, ['active', 'invited'], true)) {
                    $user->status = 'expired';
                    $user->save();
                    $stats['marked_expired']++;
                    Logger::info('Media user marked expired by access enforcement', [
                        'media_user_id' => $user->id,
                        'username' => $user->username,
                    ]);
                }

                $result = $this->management->revokeServerAccess($user);
                if (!empty($result['skipped'])) {
                    $stats['skipped']++;
                    continue;
                }
                if (!empty($result['ok'])) {
                    $stats['revoked']++;
                    continue;
                }

                $stats['errors']++;
                Logger::warning('Could not revoke server access for expired/suspended user', [
                    'media_user_id' => $user->id,
                    'username' => $user->username,
                    'message' => $result['message'] ?? null,
                ]);
            }
        } else {
            $stats['disabled'] = true;
        }

        $off = $this->suspendMissingOnServer($tenantId);
        $stats['off_server_suspended'] = $off['suspended'];
        $stats['off_server_skipped'] = $off['skipped'];
        $stats['off_server_errors'] = $off['errors'];
        if (!empty($off['disabled'])) {
            $stats['off_server_disabled'] = true;
        }

        return $stats;
    }

    /**
     * Suspende usuarios active/invited que el sync marcó como ausentes del servidor.
     *
     * @return array{checked: int, suspended: int, skipped: int, errors: int, disabled?: bool}
     */
    public function suspendMissingOnServer(int $tenantId = 1, ?int $serverId = null, bool $force = false): array
    {
        if (!$force && !(bool) config('expiry_notifications.suspend_off_server', true)) {
            return ['checked' => 0, 'suspended' => 0, 'skipped' => 0, 'errors' => 0, 'disabled' => true];
        }

        $stats = ['checked' => 0, 'suspended' => 0, 'skipped' => 0, 'errors' => 0];

        $sql = 'SELECT * FROM media_users
             WHERE tenant_id = ?
               AND deleted_at IS NULL
               AND on_server = 0
               AND status IN (\'active\', \'invited\')';
        $params = [$tenantId];
        if ($serverId !== null && $serverId > 0) {
            $sql .= ' AND server_id = ?';
            $params[] = $serverId;
        }
        $rows = Database::getInstance()->fetchAll($sql, $params);

        foreach ($rows as $row) {
            $stats['checked']++;
            $user = new MediaUser($row);
            $result = $this->management->suspend($user);
            if (!empty($result['success'])) {
                $stats['suspended']++;
                Logger::info('Media user suspended: missing on server', [
                    'media_user_id' => $user->id,
                    'username' => $user->username,
                ]);
                continue;
            }
            // Aunque falle el corte remoto, el status ya puede estar suspended.
            if ((string) $user->status === 'suspended') {
                $stats['suspended']++;
                $stats['errors']++;
                continue;
            }
            $stats['errors']++;
            Logger::warning('Could not suspend off-server media user', [
                'media_user_id' => $user->id,
                'username' => $user->username,
                'message' => $result['message'] ?? null,
            ]);
        }

        return $stats;
    }
}
