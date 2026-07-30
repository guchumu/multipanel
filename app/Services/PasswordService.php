<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Password hashing and generation service using Argon2id.
 */
final class PasswordService
{
    private const OPTIONS = [
        'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
        'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
        'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
    ];

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function generate(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }

        return $password;
    }

    public function validate(string $password, array $policy = []): bool
    {
        $minLength = $policy['min_length'] ?? 8;
        $requireUpper = $policy['require_uppercase'] ?? true;
        $requireLower = $policy['require_lowercase'] ?? true;
        $requireNumber = $policy['require_number'] ?? true;
        $requireSpecial = $policy['require_special'] ?? false;

        if (strlen($password) < $minLength) {
            return false;
        }

        if ($requireUpper && !preg_match('/[A-Z]/', $password)) {
            return false;
        }

        if ($requireLower && !preg_match('/[a-z]/', $password)) {
            return false;
        }

        if ($requireNumber && !preg_match('/[0-9]/', $password)) {
            return false;
        }

        if ($requireSpecial && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            return false;
        }

        return true;
    }
}
