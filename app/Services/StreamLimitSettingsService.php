<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Tenant settings for concurrent stream limit enforcement.
 *
 * Group: streams
 * Keys: enforcement_enabled, default_max_streams, default_max_away_streams,
 * kill_message, kill_message_video_transcode, count_mode, sandbox_alerts,
 * auto_kill_video_transcodes
 */
final class StreamLimitSettingsService
{
    public const GROUP = 'streams';

    public const DEFAULT_MAX_STREAMS = 2;

    public const DEFAULT_MAX_AWAY = 0;

    public const COUNT_MODE_DISTINCT_IP = 'distinct_ip';

    public const COUNT_MODE_SESSIONS = 'sessions';

    public const COUNT_MODE_HOUSEHOLD = 'household';

    public const DEFAULT_KILL_MESSAGE = ClientStopGuidanceService::MSG_GENERIC_LIMIT;

    public const DEFAULT_KILL_HOME = ClientStopGuidanceService::MSG_HOME_LIMIT;

    public const DEFAULT_KILL_AWAY = ClientStopGuidanceService::MSG_AWAY_LIMIT;

    public const DEFAULT_KILL_VIDEO_TRANSCODE = ClientStopGuidanceService::MSG_SALVABLE_TRANSCODE;
    public function isEnforcementEnabled(int $tenantId): bool
    {
        $value = $this->get($tenantId, 'enforcement_enabled');

        return $value === '1' || $value === 'true' || $value === 'yes';
    }

    public function setEnforcementEnabled(int $tenantId, bool $enabled): void
    {
        $this->set($tenantId, 'enforcement_enabled', $enabled ? '1' : '0', 'boolean');
    }

    /** Auto-corta sesiones cuyo vídeo está en «transcode» (no audio-only ni Direct Play). */
    public function isAutoKillVideoTranscodesEnabled(int $tenantId): bool
    {
        $value = $this->get($tenantId, 'auto_kill_video_transcodes');

        return $value === '1' || $value === 'true' || $value === 'yes';
    }

    public function setAutoKillVideoTranscodesEnabled(int $tenantId, bool $enabled): void
    {
        $this->set($tenantId, 'auto_kill_video_transcodes', $enabled ? '1' : '0', 'boolean');
    }

    public function getDefaultMaxStreams(int $tenantId): int
    {
        $value = $this->get($tenantId, 'default_max_streams');
        $n = $value !== null ? (int) $value : self::DEFAULT_MAX_STREAMS;

        return max(1, min(50, $n));
    }

    public function setDefaultMaxStreams(int $tenantId, int $max): void
    {
        $this->set($tenantId, 'default_max_streams', (string) max(1, min(50, $max)), 'integer');
    }

    public function getDefaultMaxAwayStreams(int $tenantId): int
    {
        $value = $this->get($tenantId, 'default_max_away_streams');
        if ($value === null || trim($value) === '') {
            return self::DEFAULT_MAX_AWAY;
        }

        return max(0, min(20, (int) $value));
    }

    public function setDefaultMaxAwayStreams(int $tenantId, int $max): void
    {
        $this->set($tenantId, 'default_max_away_streams', (string) max(0, min(20, $max)), 'integer');
    }

    public function sandboxAlertsEnabled(int $tenantId): bool
    {
        $value = $this->get($tenantId, 'sandbox_alerts');
        if ($value === null || trim($value) === '') {
            return true;
        }

        return $value === '1' || $value === 'true' || $value === 'yes';
    }

    public function setSandboxAlertsEnabled(int $tenantId, bool $enabled): void
    {
        $this->set($tenantId, 'sandbox_alerts', $enabled ? '1' : '0', 'boolean');
    }

    public function getKillMessage(int $tenantId): string
    {
        $custom = $this->get($tenantId, 'kill_message');
        if ($custom !== null && trim($custom) !== '') {
            return trim($custom);
        }

        return ClientStopGuidanceService::forGenericStreamLimit();
    }

    public function getKillMessageHome(int $tenantId): string
    {
        $custom = $this->get($tenantId, 'kill_message_home');
        if ($custom !== null && trim($custom) !== '') {
            return trim($custom);
        }

        return ClientStopGuidanceService::forHomeLimit();
    }

    public function getKillMessageAway(int $tenantId): string
    {
        $custom = $this->get($tenantId, 'kill_message_away');
        if ($custom !== null && trim($custom) !== '') {
            return trim($custom);
        }

        return ClientStopGuidanceService::forAwayLimit();
    }

    public function setKillMessage(int $tenantId, ?string $message): void
    {
        $message = $message !== null ? trim($message) : '';
        $this->set($tenantId, 'kill_message', $message, 'string');
    }

    /** Mensaje al cliente en auto-corte / «Cortar ahora» de Transcode salvable. */
    public function getKillMessageVideoTranscode(int $tenantId): string
    {
        $custom = $this->get($tenantId, 'kill_message_video_transcode');
        if ($custom !== null && trim($custom) !== '') {
            return mb_substr(trim($custom), 0, 500);
        }

        return ClientStopGuidanceService::forSalvableTranscode();
    }

