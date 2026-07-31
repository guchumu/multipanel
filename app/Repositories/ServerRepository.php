<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Server;
use Core\Database;

/**
 * Server data access layer.
 */
class ServerRepository
{
    /** @return array<int, Server> */
    public function allByTenant(int $tenantId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM `servers` WHERE `tenant_id` = ? AND `deleted_at` IS NULL ORDER BY `sort_order`, `name`',
            [$tenantId]
        );

        return array_map(fn ($row) => new Server($row), $rows);
    }

    public function findByUuid(string $uuid): ?Server
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM `servers` WHERE `uuid` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$uuid]
        );

        return $row ? new Server($row) : null;
    }

    public function findDefaultByTenant(int $tenantId, string $type = 'plex'): ?Server
    {
        $type = in_array($type, ['plex', 'jellyfin'], true) ? $type : 'plex';

        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM `servers`
             WHERE `tenant_id` = ? AND `type` = ? AND `is_default` = 1 AND `deleted_at` IS NULL
             LIMIT 1',
            [$tenantId, $type]
        );

        if ($row) {
            return new Server($row);
        }

        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM `servers`
             WHERE `tenant_id` = ? AND `type` = ? AND `deleted_at` IS NULL
             ORDER BY `sort_order`, `id` LIMIT 1',
            [$tenantId, $type]
        );

        return $row ? new Server($row) : null;
    }

    public function countByStatus(int $tenantId, string $status): int
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT COUNT(*) as total FROM `servers` WHERE `tenant_id` = ? AND `status` = ? AND `deleted_at` IS NULL',
            [$tenantId, $status]
        );

        return (int) ($row['total'] ?? 0);
    }
}
