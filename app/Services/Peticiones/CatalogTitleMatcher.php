<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

/**
 * Compara el título de una petición con un resultado de Plex/Jellyfin.
 */
final class CatalogTitleMatcher
{
    public static function normalize(string $title): string
    {
        $title = TmdbPeticionLookup::searchQuery($title);
        $title = mb_strtolower($title, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        if (is_string($ascii) && $ascii !== '') {
            $title = $ascii;
        }
        $title = preg_replace('/[^a-z0-9]+/i', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return trim($title);
    }

    public static function matches(string $requested, string $found): bool
    {
        $a = self::normalize($requested);
        $b = self::normalize($found);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $len = min(strlen($a), strlen($b));
        if ($len < 8) {
            return false;
        }

        similar_text($a, $b, $pct);
        if ($pct >= 92.0) {
            return true;
        }

        if (strlen($a) >= 12 && str_starts_with($b, $a)) {
            return true;
        }
        if (strlen($b) >= 12 && str_starts_with($a, $b)) {
            return true;
        }

        return false;
    }
}
