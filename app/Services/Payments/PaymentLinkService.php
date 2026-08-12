<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Core\Database;
use Core\Logger;
use Core\Updater;

/**
 * Short public payment links: /p/{code} → Stripe checkout URL.
 */
final class PaymentLinkService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private const CODE_LENGTH = 8;

    private bool $tableEnsured = false;

    /**
     * @return array{success: bool, code?: string, short_url?: string, message?: string}
     */
    public function create(
        int $tenantId,
        string $checkoutUrl,
        ?int $mediaUserId = null,
        ?string $stripeSessionId = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $checkoutUrl = trim($checkoutUrl);
        if ($checkoutUrl === '' || !preg_match('#^https?://#i', $checkoutUrl)) {
            return ['success' => false, 'message' => 'URL de checkout inválida.'];
        }

        $baseUrl = $this->resolvePublicBaseUrl();
        if ($baseUrl === null) {
            return ['success' => false, 'message' => 'APP_URL no es una URL pública válida.'];
        }

        $this->ensureTable();

        try {
            $code = $this->allocateUniqueCode();
            Database::getInstance()->insert('payment_links', [
                'tenant_id' => $tenantId,
                'media_user_id' => $mediaUserId,
                'code' => $code,
                'url' => $checkoutUrl,
                'stripe_session_id' => $stripeSessionId !== null && $stripeSessionId !== '' ? $stripeSessionId : null,
                'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            ]);

            return [
                'success' => true,
                'code' => $code,
                'short_url' => $baseUrl . '/p/' . $code,
            ];
        } catch (\Throwable $e) {
            Logger::error('Payment link create failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'No se pudo crear el enlace corto.'];
        }
    }

    /**
     * Resuelve un código a la URL de Stripe (null si no existe o ha caducado).
     */
    public function resolve(string $code): ?string
    {
        $code = trim($code);
        if (!self::isValidCode($code)) {
            return null;
        }

        $this->ensureTable();

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT url, expires_at FROM payment_links WHERE code = ? LIMIT 1',
                [$code]
            );
        } catch (\Throwable $e) {
            Logger::warning('Payment link resolve failed', ['error' => $e->getMessage()]);
            return null;
        }

        if ($row === null) {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt !== null && $expiresAt !== '') {
            $expiresTs = strtotime((string) $expiresAt);
            if ($expiresTs !== false && $expiresTs < time()) {
                return null;
            }
        }

        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }

    public static function isValidCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{6,32}$/', $code);
    }

    public static function generateCode(int $length = self::CODE_LENGTH): string
    {
        $length = max(6, min(32, $length));
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private function allocateUniqueCode(): string
    {
        $db = Database::getInstance();
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = self::generateCode();
            $existing = $db->fetchOne('SELECT id FROM payment_links WHERE code = ? LIMIT 1', [$code]);
            if ($existing === null) {
                return $code;
            }
        }

        return self::generateCode(12);
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        try {
            (new Updater())->runMigrations();
        } catch (\Throwable $e) {
            Logger::warning('Payment links migration ensure failed', ['error' => $e->getMessage()]);
        }

        $this->tableEnsured = true;
    }

    private function resolvePublicBaseUrl(): ?string
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
}
