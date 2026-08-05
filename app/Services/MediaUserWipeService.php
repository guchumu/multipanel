<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;

/**
 * Soft-delete all media users for a tenant (panel DB only; not Plex/Jellyfin).
 */
final class MediaUserWipeService
{
    public const CONFIRM_PHRASE = 'BORRAR TODOS';

    public function __construct(
        private AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * @return array{ok: bool, deleted: int, errors: array<int, string>}
     */
    public function softDeleteAll(int $tenantId, string $confirmPhrase): array
    {
        if (trim($confirmPhrase) !== self::CONFIRM_PHRASE) {
            return [
                'ok' => false,
                'deleted' => 0,
                'errors' => ['Confirmación incorrecta. Escribe exactamente: ' . self::CONFIRM_PHRASE],
            ];
        }

        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');

        try {
            $before = $db->fetchOne(
                'SELECT COUNT(*) AS c FROM media_users WHERE tenant_id = ? AND deleted_at IS NULL',
                [$tenantId]
            );
            $count = (int) ($before['c'] ?? 0);

            if ($count === 0) {
                return ['ok' => true, 'deleted' => 0, 'errors' => []];
            }

            $db->query(
                "UPDATE media_users
                 SET deleted_at = ?, status = 'inactive'
                 WHERE tenant_id = ? AND deleted_at IS NULL",
                [$now, $tenantId]
            );

            $this->audit->log('media_users.wipe_all', 'media_user', null, null, [
                'tenant_id' => $tenantId,
                'deleted' => $count,
                'mode' => 'soft_delete',
            ]);
            Logger::info('Media users wiped (soft-delete)', [
                'tenant_id' => $tenantId,
                'deleted' => $count,
            ]);

            return ['ok' => true, 'deleted' => $count, 'errors' => []];
        } catch (\Throwable $e) {
            Logger::error('Media users wipe failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'deleted' => 0, 'errors' => [$e->getMessage()]];
        }
    }
}
