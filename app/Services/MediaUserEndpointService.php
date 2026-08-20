<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Media\SessionClientIp;
use Core\Database;
use Core\Logger;

/**
 * Historial de IPs y dispositivos por usuario media, para distinguir hogar y fuera.
 */
final class MediaUserEndpointService
{
    public const KIND_HOME = 'home';

    public const KIND_AWAY = 'away';

    public const KIND_UNKNOWN = 'unknown';

    /**
     * @param array<int, array<string, mixed>> $sessions
     */
    public function recordFromSessions(int $tenantId, array $sessions): void
    {
        $this->ensureTable();
        $now = date('Y-m-d H:i:s');
        $db = Database::getInstance();

        foreach ($sessions as $session) {
            $userId = (int) ($session['media_user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $publicIp = SessionClientIp::normalize((string) ($session['public_ip'] ?? $session['client_ip'] ?? ''));
            $lanIp = SessionClientIp::normalize((string) ($session['lan_ip'] ?? ''));
            $ip = $publicIp !== '' ? $publicIp : $lanIp;
            $deviceName = trim((string) ($session['player'] ?? ''));
            $product = trim((string) ($session['product'] ?? ''));
            $platform = trim((string) ($session['platform'] ?? ''));
            $machineId = trim((string) ($session['machine_id'] ?? ''));
            if ($ip === '' && $deviceName === '' && $machineId === '') {
                continue;
            }

            $location = SessionClientIp::classifyLocation(
                isset($session['location']) ? (string) $session['location'] : null,
                $ip,
                $lanIp
            );
            $deviceKey = self::deviceKey($ip, $machineId, $deviceName, $product, $platform);

            try {
                $existing = $db->fetchOne(
                    'SELECT id, kind, kind_locked FROM media_user_endpoints
                     WHERE media_user_id = ? AND ip = ? AND device_key = ? LIMIT 1',
                    [$userId, $ip, $deviceKey]
                );

                if ($existing) {
                    $kind = (string) ($existing['kind'] ?? self::KIND_UNKNOWN);
                    $locked = (int) ($existing['kind_locked'] ?? 0) === 1;
                    if (!$locked) {
                        $kind = $this->inferKind($userId, $ip, $location, $kind);
                    }
                    $db->query(
                        'UPDATE media_user_endpoints
                         SET lan_ip = ?, location = ?, device_name = ?, product = ?, platform = ?,
                             machine_id = ?, kind = ?, play_count = play_count + 1, last_seen_at = ?
                         WHERE id = ?',
                        [
                            $lanIp !== '' ? $lanIp : null,
                            $location,
                            $deviceName !== '' ? $deviceName : null,
                            $product !== '' ? $product : null,
                            $platform !== '' ? $platform : null,
                            $machineId !== '' ? $machineId : null,
                            $kind,
                            $now,
                            (int) $existing['id'],
                        ]
                    );
                    continue;
                }

                $kind = $this->inferKind($userId, $ip, $location, self::KIND_UNKNOWN);
                $db->insert('media_user_endpoints', [
                    'tenant_id' => $tenantId,
                    'media_user_id' => $userId,
                    'ip' => $ip,
                    'lan_ip' => $lanIp !== '' ? $lanIp : null,
                    'location' => $location,
                    'device_key' => $deviceKey,
                    'device_name' => $deviceName !== '' ? $deviceName : null,
                    'product' => $product !== '' ? $product : null,
                    'platform' => $platform !== '' ? $platform : null,
                    'machine_id' => $machineId !== '' ? $machineId : null,
                    'kind' => $kind,
                    'kind_locked' => 0,
                    'play_count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            } catch (\Throwable $e) {
                Logger::debug('No se pudo guardar endpoint de reproducción', [
                    'media_user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $mediaUserId): array
    {
        $this->ensureTable();
        try {
            return Database::getInstance()->fetchAll(
                'SELECT * FROM media_user_endpoints WHERE media_user_id = ? ORDER BY last_seen_at DESC, id DESC',
                [$mediaUserId]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setKind(int $tenantId, int $mediaUserId, int $endpointId, string $kind): bool
    {
        $kind = self::normalizeKind($kind);
        $this->ensureTable();
        $locked = $kind === self::KIND_UNKNOWN ? 0 : 1;
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT id FROM media_user_endpoints WHERE id = ? AND media_user_id = ? AND tenant_id = ? LIMIT 1',
            [$endpointId, $mediaUserId, $tenantId]
        );
        if (!$row) {
            return false;
        }
        $db->query(
            'UPDATE media_user_endpoints SET kind = ?, kind_locked = ?
             WHERE id = ? AND media_user_id = ? AND tenant_id = ?',
            [$kind, $locked, $endpointId, $mediaUserId, $tenantId]
        );

        return true;
    }

    public static function deviceKey(string $ip, string $machineId, string $deviceName, string $product, string $platform): string
    {
        $raw = strtolower(trim($ip) . '|' . trim($machineId) . '|' . trim($deviceName) . '|' . trim($product) . '|' . trim($platform));

        return substr(hash('sha256', $raw), 0, 40);
    }

    public static function normalizeKind(string $kind): string
    {
        return match ($kind) {
            self::KIND_HOME, 'hogar', 'casa' => self::KIND_HOME,
            self::KIND_AWAY, 'fuera' => self::KIND_AWAY,
            default => self::KIND_UNKNOWN,
        };
    }

    public static function inferKindFromLocation(string $location, string $current = self::KIND_UNKNOWN): string
    {
        if (strtoupper($location) === 'LAN') {
            return self::KIND_HOME;
        }

        return $current !== '' ? $current : self::KIND_UNKNOWN;
    }

    private function inferKind(int $mediaUserId, string $ip, string $location, string $current): string
    {
        $kind = self::inferKindFromLocation($location, $current);
        if ($kind === self::KIND_HOME) {
            return $kind;
        }
        if ($ip === '') {
            return $kind;
        }

        try {
            $home = Database::getInstance()->fetchOne(
                'SELECT id FROM media_user_endpoints
                 WHERE media_user_id = ? AND ip = ? AND kind = ? LIMIT 1',
                [$mediaUserId, $ip, self::KIND_HOME]
            );
            if ($home) {
                return self::KIND_HOME;
            }
        } catch (\Throwable) {
        }

        return $kind;
    }

    public function ensureTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT 1 AS ok FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                ['media_user_endpoints']
            );
            if ($row === null) {
                (new \Core\Updater())->runMigrations();
            }
        } catch (\Throwable) {
        }
        $ensured = true;
    }
}
