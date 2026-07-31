<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\MediaUser;
use Core\Database;

/**
 * Media user data access layer.
 */
class MediaUserRepository
{
    /** @return array<int, MediaUser> */
    public function paginate(int $tenantId, int $page = 1, int $perPage = 20, ?string $status = null, ?int $serverId = null): array
    {
        $offset = ($page - 1) * $perPage;
        $params = [$tenantId];
        $sql = 'SELECT mu.*, s.name AS server_name, s.uuid AS server_uuid
                FROM `media_users` mu
                LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL';

        if ($status !== null) {
            $sql .= ' AND mu.`status` = ?';
            $params[] = $status;
        }

        if ($serverId !== null) {
            $sql .= ' AND mu.`server_id` = ?';
            $params[] = $serverId;
        }

        $sql .= ' ORDER BY mu.`created_at` DESC LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;

        $rows = Database::getInstance()->fetchAll($sql, $params);
        return array_map(fn ($row) => new MediaUser($row), $rows);
    }

    public function countByStatus(int $tenantId, string $status): int
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT COUNT(*) as total FROM `media_users` WHERE `tenant_id` = ? AND `status` = ? AND `deleted_at` IS NULL',
            [$tenantId, $status]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function countTotal(int $tenantId): int
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT COUNT(*) as total FROM `media_users` WHERE `tenant_id` = ? AND `deleted_at` IS NULL',
            [$tenantId]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function findByUuid(string $uuid): ?MediaUser
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM `media_users` WHERE `uuid` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$uuid]
        );

        return $row ? new MediaUser($row) : null;
    }

    public function backfillMissingServerIds(int $tenantId): int
    {
        $db = Database::getInstance();
        $servers = $db->fetchAll(
            'SELECT id FROM servers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY id',
            [$tenantId]
        );

        if ($servers === []) {
            return 0;
        }

        if (count($servers) === 1) {
            return $db->update(
                'media_users',
                ['server_id' => (int) $servers[0]['id']],
                'tenant_id = ? AND server_id IS NULL AND deleted_at IS NULL',
                [$tenantId]
            );
        }

        return 0;
    }
}
