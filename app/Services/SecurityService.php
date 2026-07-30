<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * IP blacklist and security utilities.
 */
final class SecurityService
{
    public function isBlocked(string $ip, int $tenantId = 1): bool
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT id FROM ip_blacklist WHERE (tenant_id = ? OR tenant_id IS NULL) AND ip_address = ?
                 AND (expires_at IS NULL OR expires_at > NOW())',
                [$tenantId, $ip]
            );
            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listBlacklist(int $tenantId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM ip_blacklist WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY created_at DESC',
            [$tenantId]
        );
    }

    public function blockIp(int $tenantId, string $ip, ?string $reason = null, ?string $expiresAt = null): int
    {
        return Database::getInstance()->insert('ip_blacklist', [
            'tenant_id' => $tenantId,
            'ip_address' => $ip,
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ]);
    }

    public function unblockIp(int $id): void
    {
        Database::getInstance()->query('DELETE FROM ip_blacklist WHERE id = ?', [$id]);
    }
}
