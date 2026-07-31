<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Normalizes server host/port/ssl from user input.
 */
final class ServerEndpoint
{
    /** @return array{url: string, port: int, ssl: bool} */
    public static function normalize(string $url, int $port, bool $ssl): array
    {
        $raw = trim($url);
        if ($raw === '') {
            return ['url' => '', 'port' => $port, 'ssl' => $ssl];
        }

        if (!str_contains($raw, '://') && preg_match('#^([^:/]+):(\d+)(/.*)?$#', $raw, $m)) {
            return [
                'url' => $m[1],
                'port' => (int) $m[2],
                'ssl' => $ssl,
            ];
        }

        if (!str_contains($raw, '://')) {
            $raw = 'http://' . $raw;
        }

        $parts = parse_url($raw);
        if ($parts === false || empty($parts['host'])) {
            return [
                'url' => preg_replace('#^https?://#', '', explode('/', trim($url))[0]) ?? trim($url),
                'port' => $port,
                'ssl' => $ssl,
            ];
        }

        $scheme = $parts['scheme'] ?? 'http';

        return [
            'url' => $parts['host'],
            'port' => (int) ($parts['port'] ?? $port),
            'ssl' => $scheme === 'https' ? true : ($scheme === 'http' ? false : $ssl),
        ];
    }
}
