<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Core\Exceptions\HttpException;

/**
 * JWT token generation and validation service.
 */
final class JwtService
{
    public function generate(array $payload, ?int $ttl = null): string
    {
        $now = time();
        $ttl = $ttl ?? config('jwt.ttl', 3600);

        $token = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + $ttl,
            'iss' => config('app.url'),
        ]);

        return JWT::encode($token, config('jwt.secret'), config('jwt.algorithm', 'HS256'));
    }

    public function generateRefreshToken(int $userId): string
    {
        return $this->generate(['sub' => $userId, 'type' => 'refresh'], config('jwt.refresh_ttl', 604800));
    }

    public function validate(string $token): object
    {
        try {
            return JWT::decode($token, new Key(config('jwt.secret'), config('jwt.algorithm', 'HS256')));
        } catch (\Exception $e) {
            throw new HttpException('Token inválido o expirado.', 401);
        }
    }

    public function getUserId(string $token): int
    {
        $decoded = $this->validate($token);
        return (int) ($decoded->sub ?? 0);
    }
}
