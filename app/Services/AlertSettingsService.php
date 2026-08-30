<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Session;
use App\Services\TelegramConfig;
use App\Services\MailConfig;

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

    public function whatsappCloudToken(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $token = trim((string) ($alerts['whatsapp_cloud_token'] ?? ''));
        if ($token === '') {
            $token = trim((string) config('alerts.whatsapp_cloud_token', env('WHATSAPP_CLOUD_TOKEN', '')));
        }

        return $token;
    }

    public function whatsappCloudPhoneId(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $id = trim((string) ($alerts['whatsapp_cloud_phone_id'] ?? ''));
        if ($id === '') {
            $id = trim((string) config('alerts.whatsapp_cloud_phone_id', env('WHATSAPP_CLOUD_PHONE_ID', '')));
        }

        return $id;
    }

    public function whatsappCloudDisplayPhone(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $phone = trim((string) ($alerts['whatsapp_cloud_display_phone'] ?? ''));
        if ($phone === '') {
            $phone = trim((string) config('alerts.whatsapp_cloud_display_phone', env('WHATSAPP_CLOUD_DISPLAY_PHONE', '')));
        }

        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public function whatsappCloudVerifyToken(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $token = trim((string) ($alerts['whatsapp_cloud_verify_token'] ?? ''));
        if ($token === '') {
            $token = trim((string) config('alerts.whatsapp_cloud_verify_token', env('WHATSAPP_CLOUD_VERIFY_TOKEN', '')));
        }

        return $token;
    }

    public function whatsappClientAlertsEnabled(?int $tenantId = null): bool
    {
        return $this->alertFlag('whatsapp_client_alerts', true, $tenantId);
    }

    public function whatsappCloudConfigured(?int $tenantId = null): bool
    {
        return $this->whatsappCloudToken($tenantId) !== ''
            && $this->whatsappCloudPhoneId($tenantId) !== '';
    }

    /** Bot token + chat admin (no sandbox): necesario para alertas de servidor caído. */
    public function telegramConfigured(?int $tenantId = null): bool
    {
        $tenantId ??= $this->tenantId();
        $cfg = TelegramConfig::forTenant($tenantId);

        return trim((string) ($cfg['bot_token'] ?? '')) !== ''
            && trim((string) ($cfg['admin_chat_id'] ?? '')) !== '';
    }

    /** SMTP host + destinatario de alertas. */
    public function emailConfigured(?int $tenantId = null): bool
    {
        $tenantId ??= $this->tenantId();
        $mail = MailConfig::forTenant($tenantId);
        $to = $this->alertEmail($tenantId);

        return trim((string) ($mail['host'] ?? '')) !== '' && $to !== '';
    }

    /**
     * Preferencias por tipo de aviso admin.
     * WhatsApp: digest + server-down + alta ON por defecto; renovación OFF.
     * Telegram: digest + ciclo de vida + server-down ON por defecto.
     * Clave ausente en DB → default; valor explícito 0/1 se respeta.
     */
    public function whatsappNotifyAlta(?int $tenantId = null): bool
    {
        return $this->alertFlag('whatsapp_notify_alta', true, $tenantId);
    }

    public function whatsappNotifyRenew(?int $tenantId = null): bool
    {
        return $this->alertFlag('whatsapp_notify_renew', false, $tenantId);
    }

    public function whatsappNotifyServerDown(?int $tenantId = null): bool
    {
        return $this->alertFlag('whatsapp_notify_server_down', true, $tenantId);
    }

    public function whatsappNotifyDigest(?int $tenantId = null): bool
    {
        return $this->alertFlag('whatsapp_notify_digest', true, $tenantId);
    }

    public function telegramNotifyAlta(?int $tenantId = null): bool
    {
        return $this->alertFlag('telegram_notify_alta', true, $tenantId);
    }

    public function telegramNotifyRenew(?int $tenantId = null): bool
    {
        return $this->alertFlag('telegram_notify_renew', true, $tenantId);
    }

    public function telegramNotifyServerDown(?int $tenantId = null): bool
    {
        return $this->alertFlag('telegram_notify_server_down', true, $tenantId);
    }

    public function telegramNotifyDigest(?int $tenantId = null): bool
    {
        return $this->alertFlag('telegram_notify_digest', true, $tenantId);
    }

    public function emailNotifyServerDown(?int $tenantId = null): bool
    {
        return $this->alertFlag('email_notify_server_down', true, $tenantId);
    }

    /** Telegram para incumplimientos de streams / eventos críticos. Default ON. */
    public function telegramNotifyCritical(?int $tenantId = null): bool
    {
        return $this->alertFlag('telegram_notify_critical', true, $tenantId);
    }

    /** WhatsApp para eventos críticos (streams, sync FAIL, cron). Default ON. */
    public function whatsappNotifyCritical(?int $tenantId = null): bool
    {
        return $this->alertFlag('whatsapp_notify_critical', true, $tenantId);
    }

    /** Email para eventos críticos. Default ON. */
    public function emailNotifyCritical(?int $tenantId = null): bool
    {
        return $this->alertFlag('email_notify_critical', true, $tenantId);
    }

    public function ntfyEnabled(?int $tenantId = null): bool
    {
        return $this->alertFlag('ntfy_enabled', false, $tenantId);
    }

    public function ntfyServer(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $server = trim((string) ($alerts['ntfy_server'] ?? ''));
        if ($server === '') {
            $server = trim((string) config('alerts.ntfy_server', env('NTFY_SERVER', 'https://ntfy.sh')));
        }

        return rtrim($server, '/');
    }

    public function ntfyTopic(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $topic = trim((string) ($alerts['ntfy_topic'] ?? ''));
        if ($topic === '') {
            $topic = trim((string) config('alerts.ntfy_topic', env('NTFY_TOPIC', '')));
        }

        return $topic;
    }

    public function ntfyToken(?int $tenantId = null): string
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        $token = trim((string) ($alerts['ntfy_token'] ?? ''));
        if ($token === '') {
            $token = trim((string) config('alerts.ntfy_token', env('NTFY_TOKEN', '')));
        }

        return $token;
    }

    public function ntfyConfigured(?int $tenantId = null): bool
    {
        return $this->ntfyEnabled($tenantId)
            && $this->ntfyTopic($tenantId) !== ''
            && preg_match('#^https?://#i', $this->ntfyServer($tenantId)) === 1;
    }

    public function ntfyNotifyAlta(?int $tenantId = null): bool
    {
        return $this->alertFlag('ntfy_notify_alta', true, $tenantId);
    }

    public function ntfyNotifyRenew(?int $tenantId = null): bool
    {
        return $this->alertFlag('ntfy_notify_renew', false, $tenantId);
    }

    public function ntfyNotifyServerDown(?int $tenantId = null): bool
    {
        return $this->alertFlag('ntfy_notify_server_down', true, $tenantId);
    }

    public function ntfyNotifyDigest(?int $tenantId = null): bool
    {
        return $this->alertFlag('ntfy_notify_digest', true, $tenantId);
    }

    public function ntfyNotifyCritical(?int $tenantId = null): bool
    {
        return $this->alertFlag('ntfy_notify_critical', true, $tenantId);
    }

    /**
     * Debounce de alertas críticas (fingerprint → last_sent_at UTC).
     *
     * @return array<string, array{last_sent_at: string, title?: string}>
     */
    public function getCriticalAlertState(int $tenantId): array
    {
        $raw = $this->get($tenantId, 'alerts', 'critical_alert_state');
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array{last_sent_at: string, title?: string}> $state */
    public function saveCriticalAlertState(int $tenantId, array $state): void
    {
        // Conservar como máximo ~200 fingerprints recientes.
        if (count($state) > 200) {
            uasort($state, static fn ($a, $b): int => strcmp(
                (string) ($b['last_sent_at'] ?? ''),
                (string) ($a['last_sent_at'] ?? '')
            ));
            $state = array_slice($state, 0, 200, true);
        }
        $this->set(
            $tenantId,
            'alerts',
            'critical_alert_state',
            json_encode($state, JSON_UNESCAPED_UNICODE),
            'json'
        );
    }

    /**
     * Misma ventana que avisos de caducidad (hora configurada en Europe/Madrid por defecto).
     *
     * @param array{hour: int, timezone: string, window_minutes?: int}|null $schedule
     */
    public function isWithinExpiryNotifyWindow(?array $schedule = null, ?int $tenantId = null): bool
    {
        $schedule ??= $this->expiryNotifySchedule($tenantId);
        try {
            $tz = new \DateTimeZone($schedule['timezone']);
        } catch (\Throwable) {
            $tz = new \DateTimeZone(self::DEFAULT_EXPIRY_TZ);
        }

        $now = new \DateTimeImmutable('now', $tz);
        $hour = (int) $now->format('G');
        $targetHour = (int) $schedule['hour'];

        return $hour === $targetHour;
    }

    public function digestLastSentDate(?int $tenantId = null): ?string
    {
        $tenantId ??= $this->tenantId();
        $raw = $this->get($tenantId, 'alerts', 'digest_last_sent');
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return substr(trim($raw), 0, 10);
    }

    public function markDigestSent(string $dateYmd, ?int $tenantId = null): void
    {
        $tenantId ??= $this->tenantId();
        $this->set($tenantId, 'alerts', 'digest_last_sent', substr($dateYmd, 0, 10), 'string');
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

    private function alertFlag(string $key, bool $default, ?int $tenantId = null): bool
    {
        $tenantId ??= $this->tenantId();
        $alerts = $this->loadGroup($tenantId, 'alerts');
        if (array_key_exists($key, $alerts) && trim($alerts[$key]) !== '') {
            return $this->truthy($alerts[$key]);
        }

        $fromConfig = config('alerts.' . $key, null);
        if ($fromConfig !== null && $fromConfig !== '') {
            return is_bool($fromConfig) ? $fromConfig : $this->truthy($fromConfig);
        }

        return $default;
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
