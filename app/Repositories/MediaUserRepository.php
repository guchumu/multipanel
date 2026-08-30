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
    /** @var list<string> */
    public const EMPTY_FILTERS = ['expires', 'telegram', 'email'];

    /** @var array<string, string> */
    private const SORT_COLUMNS = [
        'id' => 'mu.`id`',
        'username' => 'COALESCE(NULLIF(TRIM(mu.`display_name`), \'\'), mu.`username`)',
        'email' => 'mu.`email`',
        'server' => 's.`name`',
        'status' => 'mu.`status`',
        'expires' => 'mu.`expires_at`',
        'expires_at' => 'mu.`expires_at`',
        'telegram' => 'mu.`telegram_chat_id`',
        'max_streams' => 'mu.`max_streams`',
    ];

    /**
     * @param list<string> $emptyFilters
     * @return array<int, MediaUser>
     */
    public function paginate(
        int $tenantId,
        int $page = 1,
        int $perPage = 20,
        ?string $status = null,
        ?int $serverId = null,
        ?bool $onServer = null,
        ?string $sort = null,
        string $dir = 'desc',
        array $emptyFilters = [],
    ): array {
        $offset = ($page - 1) * $perPage;
        $params = [$tenantId];
        $sql = 'SELECT mu.*, s.name AS server_name, s.uuid AS server_uuid, s.type AS server_type
                FROM `media_users` mu
                LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL';

        $this->appendListFilters($sql, $params, $status, $serverId, $onServer, $emptyFilters, true);
        $sql .= ' ' . $this->orderBySql($sort, $dir) . ' LIMIT ? OFFSET ?';
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

    /**
     * @param list<string> $emptyFilters
     */
    public function countFiltered(
        int $tenantId,
        ?string $status = null,
        ?int $serverId = null,
        ?bool $onServer = null,
        array $emptyFilters = [],
    ): int {
        $params = [$tenantId];
        $sql = 'SELECT COUNT(*) as total FROM `media_users` mu WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL';

        $this->appendListFilters($sql, $params, $status, $serverId, $onServer, $emptyFilters, true);

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

    /**
     * @param list<string> $emptyFilters
     * @return array<int, MediaUser>
     */
    public function search(
        int $tenantId,
        string $query,
        int $limit = 25,
        ?string $status = null,
        ?int $serverId = null,
        ?bool $onServer = null,
        ?string $sort = null,
        string $dir = 'asc',
        array $emptyFilters = [],
    ): array {
        $query = trim($query);
        // Permitir id numérico de 1 dígito; resto mínimo 2 caracteres.
        if ($query === '' || (mb_strlen($query) < 2 && !ctype_digit($query))) {
            return [];
        }

        $like = '%' . $query . '%';
        $params = [$tenantId, $like, $like, $like, $like];
        $matchSql = '(
                    mu.`username` LIKE ?
                    OR mu.`email` LIKE ?
                    OR mu.`display_name` LIKE ?
                    OR mu.`uuid` LIKE ?';

        if (ctype_digit($query)) {
            $matchSql .= '
                    OR mu.`id` = ?';
            $params[] = (int) $query;
        }

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

        $sql = 'SELECT mu.*, s.name AS server_name, s.uuid AS server_uuid, s.type AS server_type
                FROM `media_users` mu
                LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL
                  AND ' . $matchSql;

        $this->appendListFilters($sql, $params, $status, $serverId, $onServer, $emptyFilters, true);
        $sql .= ' ' . $this->orderBySql($sort ?? 'username', $dir, 'mu.`username` ASC') . ' LIMIT ?';
        $params[] = $limit;

        $rows = Database::getInstance()->fetchAll($sql, $params);

        return array_map(fn ($row) => new MediaUser($row), $rows);
    }

    /**
     * Convierte telegram_chat_id con valor literal "null" a NULL real.
     */
    public function scrubLiteralNullTelegram(int $tenantId): int
    {
        if (!$this->hasTelegramChatIdColumn()) {
            return 0;
        }

        $stmt = Database::getInstance()->query(
            'UPDATE `media_users`
             SET `telegram_chat_id` = NULL
             WHERE `tenant_id` = ?
               AND `deleted_at` IS NULL
               AND LOWER(TRIM(`telegram_chat_id`)) = \'null\'',
            [$tenantId]
        );

        return (int) $stmt->rowCount();
    }

    /**
     * @param list<string> $raw
     * @return list<string>
     */
    public static function normalizeEmptyFilters(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            foreach (preg_split('/[|,]+/', (string) $item) ?: [] as $part) {
                $key = strtolower(trim($part));
                if (in_array($key, self::EMPTY_FILTERS, true) && !in_array($key, $out, true)) {
                    $out[] = $key;
                }
            }
        }

        return $out;
    }

    public static function normalizeSort(?string $sort): ?string
    {
        $sort = strtolower(trim((string) $sort));
        if ($sort === '' || !isset(self::SORT_COLUMNS[$sort])) {
            return null;
        }

        return $sort === 'expires_at' ? 'expires' : $sort;
    }

    public static function normalizeDir(?string $dir): string
    {
        return strtolower(trim((string) $dir)) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @param list<string> $emptyFilters
     * @param array<int, mixed> $params
     */
    private function appendListFilters(
        string &$sql,
        array &$params,
        ?string $status,
        ?int $serverId,
        ?bool $onServer,
        array $emptyFilters,
        bool $useAlias,
    ): void {
        $p = $useAlias ? 'mu.' : '';

        if ($status !== null) {
            $sql .= " AND {$p}`status` = ?";
            $params[] = $status;
        }

        if ($serverId !== null) {
            $sql .= " AND {$p}`server_id` = ?";
            $params[] = $serverId;
        }

        if ($onServer !== null && $this->hasOnServerColumn()) {
            $sql .= " AND {$p}`on_server` = ?";
            $params[] = $onServer ? 1 : 0;
        }

        foreach (self::normalizeEmptyFilters($emptyFilters) as $filter) {
            if ($filter === 'expires') {
                $sql .= " AND {$p}`expires_at` IS NULL";
                continue;
            }
            if ($filter === 'email') {
                $sql .= " AND ({$p}`email` IS NULL OR TRIM({$p}`email`) = '')";
                continue;
            }
            if ($filter === 'telegram' && $this->hasTelegramChatIdColumn()) {
                $sql .= " AND ({$p}`telegram_chat_id` IS NULL
                    OR TRIM({$p}`telegram_chat_id`) = ''
                    OR LOWER(TRIM({$p}`telegram_chat_id`)) = 'null')";
            }
        }
    }

    private function orderBySql(?string $sort, string $dir, string $default = 'mu.`created_at` DESC'): string
    {
        $sortKey = self::normalizeSort($sort);
        if ($sortKey === null || !isset(self::SORT_COLUMNS[$sortKey])) {
            return 'ORDER BY ' . $default;
        }

        $column = self::SORT_COLUMNS[$sortKey];
        $direction = self::normalizeDir($dir);
        // NULLs al final (más útil al ordenar caducidad / telegram / email).
        return "ORDER BY ({$column} IS NULL) ASC, {$column} {$direction}";
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
        $sql = 'SELECT mu.*, s.name AS server_name, s.uuid AS server_uuid, s.type AS server_type,
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

    /** @var array<string, bool> */
    private static array $columnCache = [];

    public static function clearColumnCache(): void
    {
        self::$columnCache = [];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
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
            self::$columnCache[$key] = ((int) ($row['total'] ?? 0)) > 0;
        } catch (\Throwable) {
            self::$columnCache[$key] = false;
        }

        return self::$columnCache[$key];
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
            self::clearColumnCache();
        }

        $ensured = true;
    }

    public function ensureJellyfinPasswordColumn(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if (!$this->hasColumn('media_users', 'jellyfin_password_encrypted')) {
            try {
                (new \Core\Updater())->runMigrations();
            } catch (\Throwable) {
                // Continue with direct ALTER if migrations fail.
            }
        }

        if (!$this->hasColumn('media_users', 'jellyfin_password_encrypted')) {
            try {
                Database::getInstance()->pdo()->exec(
                    'ALTER TABLE `media_users` ADD COLUMN `jellyfin_password_encrypted` TEXT NULL AFTER `password`'
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
                 CASE
                     WHEN JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\') IS NULL
                          OR JSON_TYPE(JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\')) = \'NULL\'
                          OR TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\'))) = \'\'
                          OR LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\')))) = \'null\'
                     THEN NULL
                     ELSE TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\')))
                 END,
                 CASE
                     WHEN JSON_EXTRACT(c.metadata, \'$.telegram_id\') IS NULL
                          OR JSON_TYPE(JSON_EXTRACT(c.metadata, \'$.telegram_id\')) = \'NULL\'
                          OR TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_id\'))) = \'\'
                          OR LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_id\')))) = \'null\'
                     THEN NULL
                     ELSE TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_id\')))
                 END
             )
             WHERE mu.tenant_id = ?
               AND mu.deleted_at IS NULL
               AND (
                 mu.telegram_chat_id IS NULL
                 OR mu.telegram_chat_id = \'\'
                 OR LOWER(TRIM(mu.telegram_chat_id)) = \'null\'
               )
               AND (
                 (
                   JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\') IS NOT NULL
                   AND JSON_TYPE(JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\')) != \'NULL\'
                   AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\'))) != \'\'
                   AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_chat_id\')))) != \'null\'
                 )
                 OR (
                   JSON_EXTRACT(c.metadata, \'$.telegram_id\') IS NOT NULL
                   AND JSON_TYPE(JSON_EXTRACT(c.metadata, \'$.telegram_id\')) != \'NULL\'
                   AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_id\'))) != \'\'
                   AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.metadata, \'$.telegram_id\')))) != \'null\'
                 )
               )',
            [$tenantId]
        );

        return (int) $stmt->rowCount();
    }

    /**
     * Restaura emails borrados por sync (API sin email) desde customers vinculados.
     */
    public function backfillEmailsFromCustomers(int $tenantId): int
    {
        $stmt = Database::getInstance()->query(
            'UPDATE media_users mu
             INNER JOIN customers c ON c.media_user_id = mu.id AND c.tenant_id = mu.tenant_id
             SET mu.email = c.email
             WHERE mu.tenant_id = ?
               AND mu.deleted_at IS NULL
               AND (mu.email IS NULL OR mu.email = \'\')
               AND c.email IS NOT NULL AND c.email != \'\'',
            [$tenantId]
        );

        return (int) $stmt->rowCount();
    }

    /**
     * Datos previos del panel (soft-deleted u otro registro) para recuperar identidad.
     *
     * @return array{email?: ?string, telegram_chat_id?: ?string, expires_at?: ?string, notes?: ?string}|null
     */
    public function findIdentityTwin(
        int $tenantId,
        int $serverId,
        string $username,
        string $email,
        string $externalId,
        int $excludeId = 0,
    ): ?array {
        $db = Database::getInstance();
        $candidates = [];
        $excludeSql = $excludeId > 0 ? ' AND id <> ?' : '';
        $excludeParams = $excludeId > 0 ? [$excludeId] : [];

        if ($externalId !== '') {
            $row = $db->fetchOne(
                'SELECT id, username, server_id, deleted_at, email, telegram_chat_id, expires_at, notes FROM media_users
                 WHERE tenant_id = ? AND external_id = ?' . $excludeSql . '
                 ORDER BY (deleted_at IS NULL) DESC, id DESC LIMIT 1',
                array_merge([$tenantId, $externalId], $excludeParams)
            );
            if ($row) {
                $candidates[] = $row;
            }
        }

        if ($email !== '') {
            $row = $db->fetchOne(
                'SELECT id, username, server_id, deleted_at, email, telegram_chat_id, expires_at, notes FROM media_users
                 WHERE tenant_id = ? AND LOWER(email) = LOWER(?)' . $excludeSql . '
                 ORDER BY (server_id = ?) DESC, (deleted_at IS NULL) DESC, id DESC LIMIT 1',
                array_merge([$tenantId, $email, $serverId], $excludeParams)
            );
            if ($row) {
                $candidates[] = $row;
            }
        }

        if ($username !== '') {
            $row = $db->fetchOne(
                'SELECT id, username, server_id, deleted_at, email, telegram_chat_id, expires_at, notes FROM media_users
                 WHERE tenant_id = ? AND (LOWER(username) = LOWER(?) OR LOWER(display_name) = LOWER(?))'
                 . $excludeSql . '
                 ORDER BY (server_id = ?) DESC, (deleted_at IS NULL) DESC, id DESC LIMIT 1',
                array_merge([$tenantId, $username, $username, $serverId], $excludeParams)
            );
            if ($row) {
                $candidates[] = $row;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $merged = [
            'id' => null,
            'username' => null,
            'server_id' => null,
            'deleted_at' => null,
            'email' => null,
            'telegram_chat_id' => null,
            'expires_at' => null,
            'notes' => null,
        ];
        foreach ($candidates as $row) {
            foreach (array_keys($merged) as $field) {
                $val = $row[$field] ?? null;
                if (($merged[$field] === null || trim((string) $merged[$field]) === '')
                    && $val !== null && trim((string) $val) !== '') {
                    $merged[$field] = $val;
                }
            }
        }

        foreach ($merged as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return $merged;
            }
        }

        return null;
    }

    /**
     * Siguiente usuario para revisión one-by-one (campos vacíos / fuera del servidor).
     *
     * @param list<string> $emptyFilters
     */
    public function findNextForReview(
        int $tenantId,
        array $emptyFilters = [],
        ?bool $onServer = null,
        ?int $serverId = null,
        ?int $afterId = null,
    ): ?MediaUser {
        $params = [$tenantId];
        $sql = 'SELECT mu.*, s.name AS server_name, s.uuid AS server_uuid, s.type AS server_type
                FROM `media_users` mu
                LEFT JOIN `servers` s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.`tenant_id` = ? AND mu.`deleted_at` IS NULL';
        $this->appendListFilters($sql, $params, null, $serverId, $onServer, $emptyFilters, true);
        if ($afterId !== null && $afterId > 0) {
            $sql .= ' AND mu.`id` > ?';
            $params[] = $afterId;
        }
        $sql .= ' ORDER BY mu.`id` ASC LIMIT 1';
        $row = Database::getInstance()->fetchOne($sql, $params);

        return $row ? new MediaUser($row) : null;
    }

    /**
     * Soft-delete usuarios con on_server=0 (solo panel; no toca Plex/Jellyfin).
     *
     * @param list<string>|null $uuids null = todos los ausentes del filtro
     * @return int filas afectadas
     */
    public function softDeleteOffServer(int $tenantId, ?array $uuids = null, ?int $serverId = null): int
    {
        if (!$this->hasOnServerColumn()) {
            return 0;
        }

        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $params = [$now, $tenantId];
        $sql = 'UPDATE `media_users`
                SET `deleted_at` = ?
                WHERE `tenant_id` = ?
                  AND `deleted_at` IS NULL
                  AND `on_server` = 0';

        if ($serverId !== null) {
            $sql .= ' AND `server_id` = ?';
            $params[] = $serverId;
        }

        if ($uuids !== null) {
            $uuids = array_values(array_unique(array_filter(array_map(
                static fn ($u) => trim((string) $u),
                $uuids
            ))));
            if ($uuids === []) {
                return 0;
            }
            $placeholders = implode(',', array_fill(0, count($uuids), '?'));
            $sql .= " AND `uuid` IN ({$placeholders})";
            $params = array_merge($params, $uuids);
        }

        $stmt = $db->query($sql, $params);

        return (int) $stmt->rowCount();
    }
}
