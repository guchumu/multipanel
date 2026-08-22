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

    /** Post-caducidad: renovar a precio normal antes del reenganche. */
    private const POST_EXPIRY_RENEW = [-15, -30, -45];

    /** @return array<int|string, string> */
    public function getExpiryMessages(int $tenantId): array
    {
        $defaults = config('expiry_notifications.messages', []);
        if (!is_array($defaults)) {
            $defaults = [];
        }

        $stored = $this->loadStored($tenantId);
        if ($stored === []) {
            return $defaults;
        }

        // Plantillas guardadas pisan; las nuevas (p. ej. -15) salen del config.
        return array_replace($defaults, $stored);
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
        $defaults = config('expiry_notifications.milestones', [10, 7, 5, 4, 3, 2, 1, 0, -1, -15, -30, -45]);
        if (!is_array($defaults)) {
            $defaults = [10, 7, 5, 4, 3, 2, 1, 0, -1, -15, -30, -45];
        }
        $defaults = array_map('intval', $defaults);

        $row = Database::getInstance()->fetchOne(
            'SELECT value FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, self::GROUP, 'expiry_milestones']
        );

        $milestones = $defaults;
        if ($row && is_string($row['value'])) {
            $decoded = json_decode($row['value'], true);
            if (is_array($decoded) && $decoded !== []) {
                $milestones = array_map('intval', $decoded);
            }
        }

        foreach (self::POST_EXPIRY_RENEW as $day) {
            if (!in_array($day, $milestones, true)) {
                $milestones[] = $day;
            }
        }

        // Antes de caducar (desc), el día 0, luego post-caducidad (más reciente primero).
        usort($milestones, static function (int $a, int $b): int {
            if ($a >= 0 && $b >= 0) {
                return $b <=> $a;
            }
            if ($a >= 0) {
                return -1;
            }
            if ($b >= 0) {
                return 1;
            }

            return $b <=> $a; // -1, -15, -30, -45
        });

        return array_values($milestones);
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
