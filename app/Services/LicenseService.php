<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Panel license validation and management.
 */
final class LicenseService
{
    private const GRACE_DAYS = 7;

    public function isValid(): bool
    {
        $info = $this->getLicenseInfo();
        if ($info === null) {
            return true; // No license required in dev/unlicensed mode
        }

        if (($info['status'] ?? '') === 'active') {
            if (!empty($info['expires_at']) && strtotime($info['expires_at']) < time()) {
                return false;
            }
            return true;
        }

        return false;
    }

    public function getStatusMessage(): ?string
    {
        $info = $this->getLicenseInfo();
        if (!$info) {
            return 'Modo sin licencia (desarrollo)';
        }

        if (($info['status'] ?? '') === 'expired') {
            return 'Licencia expirada';
        }

        if (!empty($info['expires_at'])) {
            $days = (int) floor((strtotime($info['expires_at']) - time()) / 86400);
            if ($days < 0) {
                return 'Expirada hace ' . abs($days) . ' días';
            }
            if ($days <= 30) {
                return "Expira en {$days} días";
            }
        }

        return $info['plan'] ?? 'Activa';
    }

    /** @return array<string, mixed>|null */
    public function getLicenseInfo(): ?array
    {
        try {
            $tenant = Database::getInstance()->fetchOne(
                'SELECT license_key, license_expires_at, plan, status FROM tenants WHERE id = 1 LIMIT 1'
            );
        } catch (\Throwable) {
            return null;
        }

        if (!$tenant || empty($tenant['license_key'])) {
            return null;
        }

        return [
            'key' => $this->maskKey($tenant['license_key']),
            'plan' => $tenant['plan'] ?? 'enterprise',
            'status' => $tenant['status'] ?? 'active',
            'expires_at' => $tenant['license_expires_at'],
            'valid' => $this->validateKey($tenant['license_key']),
        ];
    }

    public function activate(string $licenseKey, string $domain = ''): bool
    {
        if (!$this->validateKey($licenseKey)) {
            return false;
        }

        $decoded = $this->decodeKey($licenseKey);
        if (!$decoded) {
            return false;
        }

        Database::getInstance()->update('tenants', [
            'license_key' => $licenseKey,
            'license_expires_at' => $decoded['expires_at'] ?? null,
            'plan' => $decoded['plan'] ?? 'enterprise',
            'domain' => $domain ?: config('app.url'),
        ], 'id = 1');

        return true;
    }

    public function generateKey(string $plan = 'enterprise', ?string $expiresAt = null, string $domain = '*'): string
    {
        $payload = json_encode([
            'plan' => $plan,
            'domain' => $domain,
            'expires_at' => $expiresAt,
            'issued_at' => date('Y-m-d'),
        ]);

        $signature = hash_hmac('sha256', $payload, env('APP_KEY', config('app.key', 'multipanel-secret')));
        return base64_encode($payload . '.' . $signature);
    }

    public function validateKey(string $key): bool
    {
        return $this->decodeKey($key) !== null;
    }

    /** @return array<string, mixed>|null */
    private function decodeKey(string $key): ?array
    {
        $decoded = base64_decode($key, true);
        if (!$decoded || !str_contains($decoded, '.')) {
            return null;
        }

        [$payload, $signature] = explode('.', $decoded, 2);
        $expected = hash_hmac('sha256', $payload, env('APP_KEY', config('app.key', 'multipanel-secret')));

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return null;
        }

        if (!empty($data['expires_at']) && strtotime($data['expires_at']) < time()) {
            return null;
        }

        $domain = $data['domain'] ?? '*';
        if ($domain !== '*') {
            $appUrl = parse_url(config('app.url', ''), PHP_URL_HOST) ?? '';
            if ($appUrl && !str_contains($appUrl, $domain)) {
                return null;
            }
        }

        return $data;
    }

    private function maskKey(string $key): string
    {
        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }
        return substr($key, 0, 6) . '...' . substr($key, -4);
    }
}
