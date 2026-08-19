<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use Core\Cache;
use Core\Database;
use Core\Logger;
use Core\Updater;

/**
 * Enlaces mágicos al portal: /u/{code} inicia sesión sin contraseña.
 */
final class PortalLoginLinkService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private const CODE_LENGTH = 22;

    public const DEFAULT_TTL_DAYS = 30;

    public const MAX_TTL_DAYS = 365;

    private const RATE_TTL = 3600;

    private const RATE_MAX = 40;

    /**
     * @return array{success: bool, url?: string, expires_at?: string, purpose?: string, message: string}
     */
    public function create(MediaUser $user, string $purpose = 'home', int $ttlDays = self::DEFAULT_TTL_DAYS): array
    {
        $purpose = $this->normalizePurpose($purpose);
        $ttlDays = max(1, min(self::MAX_TTL_DAYS, $ttlDays));
        $base = $this->publicBaseUrl();
        if ($base === null) {
            return ['success' => false, 'message' => 'APP_URL no es una URL pública válida.'];
        }

        self::ensureTable();
        $this->revokeActive((int) $user->id);

        $code = $this->allocateUniqueCode();
        $expires = (new \DateTimeImmutable('now'))->modify('+' . $ttlDays . ' days');

        try {
            Database::getInstance()->insert('portal_login_links', [
                'tenant_id' => (int) ($user->tenant_id ?? 1),
                'media_user_id' => (int) $user->id,
                'token_hash' => self::hashCode($code),
                'purpose' => $purpose,
                'expires_at' => $expires->format('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Portal login link create failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'No se pudo crear el enlace.'];
        }

        AuditService::log('media_user.portal_link_created', 'media_user', (int) $user->id, null, [
            'purpose' => $purpose,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'ttl_days' => $ttlDays,
        ]);

        return [
            'success' => true,
            'url' => $base . '/u/' . $code,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'purpose' => $purpose,
            'message' => 'Enlace listo. Cópialo ahora: si lo pierdes, genera otro (el anterior deja de valer).',
        ];
    }

    /**
     * @return array{ok: bool, user?: MediaUser, redirect?: string, error?: string}
     */
    public function consume(string $code, ?string $clientIp = null): array
    {
        if (!$this->allowAttempt($clientIp)) {
            return ['ok' => false, 'error' => 'Demasiados intentos. Espera un rato.'];
        }

        $code = trim($code);
        if (!self::isValidCode($code)) {
            return ['ok' => false, 'error' => 'Enlace no válido.'];
        }

        self::ensureTable();
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT * FROM portal_login_links WHERE token_hash = ? LIMIT 1',
                [self::hashCode($code)]
            );
        } catch (\Throwable $e) {
            Logger::warning('Portal login link lookup failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'Enlace no disponible.'];
        }

        if ($row === null) {
            return ['ok' => false, 'error' => 'Este enlace no existe o ya no vale.'];
        }

        if (!empty($row['revoked_at'])) {
            return ['ok' => false, 'error' => 'Este enlace se ha cancelado. Pide uno nuevo.'];
        }

        $expiresTs = strtotime((string) ($row['expires_at'] ?? ''));
        if ($expiresTs === false || $expiresTs < time()) {
            return ['ok' => false, 'error' => 'Este enlace ha caducado. Pide uno nuevo.'];
        }

        $user = MediaUser::find((int) $row['media_user_id']);
        if ($user === null || $user->deleted_at) {
            return ['ok' => false, 'error' => 'La cuenta ya no está disponible.'];
        }

        $status = strtolower(trim((string) ($user->status ?? '')));
        if (in_array($status, ['blocked', 'deleted'], true)) {
            return ['ok' => false, 'error' => 'Tu cuenta está bloqueada. Contacta con soporte.'];
        }

        try {
            Database::getInstance()->update(
                'portal_login_links',
                [
                    'last_used_at' => date('Y-m-d H:i:s'),
                    'use_count' => (int) ($row['use_count'] ?? 0) + 1,
                ],
                'id = ?',
                [(int) $row['id']]
            );
        } catch (\Throwable) {
        }

        Logger::info('Portal magic link used', [
            'media_user_id' => $user->id,
            'purpose' => $row['purpose'] ?? 'home',
        ]);

        return [
            'ok' => true,
            'user' => $user,
            'redirect' => $this->redirectForPurpose((string) ($row['purpose'] ?? 'home')),
        ];
    }

    public function revokeActive(int $mediaUserId): int
    {
        self::ensureTable();
        try {
            $stmt = Database::getInstance()->query(
                'UPDATE portal_login_links
                 SET revoked_at = ?
                 WHERE media_user_id = ? AND revoked_at IS NULL AND expires_at > ?',
                [date('Y-m-d H:i:s'), $mediaUserId, date('Y-m-d H:i:s')]
            );

            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array{has_active: bool, expires_at: ?string, purpose: ?string, use_count: int} */
    public function activeInfo(int $mediaUserId): array
    {
        self::ensureTable();
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT expires_at, purpose, use_count FROM portal_login_links
                 WHERE media_user_id = ? AND revoked_at IS NULL AND expires_at > ?
                 ORDER BY id DESC LIMIT 1',
                [$mediaUserId, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable) {
            $row = null;
        }

        if ($row === null) {
            return ['has_active' => false, 'expires_at' => null, 'purpose' => null, 'use_count' => 0];
        }

        return [
            'has_active' => true,
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'purpose' => (string) ($row['purpose'] ?? 'home'),
            'use_count' => (int) ($row['use_count'] ?? 0),
        ];
    }

    public static function isValidCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{16,48}$/', $code);
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    public static function generateCode(int $length = self::CODE_LENGTH): string
    {
        $length = max(16, min(48, $length));
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    public function normalizePurpose(string $purpose): string
    {
        $purpose = strtolower(trim($purpose));

        return $purpose === 'pay' ? 'pay' : 'home';
    }

    public function redirectForPurpose(string $purpose): string
    {
        return $this->normalizePurpose($purpose) === 'pay' ? '/portal/subscription' : '/portal';
    }

    private function allocateUniqueCode(): string
    {
        $db = Database::getInstance();
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = self::generateCode();
            $existing = $db->fetchOne(
                'SELECT id FROM portal_login_links WHERE token_hash = ? LIMIT 1',
                [self::hashCode($code)]
            );
            if ($existing === null) {
                return $code;
            }
        }

        return self::generateCode(32);
    }

    private function allowAttempt(?string $clientIp): bool
    {
        $ip = trim((string) $clientIp);
        if ($ip === '') {
            return true;
        }

        $key = 'portal_magic_ip_' . hash('sha256', $ip);
        $count = (int) (Cache::get($key) ?? 0);
        if ($count >= self::RATE_MAX) {
            return false;
        }
        Cache::set($key, $count + 1, self::RATE_TTL);

        return true;
    }

    private function publicBaseUrl(): ?string
    {
        $configured = rtrim((string) config('app.url', env('APP_URL', '')), '/');
        $configuredLooksLocal = $configured === ''
            || str_contains($configured, 'localhost')
            || str_contains($configured, '127.0.0.1');

        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $host = trim(explode(',', $host)[0]);

        if ($host !== '' && $configuredLooksLocal) {
            $proto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
            $https = strtolower($proto) === 'https'
                || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

            return ($https ? 'https' : 'http') . '://' . $host;
        }

        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return $configured;
        }

        return null;
    }

    public static function ensureTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS total FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                ['portal_login_links']
            );
            if (((int) ($row['total'] ?? 0)) > 0) {
                $ensured = true;
                return;
            }
        } catch (\Throwable) {
        }

        try {
            (new Updater())->runMigrations();
        } catch (\Throwable) {
        }

        try {
            Database::getInstance()->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `portal_login_links` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT UNSIGNED NOT NULL,
                    `media_user_id` BIGINT UNSIGNED NOT NULL,
                    `token_hash` CHAR(64) NOT NULL,
                    `purpose` VARCHAR(20) NOT NULL DEFAULT \'home\',
                    `expires_at` DATETIME NOT NULL,
                    `revoked_at` DATETIME NULL,
                    `last_used_at` DATETIME NULL,
                    `use_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_portal_login_links_hash` (`token_hash`),
                    KEY `idx_portal_login_links_user` (`media_user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            return;
        }

        $ensured = true;
    }
}
