<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Centralized audit logging for GDPR compliance.
 */
final class AuditService
{
    public static function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?int $tenantId = null,
    ): void {
        $auth = new AuthService();
        $user = $auth->user();

        Database::getInstance()->insert('audit_logs', [
            'tenant_id' => $tenantId ?? ($user->tenant_id ?? 1),
            'user_id' => $userId ?? ($user->id ?? null),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }
}
