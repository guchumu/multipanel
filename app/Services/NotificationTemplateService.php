<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Tenant-specific expiry / Telegram message templates (overrides config file).
 */
final class NotificationTemplateService
{
    private const GROUP = 'notifications';
    private const KEY = 'expiry_messages';

    /** @return array<int|string, string> */
    public function getExpiryMessages(int $tenantId): array
    {
        $stored = $this->loadStored($tenantId);
        if ($stored !== []) {
            return $stored;
        }

        return config('expiry_notifications.messages', []);
    }

    /** @param array<int|string, string> $messages */
    public function saveExpiryMessages(int $tenantId, array $messages): void
    {
        $db = Database::getInstance();
        $value = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, self::GROUP, self::KEY]
        );

        if ($existing) {
            $db->update('settings', ['value' => $value], 'id = ?', [$existing['id']]);
            return;
        }

        $db->insert('settings', [
            'tenant_id' => $tenantId,
            'group' => self::GROUP,
            'key' => self::KEY,
            'value' => $value,
            'type' => 'json',
        ]);
    }

    /** @return array<int, int> */
    public function getMilestones(int $tenantId): array
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT value FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, self::GROUP, 'expiry_milestones']
        );

        if ($row && is_string($row['value'])) {
            $decoded = json_decode($row['value'], true);
            if (is_array($decoded)) {
                return array_map('intval', $decoded);
            }
        }

        return config('expiry_notifications.milestones', [10, 7, 5, 4, 3, 2, 1, 0, -1]);
    }

    /** @return array<int|string, string> */
    private function loadStored(int $tenantId): array
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT value FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, self::GROUP, self::KEY]
        );

        if (!$row || !is_string($row['value']) || trim($row['value']) === '') {
            return [];
        }

        $decoded = json_decode($row['value'], true);

        return is_array($decoded) ? $decoded : [];
    }
}
