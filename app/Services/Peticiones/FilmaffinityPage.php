<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

/**
 * Enlaces y metadatos Open Graph de Filmaffinity.
 */
final class FilmaffinityPage
{
    public static function idFromText(string $text): string
    {
        if (preg_match('#filmaffinity\.com/[^/\s?#]+/film(\d+)#i', $text, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#/film(\d+)\.html#i', $text, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    public static function pageUrl(string $filmId): string
    {
        $filmId = preg_replace('/\D+/', '', $filmId) ?? '';
        if ($filmId === '') {
            return '';
        }

        return 'https://www.filmaffinity.com/es/film' . $filmId . '.html';
    }

    /**
     * @return array{title: string, poster: string}
     */
    public static function parseMeta(string $html): array
    {
        $title = self::metaContent($html, 'og:title');
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s*[-\x{2013}|]\s*FilmAffinity.*$/iu', '', $title) ?? $title;
        $title = PeticionText::repair(trim($title));

        $poster = self::metaContent($html, 'og:image');
        if ($poster === '') {
            $poster = self::metaContent($html, 'twitter:image');
        }
        $poster = html_entity_decode($poster, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $poster = self::preferLargePoster(trim($poster));

        return [
            'title' => $title,
            'poster' => $poster,
        ];
    }

    public static function preferLargePoster(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $url = preg_replace('#^http://#i', 'https://', $url) ?? $url;
        $url = preg_replace('/-(msmall|mmed|small|medium)(\.(?:jpe?g|png|webp))/i', '-large$2', $url) ?? $url;

        return $url;
    }

    public static function fetchUrl(string $filmId, string $scraperKey = ''): string
    {
        $page = self::pageUrl($filmId);
        if ($page === '') {
            return '';
        }
        $scraperKey = trim($scraperKey);
        if ($scraperKey === '') {
            return $page;
        }

        return 'https://api.scraperapi.com/?api_key=' . rawurlencode($scraperKey) . '&url=' . rawurlencode($page);
    }

    private static function metaContent(string $html, string $property): string
    {
        $prop = preg_quote($property, '/');
        if (preg_match('/property=["\']' . $prop . '["\'][^>]*content=["\']([^"\']+)/i', $html, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/content=["\']([^"\']+)["\'][^>]*property=["\']' . $prop . '["\']/i', $html, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/name=["\']' . $prop . '["\'][^>]*content=["\']([^"\']+)/i', $html, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }
}
