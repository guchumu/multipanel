<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * GDPR data export and deletion service.
 */
final class GdprService
{
    public function requestExport(int $tenantId, ?int $userId = null, ?int $mediaUserId = null): int
    {
        return Database::getInstance()->insert('gdpr_requests', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'media_user_id' => $mediaUserId,
            'type' => 'export',
            'status' => 'pending',
        ]);
    }

    public function requestDeletion(int $tenantId, ?int $userId = null, ?int $mediaUserId = null): int
    {
        return Database::getInstance()->insert('gdpr_requests', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'media_user_id' => $mediaUserId,
            'type' => 'delete',
            'status' => 'pending',
        ]);
    }

    public function processPending(int $limit = 10): int
    {
        $requests = Database::getInstance()->fetchAll(
            "SELECT * FROM gdpr_requests WHERE status = 'pending' ORDER BY id ASC LIMIT ?",
            [$limit]
        );

        $processed = 0;
        foreach ($requests as $request) {
            Database::getInstance()->update('gdpr_requests', ['status' => 'processing'], 'id = ?', [$request['id']]);

            try {
                if ($request['type'] === 'export') {
                    $this->processExport($request);
                } else {
                    $this->processDeletion($request);
                }
                $processed++;
            } catch (\Throwable $e) {
                Database::getInstance()->update('gdpr_requests', [
                    'status' => 'failed',
                    'completed_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$request['id']]);
            }
        }

        return $processed;
    }

    /** @param array<string, mixed> $request */
    private function processExport(array $request): void
    {
        $data = $this->collectUserData($request);
        $path = storage_path('exports');
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filename = 'gdpr_export_' . $request['id'] . '_' . date('Ymd_His') . '.json';
        file_put_contents($path . '/' . $filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        Database::getInstance()->update('gdpr_requests', [
            'status' => 'completed',
            'file_path' => $path . '/' . $filename,
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$request['id']]);
    }

    /** @param array<string, mixed> $request */
    private function processDeletion(array $request): void
    {
        $db = Database::getInstance();

        if ($request['media_user_id']) {
            $db->query('UPDATE media_users SET deleted_at = NOW(), status = ? WHERE id = ?', ['inactive', $request['media_user_id']]);
        }

        if ($request['user_id']) {
            $db->query('UPDATE users SET deleted_at = NOW(), status = ?, email = CONCAT(email, ".deleted.", id) WHERE id = ?', ['inactive', $request['user_id']]);
            $db->query('DELETE FROM oauth_accounts WHERE user_id = ?', [$request['user_id']]);
        }

        Database::getInstance()->update('gdpr_requests', [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$request['id']]);
    }

    /** @param array<string, mixed> $request */
    /** @return array<string, mixed> */
    public function collectUserData(array $request): array
    {
        $db = Database::getInstance();
        $data = ['exported_at' => date('c'), 'tenant_id' => $request['tenant_id']];

        if ($request['media_user_id']) {
            $data['media_user'] = $db->fetchOne('SELECT uuid, username, email, status, created_at FROM media_users WHERE id = ?', [$request['media_user_id']]);
            $data['subscriptions'] = $db->fetchAll('SELECT * FROM subscriptions WHERE media_user_id = ?', [$request['media_user_id']]);
            $data['tickets'] = $db->fetchAll('SELECT uuid, subject, status, created_at FROM tickets WHERE media_user_id = ?', [$request['media_user_id']]);
        }

        if ($request['user_id']) {
            $data['user'] = $db->fetchOne('SELECT uuid, email, username, first_name, last_name, created_at FROM users WHERE id = ?', [$request['user_id']]);
            $data['audit_logs'] = $db->fetchAll('SELECT action, entity_type, created_at FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [$request['user_id']]);
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    public function listRequests(int $tenantId, int $limit = 50): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM gdpr_requests WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ?',
            [$tenantId, $limit]
        );
    }
}
