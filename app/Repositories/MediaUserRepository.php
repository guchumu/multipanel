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
    public function paginate(int $tenantId, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $offset = ($page - 1) * $perPage;
        $params = [$tenantId];
        $sql = 'SELECT * FROM `media_users` WHERE `tenant_id` = ? AND `deleted_at` IS NULL';

        if ($status !== null) {
            $sql .= ' AND `status` = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY `created_at` DESC LIMIT ? OFFSET ?';
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
}
