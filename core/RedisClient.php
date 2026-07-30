<?php

declare(strict_types=1);

namespace Core;

/**
 * Redis cache and session driver with file fallback.
 */
final class RedisClient
{
    private static ?\Redis $instance = null;
    private static bool $available = false;

    public static function isAvailable(): bool
    {
        self::connect();
        return self::$available;
    }

    public static function get(string $key): mixed
    {
        if (!self::connect()) {
            return Cache::get($key);
        }

        $value = self::$instance->get(self::prefix() . $key);
        if ($value === false) {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!self::connect()) {
            return Cache::set($key, $value, $ttl);
        }

        $encoded = is_string($value) ? $value : json_encode($value);
        return self::$instance->setex(self::prefix() . $key, $ttl, $encoded);
    }

    public static function delete(string $key): bool
    {
        if (!self::connect()) {
            return Cache::forget($key);
        }

        return (bool) self::$instance->del(self::prefix() . $key);
    }

    public static function flush(): void
    {
        if (self::connect()) {
            $keys = self::$instance->keys(self::prefix() . '*');
            if ($keys) {
                self::$instance->del($keys);
            }
        }
        Cache::flush();
    }

    /** Session helpers */
    public static function setSession(string $id, array $data, int $ttl): bool
    {
        return self::set('session:' . $id, $data, $ttl);
    }

    public static function getSession(string $id): ?array
    {
        $data = self::get('session:' . $id);
        return is_array($data) ? $data : null;
    }

    public static function deleteSession(string $id): bool
    {
        return self::delete('session:' . $id);
    }

    private static function connect(): bool
    {
        if (self::$instance !== null) {
            return self::$available;
        }

        if (!extension_loaded('redis')) {
            self::$available = false;
            return false;
        }

        try {
            self::$instance = new \Redis();
            $host = config('redis.host', env('REDIS_HOST', '127.0.0.1'));
            $port = (int) config('redis.port', env('REDIS_PORT', 6379));
            $password = config('redis.password', env('REDIS_PASSWORD', ''));

            self::$instance->connect($host, $port, 2);
            if ($password) {
                self::$instance->auth($password);
            }
            self::$available = true;
        } catch (\Throwable) {
            self::$available = false;
        }

        return self::$available;
    }

    private static function prefix(): string
    {
        return config('redis.prefix', 'multipanel:');
    }
}
