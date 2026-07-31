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

    public function countFiltered(int $tenantId, ?string $status = null, ?int $serverId = null): int
    {
        $params = [$tenantId];
        $sql = 'SELECT COUNT(*) as total FROM `media_users` WHERE `tenant_id` = ? AND `deleted_at` IS NULL';

        if ($status !== null) {
            $sql .= ' AND `status` = ?';
            $params[] = $status;
        }

        if ($serverId !== null) {
            $sql .= ' AND `server_id` = ?';
            $params[] = $serverId;
        }

        $row = Database::getInstance()->fetchOne($sql, $params);

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

    /** @return array<int, MediaUser> */
    public function search(int $tenantId, string $query, int $limit = 25, ?string $status = null, ?int $serverId = null): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return [];
        }

        $like = '%' . $query . '%';
        $params = [$tenantId, $like, $like, $like];
        $matchSql = '(
                    mu.`username` LIKE ?
                    OR mu.`email` LIKE ?
                    OR mu.`display_name` LIKE ?';

        if ($this->hasTelegramChatIdColumn()) {
            $matchSql .= '
                    OR mu.`telegram_chat_id` LIKE ?';
            $params[] = $like;
        }

        if ($this->hasExternalIdColumn()) {
            $matchSql .= '
                    OR mu.`external_id` LIKE ?';
            $params[] = $like;
        }

        $matchSql .= '
                  )';

        $sql = 'SELECT mu.*, s.name AS server_name, s.uuid AS server_uuid
                FROM `media_users` mu
                LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL
                  AND ' . $matchSql;

        if ($status !== null) {
            $sql .= ' AND mu.`status` = ?';
            $params[] = $status;
        }

        if ($serverId !== null) {
            $sql .= ' AND mu.`server_id` = ?';
            $params[] = $serverId;
        }

        $sql .= ' ORDER BY mu.`username` ASC LIMIT ?';
        $params[] = $limit;

        $rows = Database::getInstance()->fetchAll($sql, $params);

        return array_map(fn ($row) => new MediaUser($row), $rows);
    }

    private function hasTelegramChatIdColumn(): bool
    {
        return $this->hasColumn('media_users', 'telegram_chat_id');
    }

    private function hasExternalIdColumn(): bool
    {
        return $this->hasColumn('media_users', 'external_id');
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];

        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS total
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                [$table, $column]
            );
            $cache[$key] = ((int) ($row['total'] ?? 0)) > 0;
        } catch (\Throwable) {
            $cache[$key] = false;
        }

        return $cache[$key];
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

    /** Copy Telegram chat IDs from customer metadata when missing on media users. */
    public function backfillTelegramChatIds(int $tenantId): int
    {
        $stmt = Database::getInstance()->query(
            'UPDATE media_users mu
             INNER JOIN customers c ON c.media_user_id = mu.id AND c.tenant_id = mu.tenant_id
             SET mu.telegram_chat_id = COALESCE(
                 NULLIF(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\')), \'\'),
                 NULLIF(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_id\')), \'\')
             )
             WHERE mu.tenant_id = ?
               AND mu.deleted_at IS NULL
               AND (mu.telegram_chat_id IS NULL OR mu.telegram_chat_id = \'\')
               AND (
                 JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\') IS NOT NULL
                 OR JSON_EXTRACT(c.metadata, \'$.telegram_id\') IS NOT NULL
               )',
            [$tenantId]
        );

        return (int) $stmt->rowCount();
    }
}
