<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Identificador estable de una reproducción en playback_sessions.
 * Plex session_id cuando existe; huella de contenido como respaldo.
 */
final class PlaybackSessionKey
{
    public static function forSession(array $session, int $serverId): string
    {
        $sessionId = trim((string) ($session['session_id'] ?? ''));
        if ($sessionId !== '') {
            return 'sid:' . $serverId . ':' . $sessionId;
        }

        return 'hash:' . self::contentFingerprint($session, $serverId);
    }

    /** Formato antiguo (solo sync): md5 sin prefijo. */
    public static function legacyMd5(array $session, int $serverId): string
    {
        return self::contentFingerprint($session, $serverId);
    }

    /**
     * Claves a buscar al resolver una sesión abierta (canónica + legado).
     *
     * @return list<string>
     */
    public static function lookupKeys(array $session, int $serverId): array
    {
        $canonical = self::forSession($session, $serverId);
        $legacy = self::legacyMd5($session, $serverId);
        $keys = [$canonical];

        if ($legacy !== $canonical) {
            $keys[] = $legacy;
            $keys[] = 'hash:' . $legacy;
        }

        return array_values(array_unique($keys));
    }

    private static function contentFingerprint(array $session, int $serverId): string
    {
        return md5(
            $serverId . '|'
            . ($session['user'] ?? '') . '|'
            . ($session['title'] ?? '') . '|'
            . ($session['player'] ?? '')
        );
    }
}
