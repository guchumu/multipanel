<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Textos que ve el cliente en el reproductor al cortar una emisión.
 * Cortos (≤500 chars) e instructivos; si falla, que contacte con soporte.
 */
final class ClientStopGuidanceService
{
    public const SUPPORT_TAIL = 'Si tienes problemas, contacta con soporte.';

    public const MSG_SALVABLE_TRANSCODE = 'Tu app está limitando la calidad y el servidor tiene que convertir el vídeo. '
        . 'En Plex: Ajustes → Calidad / Reproducción → pon Original o la máxima; '
        . 'desactiva Convertir automáticamente; activa Direct Play/Stream. '
        . 'Cierra Plex del todo y vuelve a abrir. '
        . self::SUPPORT_TAIL;

    public const MSG_HOME_LIMIT = 'Has superado el límite de pantallas a la vez en casa. '
        . 'Cierra alguna reproducción que no uses. '
        . 'Si necesitáis más pantallas, contactad con soporte para ampliar el plan.';

    public const MSG_AWAY_LIMIT = 'Esta cuenta no permite ver fuera de casa (o el límite fuera es 0). '
        . 'Si estás en casa con Wi‑Fi: reinicia el router, sin VPN ni datos móviles, '
        . 'y contacta con soporte para revisar la IP. '
        . 'Si estás fuera, contacta con soporte.';

    public const MSG_GENERIC_LIMIT = 'Se ha superado el límite de reproducciones simultáneas. '
        . 'Cierra alguna pantalla que no uses. '
        . 'Si necesitáis más pantallas, contactad con soporte.';

    /** Transcode de vídeo salvable (calidad / mal ajuste). */
    public static function forSalvableTranscode(): string
    {
        return self::MSG_SALVABLE_TRANSCODE;
    }

    /** Demasiadas pantallas a la vez en casa. */
    public static function forHomeLimit(): string
    {
        return self::MSG_HOME_LIMIT;
    }

    /** Reproducción fuera del hogar no permitida. */
    public static function forAwayLimit(): string
    {
        return self::MSG_AWAY_LIMIT;
    }

    /** Límite genérico de streams simultáneos. */
    public static function forGenericStreamLimit(): string
    {
        return self::MSG_GENERIC_LIMIT;
    }

    /**
     * @param array<string, mixed> $streamInfo
     * @param array<string, mixed> $session
     */
    public static function forVideoTranscodeSession(array $streamInfo = [], array $session = []): string
    {
        unset($streamInfo, $session);

        return self::forSalvableTranscode();
    }
}
