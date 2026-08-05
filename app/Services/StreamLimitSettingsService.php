<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Tenant settings for concurrent stream limit enforcement.
 *
 * Group: streams
 * Keys: enforcement_enabled, default_max_streams, kill_message
 */
final class StreamLimitSettingsService
{
    public const GROUP = 'streams';

    public const DEFAULT_MAX_STREAMS = 2;

    public const DEFAULT_KILL_MESSAGE = 'Se ha superado el límite de reproducciones simultáneas. Se ha cortado una emisión adicional.';

    public function isEnforcementEnabled(int $tenantId): bool
    {
        $value = $this->get($tenantId, 'enforcement_enabled');

        return $value === '1' || $value === 'true' || $value === 'yes';
    }

    public function setEnforcementEnabled(int $tenantId, bool $enabled): void
    {
        $this->set($tenantId, 'enforcement_enabled', $enabled ? '1' : '0', 'boolean');
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

    public function getKillMessage(int $tenantId): string
    {
        $custom = $this->get($tenantId, 'kill_message');
        if ($custom !== null && trim($custom) !== '') {
            return trim($custom);
        }

        try {
            $presets = (new PlaybackStopMessageService())->listForTenant($tenantId);
            foreach ($presets as $preset) {
                if ((int) ($preset['is_default'] ?? 0) === 1 && trim((string) $preset['body']) !== '') {
                    return trim((string) $preset['body']);
                }
            }
            if ($presets !== [] && trim((string) ($presets[0]['body'] ?? '')) !== '') {
                return trim((string) $presets[0]['body']);
            }
        } catch (\Throwable) {
            // Fall through to default.
        }

        return self::DEFAULT_KILL_MESSAGE;
    }

    public function setKillMessage(int $tenantId, ?string $message): void
    {
        $message = $message !== null ? trim($message) : '';
        $this->set($tenantId, 'kill_message', $message, 'string');
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

    /** @return array{enforcement_enabled: bool, default_max_streams: int, kill_message: string} */
    public function all(int $tenantId): array
    {
        return [
            'enforcement_enabled' => $this->isEnforcementEnabled($tenantId),
            'default_max_streams' => $this->getDefaultMaxStreams($tenantId),
            'kill_message' => (string) ($this->get($tenantId, 'kill_message') ?? ''),
        ];
    }

    private function get(int $tenantId, string $key): ?string
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT value FROM settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND `group` = ? AND `key` = ? ORDER BY tenant_id DESC LIMIT 1',
            [$tenantId, self::GROUP, $key]
        );

        return $row ? (string) $row['value'] : null;
    }

    private function set(int $tenantId, string $key, string $value, string $type): void
    {
        $db = Database::getInstance();
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
    }
}
