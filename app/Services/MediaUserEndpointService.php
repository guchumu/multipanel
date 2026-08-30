<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Media\SessionClientIp;
use Core\Cache;
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

        // Primero teles/Fire Stick para marcar su IP como casa antes de ver móviles.
        $ordered = $this->sessionsTvFirst($sessions);

        foreach ($ordered as $session) {
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
                        $kind = $this->inferKind($userId, $ip, $location, $kind, $product, $platform, $deviceName);
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

                $kind = $this->inferKind($userId, $ip, $location, self::KIND_UNKNOWN, $product, $platform, $deviceName);
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
     * @param array<int, array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    private function sessionsTvFirst(array $sessions): array
    {
        $tvs = [];
        $rest = [];
        foreach ($sessions as $session) {
            if (self::classifyDeviceClass($session) === 'tv') {
                $tvs[] = $session;
            } else {
                $rest[] = $session;
            }
        }

        return array_merge($tvs, $rest);
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

    /**
     * Marca casa/fuera desde una sesión en directo (upsert endpoint + bloqueo manual).
     *
     * @param array<string, mixed> $session
     * @return array{success: bool, message?: string, kind?: string, endpoint_id?: int}
     */
    public function setKindFromSession(int $tenantId, array $session, string $kind): array
    {
        $mediaUserId = (int) ($session['media_user_id'] ?? 0);
        $kind = self::normalizeKind($kind);
        if ($mediaUserId <= 0) {
            return ['success' => false, 'message' => 'Usuario no identificado en la sesión.'];
        }
        if ($kind === self::KIND_UNKNOWN) {
            return ['success' => false, 'message' => 'Tipo no válido.'];
        }

        $identity = $this->sessionIdentity($session);
        if ($identity === null) {
            return ['success' => false, 'message' => 'No hay IP ni dispositivo identificable.'];
        }

        $this->ensureTable();
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');

        try {
            $existing = $db->fetchOne(
                'SELECT id FROM media_user_endpoints
                 WHERE media_user_id = ? AND ip = ? AND device_key = ? LIMIT 1',
                [$mediaUserId, $identity['ip'], $identity['device_key']]
            );

            if ($existing) {
                $endpointId = (int) $existing['id'];
                $db->query(
                    'UPDATE media_user_endpoints
                     SET lan_ip = ?, location = ?, device_name = ?, product = ?, platform = ?,
                         machine_id = ?, kind = ?, kind_locked = 1, last_seen_at = ?
                     WHERE id = ?',
                    [
                        $identity['lan_ip'] !== '' ? $identity['lan_ip'] : null,
                        $identity['location'],
                        $identity['device_name'] !== '' ? $identity['device_name'] : null,
                        $identity['product'] !== '' ? $identity['product'] : null,
                        $identity['platform'] !== '' ? $identity['platform'] : null,
                        $identity['machine_id'] !== '' ? $identity['machine_id'] : null,
                        $kind,
                        $now,
                        $endpointId,
                    ]
                );
            } else {
                $endpointId = $db->insert('media_user_endpoints', [
                    'tenant_id' => $tenantId,
                    'media_user_id' => $mediaUserId,
                    'ip' => $identity['ip'],
                    'lan_ip' => $identity['lan_ip'] !== '' ? $identity['lan_ip'] : null,
                    'location' => $identity['location'],
                    'device_key' => $identity['device_key'],
                    'device_name' => $identity['device_name'] !== '' ? $identity['device_name'] : null,
                    'product' => $identity['product'] !== '' ? $identity['product'] : null,
                    'platform' => $identity['platform'] !== '' ? $identity['platform'] : null,
                    'machine_id' => $identity['machine_id'] !== '' ? $identity['machine_id'] : null,
                    'kind' => $kind,
                    'kind_locked' => 1,
                    'play_count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }

            $this->applyKindToIpAddresses(
                $tenantId,
                $mediaUserId,
                [$identity['ip'], $identity['lan_ip']],
                $kind,
                1
            );

            return [
                'success' => true,
                'kind' => $kind,
                'endpoint_id' => $endpointId,
                'message' => $kind === self::KIND_HOME ? 'Marcado como Casa.' : 'Marcado como Fuera.',
            ];
        } catch (\Throwable $e) {
            Logger::warning('setKindFromSession failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'No se pudo guardar.'];
        }
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    public function findEndpointForSession(int $mediaUserId, array $session): ?array
    {
        if ($mediaUserId <= 0) {
            return null;
        }

        $identity = $this->sessionIdentity($session);
        if ($identity === null) {
            return null;
        }

        $this->ensureTable();
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT id, kind, kind_locked FROM media_user_endpoints
                 WHERE media_user_id = ? AND ip = ? AND device_key = ? LIMIT 1',
                [$mediaUserId, $identity['ip'], $identity['device_key']]
            );

            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $session
     * @return array{
     *   ip: string,
     *   lan_ip: string,
     *   location: string,
     *   device_key: string,
     *   device_name: string,
     *   product: string,
     *   platform: string,
     *   machine_id: string
     * }|null
     */
    public function sessionIdentity(array $session): ?array
    {
        $publicIp = SessionClientIp::normalize((string) ($session['public_ip'] ?? $session['client_ip'] ?? ''));
        $lanIp = SessionClientIp::normalize((string) ($session['lan_ip'] ?? ''));
        $ip = $publicIp !== '' ? $publicIp : $lanIp;
        $deviceName = trim((string) ($session['player'] ?? ''));
        $product = trim((string) ($session['product'] ?? ''));
        $platform = trim((string) ($session['platform'] ?? ''));
        $machineId = trim((string) ($session['machine_id'] ?? ''));
        if ($ip === '' && $deviceName === '' && $machineId === '') {
            return null;
        }

        $location = SessionClientIp::classifyLocation(
            isset($session['location']) ? (string) $session['location'] : null,
            $ip !== '' ? $ip : $lanIp,
            $lanIp
        );

        return [
            'ip' => $ip !== '' ? $ip : 'unknown',
            'lan_ip' => $lanIp,
            'location' => $location,
            'device_key' => self::deviceKey($ip !== '' ? $ip : 'unknown', $machineId, $deviceName, $product, $platform),
            'device_name' => $deviceName,
            'product' => $product,
            'platform' => $platform,
            'machine_id' => $machineId,
        ];
    }

    public function setKind(int $tenantId, int $mediaUserId, int $endpointId, string $kind): bool
    {
        $kind = self::normalizeKind($kind);
        $this->ensureTable();
        $locked = $kind === self::KIND_UNKNOWN ? 0 : 1;
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT id, ip, lan_ip FROM media_user_endpoints WHERE id = ? AND media_user_id = ? AND tenant_id = ? LIMIT 1',
            [$endpointId, $mediaUserId, $tenantId]
        );
        if (!$row) {
            return false;
        }

        $this->applyKindToIpAddresses(
            $tenantId,
            $mediaUserId,
            [(string) ($row['ip'] ?? ''), (string) ($row['lan_ip'] ?? '')],
            $kind,
            $locked
        );

        Cache::forget('activity_snapshot_' . $tenantId);

        return true;
    }

    /**
     * Marca casa/fuera en todos los dispositivos que comparten la misma IP (pública o LAN).
     *
     * @param list<string> $ips
     */
    private function applyKindToIpAddresses(
        int $tenantId,
        int $mediaUserId,
        array $ips,
        string $kind,
        int $locked,
    ): void {
        $ips = array_values(array_unique(array_filter(array_map(
            static fn (string $ip): string => SessionClientIp::normalize($ip),
            $ips
        ), static fn (string $ip): bool => $ip !== '' && $ip !== 'unknown')));

        if ($ips === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ips), '?'));
        $params = array_merge([$kind, $locked, $mediaUserId, $tenantId], $ips, $ips);

        try {
            Database::getInstance()->query(
                "UPDATE media_user_endpoints
                 SET kind = ?, kind_locked = ?
                 WHERE media_user_id = ? AND tenant_id = ?
                   AND (ip IN ({$placeholders}) OR lan_ip IN ({$placeholders}))",
                $params
            );
        } catch (\Throwable $e) {
            Logger::warning('applyKindToIpAddresses failed', ['error' => $e->getMessage()]);
        }
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

    /**
     * IPs públicas/LAN de una sesión, para comparar con las marcadas como casa.
     *
     * @param array<string, mixed> $session
     * @return list<string>
     */
    public static function sessionIps(array $session): array
    {
        $publicIp = SessionClientIp::normalize((string) ($session['public_ip'] ?? $session['client_ip'] ?? ''));
        $lanIp = SessionClientIp::normalize((string) ($session['lan_ip'] ?? ''));
        $ips = [];
        if ($publicIp !== '') {
            $ips[] = $publicIp;
        }
        if ($lanIp !== '' && $lanIp !== $publicIp) {
            $ips[] = $lanIp;
        }

        return $ips;
    }

    private function inferKind(
        int $mediaUserId,
        string $ip,
        string $location,
        string $current,
        string $product = '',
        string $platform = '',
        string $deviceName = '',
    ): string {
        $deviceClass = self::classifyDeviceClass([
            'product' => $product,
            'platform' => $platform,
            'player' => $deviceName,
        ]);
        if ($deviceClass === 'tv') {
            return self::KIND_HOME;
        }

        if ($ip !== '' && $this->ipIsKnownHome($mediaUserId, $ip)) {
            return self::KIND_HOME;
        }

        if ($deviceClass === 'mobile') {
            if (strtoupper($location) === 'LAN' && $this->userHasHomeEndpoint($mediaUserId)) {
                return self::KIND_HOME;
            }

            return self::KIND_AWAY;
        }

        $kind = self::inferKindFromLocation($location, $current);
        if ($kind === self::KIND_HOME) {
            return $kind;
        }

        return $kind !== '' ? $kind : self::KIND_UNKNOWN;
    }

    private function ipIsKnownHome(int $mediaUserId, string $ip): bool
    {
        if ($ip === '' || $ip === 'unknown') {
            return false;
        }

        try {
            $home = Database::getInstance()->fetchOne(
                'SELECT id FROM media_user_endpoints
                 WHERE media_user_id = ? AND kind = ?
                   AND (ip = ? OR lan_ip = ?)
                 LIMIT 1',
                [$mediaUserId, self::KIND_HOME, $ip, $ip]
            );

            return $home !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function userHasHomeEndpoint(int $mediaUserId): bool
    {
        try {
            $home = Database::getInstance()->fetchOne(
                'SELECT id FROM media_user_endpoints
                 WHERE media_user_id = ? AND kind = ? LIMIT 1',
                [$mediaUserId, self::KIND_HOME]
            );

            return $home !== null;
        } catch (\Throwable) {
            return false;
        }
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

    /**
     * @param list<int> $userIds
     * @return array<int, list<string>>
     */
    public function homeIpsByUserIds(array $userIds): array
    {
        $this->ensureTable();
        $userIds = array_values(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0));
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT media_user_id, ip, lan_ip FROM media_user_endpoints
                 WHERE kind = ? AND media_user_id IN ({$placeholders})",
                array_merge([self::KIND_HOME], $userIds)
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row['media_user_id'];
            foreach ([(string) ($row['ip'] ?? ''), (string) ($row['lan_ip'] ?? '')] as $ipRaw) {
                $ip = SessionClientIp::normalize($ipRaw);
                if ($ip === '' || $ip === 'unknown') {
                    continue;
                }
                $out[$uid][] = $ip;
            }
        }
        foreach ($out as $uid => $ips) {
            $out[$uid] = array_values(array_unique($ips));
        }

        return $out;
    }

    /**
     * @param list<int> $userIds
     * @return array<int, list<string>>
     */
    public function awayIpsByUserIds(array $userIds): array
    {
        $this->ensureTable();
        $userIds = array_values(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0));
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT media_user_id, ip, lan_ip FROM media_user_endpoints
                 WHERE kind = ? AND kind_locked = 1 AND media_user_id IN ({$placeholders})",
                array_merge([self::KIND_AWAY], $userIds)
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row['media_user_id'];
            foreach ([(string) ($row['ip'] ?? ''), (string) ($row['lan_ip'] ?? '')] as $ipRaw) {
                $ip = SessionClientIp::normalize($ipRaw);
                if ($ip === '' || $ip === 'unknown') {
                    continue;
                }
                $out[$uid][] = $ip;
            }
        }
        foreach ($out as $uid => $ips) {
            $out[$uid] = array_values(array_unique($ips));
        }

        return $out;
    }

    /**
     * Añade las IPs de teles/Fire Stick de este lote (para que un móvil en la misma IP cuente casa al momento).
     *
     * @param array<int, array<string, mixed>> $sessions
     * @param array<int, list<string>> $homeIpsByUser
     * @return array<int, list<string>>
     */
    public function mergeSessionHomeIps(array $sessions, array $homeIpsByUser = []): array
    {
        foreach ($sessions as $session) {
            if (self::classifyDeviceClass($session) !== 'tv') {
                continue;
            }
            $uid = (int) ($session['media_user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            foreach (self::sessionIps($session) as $ip) {
                $homeIpsByUser[$uid][] = $ip;
            }
        }
        foreach ($homeIpsByUser as $uid => $ips) {
            $homeIpsByUser[$uid] = array_values(array_unique($ips));
        }

        return $homeIpsByUser;
    }

    /**
     * Fire Stick / tele → casa. Móvil (iPhone, Android, tablet) → fuera, salvo misma IP de casa.
     * PC/navegador u otro: se decide por LAN / IP marcada hogar.
     *
     * @param array<string, mixed> $session
     */
    public static function classifyDeviceClass(array $session): string
    {
        $blob = strtolower(trim(implode(' ', array_filter([
            (string) ($session['product'] ?? ''),
            (string) ($session['platform'] ?? ''),
            (string) ($session['player'] ?? ''),
            (string) ($session['device'] ?? ''),
        ], static fn (string $x): bool => trim($x) !== ''))));

        if ($blob === '') {
            return 'unknown';
        }

        $tvNeedles = [
            'fire tv', 'firestick', 'fire stick', 'amazon fire', 'aftv',
            'roku', 'apple tv', 'tvos', 'android tv', 'androidtv',
            'google tv', 'googletv', 'chromecast', 'shield',
            'tizen', 'webos', 'web os', 'smart tv', 'smarttv',
            'samsung tv', 'lg tv', 'hisense', 'vizio', 'bravia',
            'xbox', 'playstation', 'ps4', 'ps5', 'vidaa',
            'plex for samsung', 'plex for lg', 'plex for vizio',
            'plex for xbox', 'plex for roku',
        ];
        foreach ($tvNeedles as $needle) {
            if (str_contains($blob, $needle)) {
                return 'tv';
            }
        }

        $mobileNeedles = [
            'iphone', 'ipad', 'ipod', 'plex for ios', 'plex android',
            'plex for android', 'android mobile', 'mobile safari',
        ];
        foreach ($mobileNeedles as $needle) {
            if (str_contains($blob, $needle)) {
                return 'mobile';
            }
        }

        if (str_contains($blob, 'android') && !str_contains($blob, 'tv')) {
            return 'mobile';
        }
        if (preg_match('/(^|[^a-z])ios([^a-z]|$)/', $blob) === 1) {
            return 'mobile';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $session
     * @param list<string> $homeIps
     * @param list<string> $awayIps
     * @return array{kind: string, source: string, device_class: string, endpoint_id: ?int}
     */
    public function classifyPlaybackMeta(
        array $session,
        array $homeIps = [],
        ?int $mediaUserId = null,
        array $awayIps = [],
    ): array {
        $mediaUserId ??= (int) ($session['media_user_id'] ?? 0);
        $endpointId = null;
        $deviceClass = self::classifyDeviceClass($session);

        // La IP marcada como hogar manda: iPhone, PC, etc. en la misma IP cuentan como casa.
        if (self::sessionHasHomeIp($session, $homeIps)) {
            return ['kind' => self::KIND_HOME, 'source' => 'home_ip', 'device_class' => $deviceClass, 'endpoint_id' => $endpointId];
        }

        if (self::sessionHasAwayIp($session, $awayIps)) {
            return ['kind' => self::KIND_AWAY, 'source' => 'away_ip', 'device_class' => $deviceClass, 'endpoint_id' => $endpointId];
        }

        if ($mediaUserId > 0) {
            $endpoint = $this->findEndpointForSession($mediaUserId, $session);
            if ($endpoint !== null) {
                $endpointId = (int) $endpoint['id'];
                if ((int) ($endpoint['kind_locked'] ?? 0) === 1) {
                    $kind = self::normalizeKind((string) ($endpoint['kind'] ?? self::KIND_UNKNOWN));
                    if ($kind !== self::KIND_UNKNOWN) {
                        return [
                            'kind' => $kind,
                            'source' => 'manual',
                            'device_class' => $deviceClass,
                            'endpoint_id' => $endpointId,
                        ];
                    }
                }
            }
        }

        if ($deviceClass === 'tv') {
            return ['kind' => self::KIND_HOME, 'source' => 'device_tv', 'device_class' => $deviceClass, 'endpoint_id' => $endpointId];
        }

        $publicIp = SessionClientIp::normalize((string) ($session['public_ip'] ?? $session['client_ip'] ?? ''));
        $lanIp = SessionClientIp::normalize((string) ($session['lan_ip'] ?? ''));
        $location = SessionClientIp::classifyLocation(
            isset($session['location']) ? (string) $session['location'] : null,
            $publicIp !== '' ? $publicIp : $lanIp,
            $lanIp
        );

        if ($deviceClass === 'mobile') {
            if ($location === 'LAN' && $homeIps !== []) {
                return ['kind' => self::KIND_HOME, 'source' => 'home_ip', 'device_class' => $deviceClass, 'endpoint_id' => $endpointId];
            }

            return ['kind' => self::KIND_AWAY, 'source' => 'device_mobile', 'device_class' => $deviceClass, 'endpoint_id' => $endpointId];
        }

        if ($location === 'LAN') {
            return ['kind' => self::KIND_HOME, 'source' => 'lan', 'device_class' => $deviceClass, 'endpoint_id' => $endpointId];
        }

        return ['kind' => self::KIND_AWAY, 'source' => 'wan', 'device_class' => $deviceClass, 'endpoint_id' => $endpointId];
    }

    /**
     * @param array<string, mixed> $session
     * @param list<string> $homeIps
     */
    public static function sessionHasHomeIp(array $session, array $homeIps): bool
    {
        if ($homeIps === []) {
            return false;
        }
        foreach (self::sessionIps($session) as $ip) {
            if (in_array($ip, $homeIps, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $session
     * @param list<string> $awayIps
     */
    public static function sessionHasAwayIp(array $session, array $awayIps): bool
    {
        if ($awayIps === []) {
            return false;
        }
        foreach (self::sessionIps($session) as $ip) {
            if (in_array($ip, $awayIps, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $session
     * @param list<string> $homeIps
     */
    public function classifyPlayback(array $session, array $homeIps = []): string
    {
        return $this->classifyPlaybackMeta($session, $homeIps)['kind'];
    }
}