    /** Valor guardado (vacío = usar el texto por defecto del sistema). */
    public function getStoredKillMessageVideoTranscode(int $tenantId): string
    {
        return trim((string) ($this->get($tenantId, 'kill_message_video_transcode') ?? ''));
    }

    public function setKillMessageVideoTranscode(int $tenantId, ?string $message): void
    {
        $message = $message !== null ? trim($message) : '';
        if (mb_strlen($message) > 500) {
            $message = mb_substr($message, 0, 500);
        }
        $this->set($tenantId, 'kill_message_video_transcode', $message, 'string');
    }

    /**
     * household (recomendado): teles en casa vs fuera.
     * sessions: cada sesión cuenta (sin distinguir hogar).
     * distinct_ip: legado (IPs distintas).
     */
    public function getCountMode(int $tenantId): string
    {
        $value = trim((string) ($this->get($tenantId, 'count_mode') ?? ''));
        if ($value === self::COUNT_MODE_SESSIONS) {
            return self::COUNT_MODE_SESSIONS;
        }

        return self::COUNT_MODE_HOUSEHOLD;
    }

    public function setCountMode(int $tenantId, string $mode): void
    {
        $mode = $mode === self::COUNT_MODE_SESSIONS
            ? self::COUNT_MODE_SESSIONS
            : self::COUNT_MODE_HOUSEHOLD;
        $this->set($tenantId, 'count_mode', $mode, 'string');
    }

    /**
     * Effective home (in-house) limit: max_home_streams → max_streams → tenant default.
     */
    public function resolveHomeLimitForUser(int $tenantId, mixed $maxHome, mixed $maxStreams = null): int
    {
        if ($maxHome !== null && $maxHome !== '') {
            return max(1, min(50, (int) $maxHome));
        }

        return $this->resolveLimitForUser($tenantId, $maxStreams);
    }

    public function resolveAwayLimitForUser(int $tenantId, mixed $maxAway): int
    {
        if ($maxAway !== null && $maxAway !== '') {
            return max(0, min(20, (int) $maxAway));
        }

        return $this->getDefaultMaxAwayStreams($tenantId);
    }

    /**
     * Effective limit for a media user: per-user override or tenant default.
     */
    public function resolveLimitForUser(int $tenantId, mixed $maxStreams): int
    {
        if ($maxStreams === null || $maxStreams === '') {
            return $this->getDefaultMaxStreams($tenantId);
        }

        return max(1, min(50, (int) $maxStreams));
    }

    /**
     * @return array{
     *   enforcement_enabled: bool,
     *   default_max_streams: int,
     *   default_max_away_streams: int,
     *   kill_message: string,
     *   kill_message_video_transcode: string,
     *   count_mode: string,
     *   sandbox_alerts: bool,
     *   auto_kill_video_transcodes: bool
     * }
     */
    public function all(int $tenantId): array
    {
        return [
            'enforcement_enabled' => $this->isEnforcementEnabled($tenantId),
            'default_max_streams' => $this->getDefaultMaxStreams($tenantId),
            'default_max_away_streams' => $this->getDefaultMaxAwayStreams($tenantId),
            'kill_message' => (string) ($this->get($tenantId, 'kill_message') ?? ''),
            'kill_message_video_transcode' => $this->getStoredKillMessageVideoTranscode($tenantId),
            'count_mode' => $this->getCountMode($tenantId),
            'sandbox_alerts' => $this->sandboxAlertsEnabled($tenantId),
            'auto_kill_video_transcodes' => $this->isAutoKillVideoTranscodesEnabled($tenantId),
        ];
    }

    private function get(int $tenantId, string $key): ?string
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT value FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ? LIMIT 1',
            [$tenantId, self::GROUP, $key]
        );

        if ($row) {
            return (string) $row['value'];
        }

        // Fallback legado: filas globales (tenant_id NULL).
        $legacy = Database::getInstance()->fetchOne(
            'SELECT value FROM settings WHERE tenant_id IS NULL AND `group` = ? AND `key` = ? LIMIT 1',
            [self::GROUP, $key]
        );

        return $legacy ? (string) $legacy['value'] : null;
    }

    private function set(int $tenantId, string $key, string $value, string $type): void
    {
        $db = Database::getInstance();

        // Upsert atómico: evita carreras y fallos silenciosos si el SELECT previo no ve la fila.
        $db->query(
            'INSERT INTO `settings` (`tenant_id`, `group`, `key`, `value`, `type`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)',
            [$tenantId, self::GROUP, $key, $value, $type]
        );

        // Si el UNIQUE no incluye tenant (esquema antiguo), forzar update/insert clásico.
        $stored = $this->get($tenantId, $key);
        if ($stored === $value) {
            return;
        }

        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, self::GROUP, $key]
        );

        if ($existing) {
            $db->update('settings', ['value' => $value, 'type' => $type], 'id = ?', [(int) $existing['id']]);
        } else {
            $db->insert('settings', [
                'tenant_id' => $tenantId,
                'group' => self::GROUP,
                'key' => $key,
                'value' => $value,
                'type' => $type,
            ]);
        }

        $stored = $this->get($tenantId, $key);
        if ($stored !== $value) {
            throw new \RuntimeException(
                "No se pudo persistir settings.{$key} (esperado={$value}, leído=" . var_export($stored, true) . ')'
            );
        }
    }
}
