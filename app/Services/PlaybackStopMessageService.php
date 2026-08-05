<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Updater;

/**
 * Tenant-scoped predefined messages for stopping playback in En directo.
 */
final class PlaybackStopMessageService
{
    public const DEFAULT_TITLE = 'Configuración mal configurada';

    public const DEFAULT_BODY = 'Ajustes de configuración mal configurados. Para evitar cortes revise la configuración obligatoria o contacte con soporte.';

    /**
     * Prefer Updater/migrations; fall back to CREATE IF NOT EXISTS when the table is still missing.
     */
    public static function ensureTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if (self::tableExists()) {
            $ensured = true;
            return;
        }

        try {
            (new Updater())->runMigrations();
        } catch (\Throwable) {
            // Fall through to direct CREATE.
        }

        if (self::tableExists()) {
            $ensured = true;
            return;
        }

        try {
            Database::getInstance()->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `playback_stop_messages` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT UNSIGNED NOT NULL,
                    `title` VARCHAR(120) NOT NULL,
                    `body` TEXT NOT NULL,
                    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                    `sort_order` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_playback_stop_messages_tenant` (`tenant_id`),
                    KEY `idx_playback_stop_messages_default` (`tenant_id`, `is_default`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            return;
        }

        $ensured = true;
    }

    /**
     * @return array<int, array{id:int,tenant_id:int,title:string,body:string,is_default:int,sort_order:int,created_at:?string,updated_at:?string}>
     */
    public function listForTenant(int $tenantId): array
    {
        self::ensureTable();
        $this->ensureDefaultSeed($tenantId);

        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM `playback_stop_messages`
             WHERE `tenant_id` = ?
             ORDER BY `is_default` DESC, `sort_order` ASC, `id` ASC',
            [$tenantId]
        );

        return array_map(static fn (array $row): array => self::normalizeRow($row), $rows);
    }

    /**
     * @return array{id:int,tenant_id:int,title:string,body:string,is_default:int,sort_order:int,created_at:?string,updated_at:?string}|null
     */
    public function findForTenant(int $tenantId, int $id): ?array
    {
        self::ensureTable();

        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM `playback_stop_messages` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1',
            [$id, $tenantId]
        );

        return $row ? self::normalizeRow($row) : null;
    }

    public function create(int $tenantId, string $title, string $body, bool $isDefault = false): int
    {
        self::ensureTable();

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('Título y mensaje son obligatorios.');
        }

        $db = Database::getInstance();
        $maxSort = $db->fetchOne(
            'SELECT COALESCE(MAX(sort_order), -1) AS m FROM `playback_stop_messages` WHERE `tenant_id` = ?',
            [$tenantId]
        );
        $sortOrder = ((int) ($maxSort['m'] ?? -1)) + 1;

        if ($isDefault) {
            $db->query(
                'UPDATE `playback_stop_messages` SET `is_default` = 0 WHERE `tenant_id` = ?',
                [$tenantId]
            );
        }

        return $db->insert('playback_stop_messages', [
            'tenant_id' => $tenantId,
            'title' => mb_substr($title, 0, 120),
            'body' => $body,
            'is_default' => $isDefault ? 1 : 0,
            'sort_order' => $sortOrder,
        ]);
    }

    public function update(int $tenantId, int $id, string $title, string $body, ?bool $isDefault = null): bool
    {
        self::ensureTable();

        $existing = $this->findForTenant($tenantId, $id);
        if ($existing === null) {
            return false;
        }

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('Título y mensaje son obligatorios.');
        }

        $db = Database::getInstance();
        $data = [
            'title' => mb_substr($title, 0, 120),
            'body' => $body,
        ];

        if ($isDefault === true) {
            $db->query(
                'UPDATE `playback_stop_messages` SET `is_default` = 0 WHERE `tenant_id` = ?',
                [$tenantId]
            );
            $data['is_default'] = 1;
        } elseif ($isDefault === false) {
            $data['is_default'] = 0;
        }

        $db->update('playback_stop_messages', $data, 'id = ? AND tenant_id = ?', [$id, $tenantId]);

        return true;
    }

    public function setDefault(int $tenantId, int $id): bool
    {
        self::ensureTable();

        if ($this->findForTenant($tenantId, $id) === null) {
            return false;
        }

        $db = Database::getInstance();
        $db->query(
            'UPDATE `playback_stop_messages` SET `is_default` = 0 WHERE `tenant_id` = ?',
            [$tenantId]
        );
        $db->query(
            'UPDATE `playback_stop_messages` SET `is_default` = 1 WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $tenantId]
        );

        return true;
    }

    public function delete(int $tenantId, int $id): bool
    {
        self::ensureTable();

        $existing = $this->findForTenant($tenantId, $id);
        if ($existing === null) {
            return false;
        }

        $db = Database::getInstance();
        $db->query(
            'DELETE FROM `playback_stop_messages` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $tenantId]
        );

        if ((int) $existing['is_default'] === 1) {
            $next = $db->fetchOne(
                'SELECT `id` FROM `playback_stop_messages` WHERE `tenant_id` = ? ORDER BY `sort_order` ASC, `id` ASC LIMIT 1',
                [$tenantId]
            );
            if ($next) {
                $db->query(
                    'UPDATE `playback_stop_messages` SET `is_default` = 1 WHERE `id` = ?',
                    [(int) $next['id']]
                );
            }
        }

        return true;
    }

    /**
     * Seed the default message when the tenant has none.
     */
    public function ensureDefaultSeed(int $tenantId): void
    {
        self::ensureTable();

        $count = Database::getInstance()->fetchOne(
            'SELECT COUNT(*) AS c FROM `playback_stop_messages` WHERE `tenant_id` = ?',
            [$tenantId]
        );

        if ((int) ($count['c'] ?? 0) > 0) {
            return;
        }

        Database::getInstance()->insert('playback_stop_messages', [
            'tenant_id' => $tenantId,
            'title' => self::DEFAULT_TITLE,
            'body' => self::DEFAULT_BODY,
            'is_default' => 1,
            'sort_order' => 0,
        ]);
    }

    private static function tableExists(): bool
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT 1 AS ok FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                ['playback_stop_messages']
            );

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int,tenant_id:int,title:string,body:string,is_default:int,sort_order:int,created_at:?string,updated_at:?string}
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'is_default' => (int) $row['is_default'],
            'sort_order' => (int) $row['sort_order'],
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }
}
