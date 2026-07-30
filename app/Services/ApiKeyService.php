<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * API key generation and validation.
 */
final class ApiKeyService
{
    public function create(int $tenantId, int $userId, string $name, array $permissions = [], ?string $expiresAt = null): array
    {
        $plainKey = 'mp_' . bin2hex(random_bytes(24));
        $prefix = substr($plainKey, 0, 8);

        $id = Database::getInstance()->insert('api_keys', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'key_hash' => hash('sha256', $plainKey),
            'key_prefix' => $prefix,
            'permissions' => json_encode($permissions ?: ['*']),
            'expires_at' => $expiresAt,
            'is_active' => 1,
        ]);

        return ['id' => $id, 'key' => $plainKey, 'prefix' => $prefix];
    }

    /** @return array<string, mixed>|null */
    public function validate(string $apiKey): ?array
    {
        if (!str_starts_with($apiKey, 'mp_')) {
            return null;
        }

        $hash = hash('sha256', $apiKey);
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1',
            [$hash]
        );

        if (!$row) {
            return null;
        }

        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return null;
        }

        Database::getInstance()->update('api_keys', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, name, key_prefix, permissions, last_used_at, expires_at, is_active, created_at
             FROM api_keys WHERE tenant_id = ? ORDER BY created_at DESC',
            [$tenantId]
        );
    }

    public function revoke(int $id): void
    {
        Database::getInstance()->update('api_keys', ['is_active' => 0], 'id = ?', [$id]);
    }
}
