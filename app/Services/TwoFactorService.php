<?php

declare(strict_types=1);

namespace App\Services;

/**
 * TOTP two-factor authentication service (RFC 6238).
 */
final class TwoFactorService
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        return $this->base32Encode($bytes);
    }

    /** @return array<int, string> */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    public function getQrCodeUrl(string $secret, string $email): string
    {
        $issuer = rawurlencode(config('app.name', 'MultiPanel'));
        $label = rawurlencode($email);
        $otpauth = "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&digits=" . self::DIGITS;

        return 'https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=' . rawurlencode($otpauth);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        $timeSlice = (int) floor(time() / self::PERIOD);

        for ($i = -1; $i <= 1; $i++) {
            if ($this->generateCode($secret, $timeSlice + $i) === $code) {
                return true;
            }
        }

        return false;
    }

    public function verifyRecoveryCode(array $storedCodes, string $code): bool
    {
        $index = array_search(strtoupper($code), array_map('strtoupper', $storedCodes), true);
        return $index !== false;
    }

    private function generateCode(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        foreach (str_split(strtoupper($data)) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
