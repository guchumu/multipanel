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
            return ['url' => '', 'port' => $port > 0 ? $port : 32400, 'ssl' => $ssl];
        }

        $raw = preg_replace('#^\s*(https?://)#i', '', $raw) ?? $raw;
        $raw = rtrim($raw, '/');

        $host = $raw;
        $parsedPort = $port > 0 ? $port : 32400;
        $parsedSsl = $ssl;

        if (preg_match('#^\[([^\]]+)\](?::(\d+))?$#', $raw, $m)) {
            $host = $m[1];
            if (!empty($m[2])) {
                $parsedPort = (int) $m[2];
            }
        } elseif (preg_match('#^([^:/]+):(\d+)$#', $raw, $m)) {
            $host = $m[1];
            $parsedPort = (int) $m[2];
        } elseif (str_contains($raw, '://') || str_contains($raw, '/')) {
            $toParse = str_contains($raw, '://') ? $raw : 'http://' . $raw;
            $parts = parse_url($toParse);
            if (is_array($parts) && !empty($parts['host'])) {
                $host = $parts['host'];
                $parsedPort = (int) ($parts['port'] ?? $parsedPort);
                $scheme = $parts['scheme'] ?? 'http';
                $parsedSsl = $scheme === 'https' ? true : ($scheme === 'http' ? false : $ssl);
            }
        }

        $host = strtolower(trim($host));

        return [
            'url' => $host,
            'port' => $parsedPort,
            'ssl' => $parsedSsl,
        ];
    }

    public static function isIpAddress(string $host): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return str_starts_with($host, '[') && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    public static function isHostname(string $host): bool
    {
        $host = trim($host);
        if ($host === '' || self::isIpAddress($host)) {
            return false;
        }

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host);
    }

    /** @param array{url: string, port: int, ssl: bool} $endpoint */
    public static function shouldPreferCurrentHost(string $currentHost, array $endpoint): bool
    {
        $newHost = trim($endpoint['url']);
        $currentHost = trim($currentHost);

        if ($currentHost === '' || self::isIpAddress($currentHost)) {
            return false;
        }

        if (self::isHostname($currentHost) && self::isIpAddress($newHost)) {
            return true;
        }

        return false;
    }
}
