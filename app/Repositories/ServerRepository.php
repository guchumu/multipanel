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
        $this->ensureIsDefaultColumn();

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
        $this->ensureIsDefaultColumn();
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

    /**
     * Marca un servidor como predeterminado de su tipo (plex o jellyfin).
     * Solo puede haber uno por tipo dentro del tenant.
     */
    public function setDefault(int $tenantId, int $serverId, string $type): void
    {
        $this->ensureIsDefaultColumn();
        $type = in_array($type, ['plex', 'jellyfin'], true) ? $type : 'plex';
        $db = Database::getInstance();

        $db->query(
            'UPDATE `servers` SET `is_default` = 0 WHERE `tenant_id` = ? AND `type` = ? AND `deleted_at` IS NULL',
            [$tenantId, $type]
        );
        $db->query(
            'UPDATE `servers` SET `is_default` = 1 WHERE `id` = ? AND `tenant_id` = ? AND `type` = ? AND `deleted_at` IS NULL',
            [$serverId, $tenantId, $type]
        );
    }

    /** ¿Hay ya algún servidor de este tipo marcado como predeterminado? */
    public function hasDefaultOfType(int $tenantId, string $type): bool
    {
        $this->ensureIsDefaultColumn();
        $type = in_array($type, ['plex', 'jellyfin'], true) ? $type : 'plex';
        $row = Database::getInstance()->fetchOne(
            'SELECT `id` FROM `servers`
             WHERE `tenant_id` = ? AND `type` = ? AND `is_default` = 1 AND `deleted_at` IS NULL
             LIMIT 1',
            [$tenantId, $type]
        );

        return $row !== null;
    }

    /**
     * Servidor a preseleccionar en formularios de alta: Plex predeterminado,
     * si no Jellyfin predeterminado, si no el primero de la lista.
     */
    public function preferredDefaultForForms(int $tenantId): ?Server
    {
        return $this->findDefaultByTenant($tenantId, 'plex')
            ?? $this->findDefaultByTenant($tenantId, 'jellyfin');
    }

    public function countByStatus(int $tenantId, string $status): int
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT COUNT(*) as total FROM `servers` WHERE `tenant_id` = ? AND `status` = ? AND `deleted_at` IS NULL',
            [$tenantId, $status]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function ensureIsDefaultColumn(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        // SHOW COLUMNS solo requiere SELECT sobre la tabla (a diferencia de
        // information_schema, que algunos hostings MySQL restringen), y no
        // depende del runner de migraciones completo, que puede quedarse
        // bloqueado si alguna migración anterior falla en este entorno.
        try {
            $row = Database::getInstance()->fetchOne('SHOW COLUMNS FROM `servers` LIKE \'is_default\'');
            if ($row) {
                $ensured = true;
                return;
            }
        } catch (\Throwable) {
            // fall through: intentamos añadir la columna de todos modos
        }

        try {
            Database::getInstance()->pdo()->exec(
                'ALTER TABLE `servers` ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0'
            );
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'duplicate column') && !str_contains($message, 'already exists')) {
                throw $e;
            }
        }

        $ensured = true;
    }
}
