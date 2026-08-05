<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Normaliza IPs de sesión Plex/Jellyfin para el límite de streams (IPs distintas).
 */
final class SessionClientIp
{
    /**
     * Prefiere IP pública/WAN; si no hay, usa LAN/local.
     */
    public static function prefer(?string $publicAddress, ?string $address, ?string $fallback = null): string
    {
        foreach ([$publicAddress, $address, $fallback] as $candidate) {
            $ip = self::normalize((string) ($candidate ?? ''));
            if ($ip !== '') {
                return $ip;
            }
        }

        return '';
    }

    /**
     * Normaliza "1.2.3.4:5678", "[2001:db8::1]:443", "::ffff:1.2.3.4".
     */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'unknown') === 0 || strcasecmp($raw, 'n/a') === 0) {
            return '';
        }

        // [IPv6]:port
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $raw, $m) === 1) {
            $raw = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/', $raw, $m) === 1) {
            // IPv4:port
            $raw = $m[1];
        }

        if (str_starts_with(strtolower($raw), '::ffff:')) {
            $raw = substr($raw, 7);
        }

        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }

        return filter_var($raw, FILTER_VALIDATE_IP) ? $raw : '';
    }

    /** Jellyfin RemoteEndPoint → IP. */
    public static function fromRemoteEndPoint(?string $remoteEndPoint): string
    {
        return self::normalize((string) ($remoteEndPoint ?? ''));
    }
}
