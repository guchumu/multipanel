<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reversible encryption for admin-revealable secrets (e.g. Jellyfin passwords).
 * Uses AES-256-GCM with APP_KEY.
 */
final class SecretCrypt
{
    private const PREFIX = 'enc:v1:';

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = $this->keyBytes();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || $tag === '') {
            throw new \RuntimeException('No se pudo cifrar el secreto.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (!str_starts_with($payload, self::PREFIX)) {
            // Legacy / accidental plaintext: return as-is so admins are not locked out.
            return $payload;
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->keyBytes(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    private function keyBytes(): string
    {
        $key = (string) (env('APP_KEY', '') ?: config('app.key', ''));
        if ($key === '') {
            $key = 'multipanel-insecure-fallback-key';
        }

        return hash('sha256', $key, true);
    }
}
