<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use Core\Database;
use Core\Logger;

/**
 * Corta el acceso en Plex/Jellyfin a usuarios caducados o suspendidos.
 * Se ejecuta en el cron (automation / all) para alinear servidor con la BD.
 */
final class MediaUserAccessEnforcementService
{
    public function __construct(
        private MediaUserManagementService $management = new MediaUserManagementService(),
    ) {
    }

    /**
     * @return array{checked: int, marked_expired: int, revoked: int, skipped: int, errors: int, disabled?: bool}
     */
    public function run(int $tenantId = 1): array
    {
        if (!(bool) config('expiry_notifications.deactivate_on_expiry', true)) {
            return [
                'checked' => 0,
                'marked_expired' => 0,
                'revoked' => 0,
                'skipped' => 0,
                'errors' => 0,
                'disabled' => true,
            ];
        }

        $stats = ['checked' => 0, 'marked_expired' => 0, 'revoked' => 0, 'skipped' => 0, 'errors' => 0];

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

        return $stats;
    }
}
