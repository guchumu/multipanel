<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Session;

/**
 * Ajustes de alertas admin y ventana de avisos de caducidad.
 * Prioridad: settings DB → .env / config → defaults.
 */
final class AlertSettingsService
{
    private const DEFAULT_ALERT_EMAIL = 'alex@masquecero.es';
    private const DEFAULT_EXPIRY_HOUR = 9;
    private const DEFAULT_EXPIRY_TZ = 'Europe/Madrid';
    private const DEFAULT_EXPIRY_WINDOW = 15;

    /** @return array{hour: int, timezone: string, window_minutes: int} */
    public function expiryNotifySchedule(?int $tenantId = null): array
    {
        $tenantId ??= $this->tenantId();
        $cron = $this->loadGroup($tenantId, 'cron');

        $hour = (int) ($cron['expiry_notify_hour'] ?? config('expiry_notifications.notify_hour', self::DEFAULT_EXPIRY_HOUR));
        if ($hour < 0 || $hour > 23) {
            $hour = self::DEFAULT_EXPIRY_HOUR;
        }

        $timezone = trim((string) ($cron['expiry_notify_timezone'] ?? config('expiry_notifications.notify_timezone', self::DEFAULT_EXPIRY_TZ)));
        if ($timezone === '') {
            $timezone = self::DEFAULT_EXPIRY_TZ;
        }

        $window = (int) ($cron['expiry_notify_window_minutes'] ?? config('expiry_notifications.notify_window_minutes', self::DEFAULT_EXPIRY_WINDOW));
        if ($window < 1) {
            $window = self::DEFAULT_EXPIRY_WINDOW;
        }
        if ($window > 60) {
            $window = 60;
        }

        return [
            'hour' => $hour,
            'timezone' => $timezone,
            'window_minutes' => $window,
        ];
    }

    public function alertEmail(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $email = trim((string) ($alerts['alert_email'] ?? ''));
        if ($email === '') {
            $email = trim((string) config('alerts.email', self::DEFAULT_ALERT_EMAIL));
        }
        if ($email === '') {
            $email = self::DEFAULT_ALERT_EMAIL;
        }

        return $email;
    }

    public function whatsappEnabled(?int $tenantId = null): bool
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        if (array_key_exists('whatsapp_enabled', $alerts) && trim($alerts['whatsapp_enabled']) !== '') {
            return $this->truthy($alerts['whatsapp_enabled']);
        }

        return (bool) config('alerts.whatsapp_enabled', false);
    }

    public function whatsappPhone(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $phone = trim((string) ($alerts['whatsapp_phone'] ?? ''));
        if ($phone === '') {
            $phone = trim((string) config('alerts.whatsapp_phone', ''));
        }

        return preg_replace('/[^\d+]/', '', $phone) ?? '';
    }

    public function whatsappApiKey(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $key = trim((string) ($alerts['whatsapp_apikey'] ?? ''));
        if ($key === '') {
            $key = trim((string) config('alerts.whatsapp_apikey', ''));
        }

        return $key;
    }

    public function whatsappConfigured(?int $tenantId = null): bool
    {
        return $this->whatsappEnabled($tenantId)
            && $this->whatsappPhone($tenantId) !== ''
            && $this->whatsappApiKey($tenantId) !== '';
    }

    /** @return array<int, int> */
    public function serverDownEscalationMinutes(): array
    {
        $raw = config('alerts.server_down_escalation_minutes', [0, 5, 15, 30]);
        if (!is_array($raw) || $raw === []) {
            return [0, 5, 15, 30];
        }

        return array_values(array_map('intval', $raw));
    }

    /** @return array<string, array{first_seen_at: string, last_alert_at: string, level: int}> */
    public function getServerDownState(int $tenantId): array
    {
        $raw = $this->get($tenantId, 'alerts', 'server_down_state');
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array{first_seen_at: string, last_alert_at: string, level: int}> $state */
    public function saveServerDownState(int $tenantId, array $state): void
    {
        $this->set(
            $tenantId,
            'alerts',
            'server_down_state',
            json_encode($state, JSON_UNESCAPED_UNICODE),
            'json'
        );
    }

    private function tenantId(): int
    {
        return (int) (Session::getInstance()->get('tenant_id') ?? 1);
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, string> */
    private function loadGroup(int $tenantId, string $group): array
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT `key`, `value` FROM settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND `group` = ?',
                [$tenantId, $group]
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        return $out;
    }

    private function get(int $tenantId, string $group, string $key): ?string
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT value FROM settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND `group` = ? AND `key` = ? ORDER BY tenant_id DESC LIMIT 1',
                [$tenantId, $group, $key]
            );
        } catch (\Throwable) {
            return null;
        }

        return $row ? (string) $row['value'] : null;
    }

    private function set(int $tenantId, string $group, string $key, string $value, string $type): void
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, $group, $key]
        );

        if ($existing) {
            $db->update('settings', ['value' => $value, 'type' => $type], 'id = ?', [$existing['id']]);
        } else {
            $db->insert('settings', [
                'tenant_id' => $tenantId,
                'group' => $group,
                'key' => $key,
                'value' => $value,
                'type' => $type,
            ]);
        }
    }
}
