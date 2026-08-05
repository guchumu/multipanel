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
    public function paginate(
        int $tenantId,
        int $page = 1,
        int $perPage = 20,
        ?string $status = null,
        ?int $serverId = null,
        ?bool $onServer = null,
    ): array {
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

        if ($onServer !== null && $this->hasOnServerColumn()) {
            $sql .= ' AND mu.`on_server` = ?';
            $params[] = $onServer ? 1 : 0;
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

    public function countFiltered(
        int $tenantId,
        ?string $status = null,
        ?int $serverId = null,
        ?bool $onServer = null,
    ): int {
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

        if ($onServer !== null && $this->hasOnServerColumn()) {
            $sql .= ' AND `on_server` = ?';
            $params[] = $onServer ? 1 : 0;
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

    /**
     * @param array<int, string> $uuids
     * @return array<int, MediaUser>
     */
    public function findByUuids(int $tenantId, array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter(array_map(
            static fn ($u) => trim((string) $u),
            $uuids
        ), static fn (string $u) => $u !== '')));

        if ($uuids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $params = array_merge([$tenantId], $uuids);
        $rows = Database::getInstance()->fetchAll(
            "SELECT mu.*, s.name AS server_name
             FROM `media_users` mu
             LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
             WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL
               AND mu.`uuid` IN ({$placeholders})",
            $params
        );

        return array_map(static fn ($row) => new MediaUser($row), $rows);
    }

    /** @return array<int, MediaUser> */
    public function search(
        int $tenantId,
        string $query,
        int $limit = 25,
        ?string $status = null,
        ?int $serverId = null,
        ?bool $onServer = null,
    ): array {
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

        if ($onServer !== null && $this->hasOnServerColumn()) {
            $sql .= ' AND mu.`on_server` = ?';
            $params[] = $onServer ? 1 : 0;
        }

        $sql .= ' ORDER BY mu.`username` ASC LIMIT ?';
        $params[] = $limit;

        $rows = Database::getInstance()->fetchAll($sql, $params);

        return array_map(fn ($row) => new MediaUser($row), $rows);
    }

    /**
     * Busca un usuario ya existente (no borrado) con el mismo email o el mismo
     * nombre de usuario dentro del tenant, para evitar altas duplicadas.
     * El email tiene prioridad porque es el identificador más fiable.
     */
    public function findDuplicate(int $tenantId, string $username, ?string $email = null, ?int $excludeId = null): ?MediaUser
    {
        $db = Database::getInstance();
        $email = $email !== null ? trim($email) : null;
        $username = trim($username);

        if ($email !== null && $email !== '') {
            $params = [$tenantId, $email];
            $sql = 'SELECT mu.*, s.name AS server_name
                    FROM `media_users` mu
                    LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
                    WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL AND LOWER(mu.`email`) = LOWER(?)';
            if ($excludeId !== null) {
                $sql .= ' AND mu.`id` != ?';
                $params[] = $excludeId;
            }
            $sql .= ' ORDER BY mu.`expires_at` DESC LIMIT 1';

            $row = $db->fetchOne($sql, $params);
            if ($row) {
                return new MediaUser($row);
            }
        }

        if ($username === '') {
            return null;
        }

        $params = [$tenantId, $username];
        $sql = 'SELECT mu.*, s.name AS server_name
                FROM `media_users` mu
                LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL AND LOWER(mu.`username`) = LOWER(?)';
        if ($excludeId !== null) {
            $sql .= ' AND mu.`id` != ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY mu.`expires_at` DESC LIMIT 1';

        $row = $db->fetchOne($sql, $params);

        return $row ? new MediaUser($row) : null;
    }

    /**
     * Usuarios cuya suscripción vence dentro de X días (incluye ya caducados si $includeExpired).
     *
     * @return array<int, MediaUser>
     */
    public function findExpiringSoon(int $tenantId, int $days = 30, ?int $serverId = null, bool $includeExpired = true): array
    {
        $params = [$tenantId];
        $sql = 'SELECT mu.*, s.name AS server_name, s.uuid AS server_uuid,
                       DATEDIFF(mu.expires_at, CURDATE()) AS days_left
                FROM `media_users` mu
                LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL
                  AND mu.`expires_at` IS NOT NULL
                  AND mu.`status` IN (\'active\', \'invited\', \'suspended\')';

        if (!$includeExpired) {
            $sql .= ' AND mu.`expires_at` >= CURDATE()';
        }

        if ($serverId !== null) {
            $sql .= ' AND mu.`server_id` = ?';
            $params[] = $serverId;
        }

        $sql .= ' HAVING `days_left` <= ? ORDER BY mu.`expires_at` ASC LIMIT 500';
        $params[] = $days;

        $rows = Database::getInstance()->fetchAll($sql, $params);

        return array_map(fn ($row) => new MediaUser($row), $rows);
    }

    /** @return array<int, MediaUser> */
    public function listForBroadcast(int $tenantId, ?string $status = null, ?int $serverId = null, bool $withTelegramOnly = false): array
    {
        $params = [$tenantId];
        $sql = 'SELECT mu.*, s.name AS server_name
                FROM media_users mu
                LEFT JOIN servers s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.tenant_id = ? AND mu.deleted_at IS NULL';

        if ($status !== null) {
            $sql .= ' AND mu.status = ?';
            $params[] = $status;
        }

        if ($serverId !== null) {
            $sql .= ' AND mu.server_id = ?';
            $params[] = $serverId;
        }

        if ($withTelegramOnly && $this->hasTelegramChatIdColumn()) {
            $sql .= ' AND mu.telegram_chat_id IS NOT NULL AND mu.telegram_chat_id != ""';
        }

        $sql .= ' ORDER BY mu.username ASC LIMIT 500';
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

    private function hasOnServerColumn(): bool
    {
        return $this->hasColumn('media_users', 'on_server');
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

    public function ensureTelegramChatIdColumn(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if (!$this->hasTelegramChatIdColumn()) {
            try {
                (new \Core\Updater())->runMigrations();
            } catch (\Throwable) {
                // Continue with direct ALTER if migrations fail.
            }
        }

        if (!$this->hasTelegramChatIdColumn()) {
            try {
                Database::getInstance()->pdo()->exec(
                    'ALTER TABLE `media_users` ADD COLUMN `telegram_chat_id` VARCHAR(50) NULL AFTER `email`'
                );
            } catch (\Throwable $e) {
                if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                    throw $e;
                }
            }
        }

        $ensured = true;
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
