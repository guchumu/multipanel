<?php

declare(strict_types=1);

namespace Core;

/**
 * File-based cache driver.
 */
final class Cache
{
    private static string $path = '';

    private static function path(): string
    {
        if (self::$path === '') {
            self::$path = storage_path('cache');
            if (!is_dir(self::$path)) {
                mkdir(self::$path, 0755, true);
            }
        }
        return self::$path;
    }

    private static function filePath(string $key): string
    {
        return self::path() . '/' . hash('sha256', $key) . '.cache';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (config('redis.enabled', false) && RedisClient::isAvailable()) {
            $value = RedisClient::get('cache:' . $key);
            return $value ?? $default;
        }

        $file = self::filePath($key);
        if (!file_exists($file)) {
            return $default;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return $default;
        }

        $data = unserialize($content);
        if ($data === false || !is_array($data)) {
            return $default;
        }

        if ($data['expires_at'] !== 0 && $data['expires_at'] < time()) {
            unlink($file);
            return $default;
        }

        return $data['value'];
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (config('redis.enabled', false) && RedisClient::isAvailable()) {
            return RedisClient::set('cache:' . $key, $value, $ttl);
        }

        $file = self::filePath($key);
        $data = [
            'value' => $value,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ];

        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }

    public static function forget(string $key): bool
    {
        if (config('redis.enabled', false) && RedisClient::isAvailable()) {
            RedisClient::delete('cache:' . $key);
        }

        $file = self::filePath($key);
        return file_exists($file) ? unlink($file) : true;
    }

    public static function flush(): void
    {
        foreach (glob(self::path() . '/*.cache') as $file) {
            unlink($file);
        }
    }
}
