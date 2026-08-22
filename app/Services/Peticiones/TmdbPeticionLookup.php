<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

use Core\Cache;
use Core\Logger;
use GuzzleHttp\Client;

/**
 * Búsqueda TMDb v3 (search/multi + watch/providers ES) para carátulas y plataformas.
 * Misma idea que el panel legacy; no guarda la API key en código.
 */
final class TmdbPeticionLookup
{
    public const IMAGE_BASE = 'https://image.tmdb.org/t/p/';
    private const CACHE_TTL = 43200; // 12 h

    public function __construct(
        private ?Client $http = null,
        private bool $useCache = true,
    ) {
    }

    /**
     * Título limpio para TMDb: tildes, entidades HTML, sin año ni «en español».
     */
    public static function searchQuery(string $title): string
    {
        $title = PeticionText::repair($title);
        $title = self::shortTitle($title);
        $title = preg_replace('/\s+en\s+espa[ñn]ol\s*$/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+en\s+castellano\s*$/iu', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * Título sin el año entre paréntesis (mejor hit en TMDb).
     */
    public static function shortTitle(string $title): string
    {
        $title = PeticionText::repair($title);
        $parts = explode('(', $title, 2);

        return trim($parts[0]);
    }

    public static function isWeakPoster(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return true;
        }

        $lower = strtolower($url);
        if (str_contains($lower, 'placeholder')) {
            return true;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return true;
        }
        if (preg_match('#image\.tmdb\.org/t/p/w\d+/?$#i', $url)) {
            return true;
        }
        if (str_contains($lower, 't/p/w500null') || str_contains($lower, 't/p/w500undefined')) {
            return true;
        }

        return false;
    }

    public static function posterUrl(?string $posterPath): string
    {
        $path = trim((string) $posterPath);
        if ($path === '' || strtolower($path) === 'null') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return self::IMAGE_BASE . 'w500' . $path;
    }

    public static function logoUrl(?string $logoPath): string
    {
        $path = trim((string) $logoPath);
        if ($path === '' || strtolower($path) === 'null') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return self::IMAGE_BASE . 'w92' . $path;
    }

    /**
     * Extrae tt1234567 de un enlace IMDb o de un texto que lo contenga.
     */
    public static function imdbIdFromText(string $text): string
    {
        if (preg_match('/tt\d{7,}/i', $text, $m) !== 1) {
            return '';
        }

        return strtolower($m[0]);
    }

    public static function imdbUrl(string $imdbId): string
    {
        $imdbId = self::imdbIdFromText($imdbId);
        if ($imdbId === '') {
            return '';
        }

        return 'https://www.imdb.com/title/' . $imdbId . '/';
    }

    /**
     * @return array{
     *   titulo: string,
     *   poster: string,
     *   plataformas: list<array{nombre: string, logo: string}>,
     *   error?: string
     * }
     */
    public function lookup(string $title, string $apiKey, bool $force = false, string $url = ''): array
    {
        $title = trim($title);
        $url = trim($url);
        $apiKey = trim($apiKey);
        $imdbId = self::imdbIdFromText($url . ' ' . $title);
        $query = self::searchQuery($title);

        if ($apiKey === '') {
            return self::emptyResult($title, 'Sin clave TMDb.');
        }
        if ($imdbId === '' && $query === '') {
            return self::emptyResult($title, 'Título vacío.');
        }

        $cacheKey = $imdbId !== ''
            ? $this->cacheKeyImdb($imdbId, $apiKey)
            : $this->cacheKey($query, $apiKey);
        if (!$force && $this->useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && array_key_exists('poster', $cached)) {
                return $cached;
            }
        }

        $result = $imdbId !== ''
            ? $this->fetchByImdb($imdbId, $title !== '' ? $title : $imdbId, $apiKey)
            : $this->fetch($query, $title, $apiKey);

        if ($imdbId !== '' && isset($result['error'])) {
            if ($query !== '') {
                $result = $this->fetch($query, $title, $apiKey);
            }
        }

        if ($this->useCache) {
            $ttl = isset($result['error']) ? 3600 : self::CACHE_TTL;
            Cache::set($cacheKey, $result, $ttl);
        }

        return $result;
    }

    /**
     * Solo lectura de caché (no llama a TMDb).
     *
     * @return array{titulo: string, poster: string, plataformas: list<array{nombre: string, logo: string}>, error?: string}|null
     */
    public function cached(string $title, string $apiKey, string $url = ''): ?array
    {
        if (!$this->useCache) {
            return null;
        }

        $apiKey = trim($apiKey);
        $imdbId = self::imdbIdFromText($url . ' ' . $title);
        $query = self::searchQuery($title);
        if ($apiKey === '' || ($imdbId === '' && $query === '')) {
            return null;
        }

        $cacheKey = $imdbId !== ''
            ? $this->cacheKeyImdb($imdbId, $apiKey)
            : $this->cacheKey($query, $apiKey);
        $cached = Cache::get($cacheKey);

        return is_array($cached) && array_key_exists('poster', $cached) ? $cached : null;
    }

    /**
     * @return list<string>
     */
    public function platformNames(string $title, string $apiKey): array
    {
        $lookup = $this->lookup($title, $apiKey);
        $names = [];
        foreach ($lookup['plataformas'] as $item) {
            $name = trim((string) ($item['nombre'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array{titulo: string, poster: string, plataformas: list<array{nombre: string, logo: string}>, error?: string}
     */
    private function fetch(string $query, string $originalTitle, string $apiKey): array
    {
        try {
            $client = $this->client();
            $search = $client->get('https://api.themoviedb.org/3/search/multi', [
                'query' => [
                    'api_key' => $apiKey,
                    'query' => $query,
                    'language' => 'es-ES',
                    'include_adult' => 'false',
                ],
            ]);
            $data = json_decode((string) $search->getBody(), true);
            $hit = $this->firstMovieOrTv(is_array($data) ? ($data['results'] ?? []) : []);
            if ($hit === null) {
                return self::emptyResult($originalTitle, 'No se encontraron resultados.');
            }

            $mediaType = (string) ($hit['media_type'] ?? '');

            return $this->detailsFromHit($hit, $mediaType, $originalTitle, $apiKey);
        } catch (\Throwable $e) {
            Logger::warning('TMDb streaming lookup failed: ' . $e->getMessage());

            return self::emptyResult($originalTitle, 'Error al consultar TMDb.');
        }
    }

    /**
     * @return array{titulo: string, poster: string, plataformas: list<array{nombre: string, logo: string}>, error?: string}
     */
    private function fetchByImdb(string $imdbId, string $originalTitle, string $apiKey): array
    {
        try {
            $client = $this->client();
            $find = $client->get('https://api.themoviedb.org/3/find/' . rawurlencode($imdbId), [
                'query' => [
                    'api_key' => $apiKey,
                    'external_source' => 'imdb_id',
                    'language' => 'es-ES',
                ],
            ]);
            $data = json_decode((string) $find->getBody(), true);
            if (!is_array($data)) {
                return self::emptyResult($originalTitle, 'No se encontraron resultados.');
            }

            $movies = $data['movie_results'] ?? [];
            $shows = $data['tv_results'] ?? [];
            if (is_array($movies) && $movies !== [] && is_array($movies[0])) {
                $hit = $movies[0];
                $hit['media_type'] = 'movie';

                return $this->detailsFromHit($hit, 'movie', $originalTitle, $apiKey);
            }
            if (is_array($shows) && $shows !== [] && is_array($shows[0])) {
                $hit = $shows[0];
                $hit['media_type'] = 'tv';

                return $this->detailsFromHit($hit, 'tv', $originalTitle, $apiKey);
            }

            return self::emptyResult($originalTitle, 'No se encontraron resultados.');
        } catch (\Throwable $e) {
            Logger::warning('TMDb IMDb find failed: ' . $e->getMessage());

            return self::emptyResult($originalTitle, 'Error al consultar TMDb.');
        }
    }

    /**
     * @param array<string, mixed> $hit
     * @return array{titulo: string, poster: string, plataformas: list<array{nombre: string, logo: string}>, error?: string}
     */
    private function detailsFromHit(array $hit, string $mediaType, string $originalTitle, string $apiKey): array
    {
        $id = (int) ($hit['id'] ?? 0);
        $displayTitle = trim((string) ($hit['title'] ?? $hit['name'] ?? $originalTitle));
        $poster = self::posterUrl(isset($hit['poster_path']) ? (string) $hit['poster_path'] : null);
        $plataformas = [];

        if ($id > 0 && ($mediaType === 'movie' || $mediaType === 'tv')) {
            try {
                $providers = $this->client()->get(
                    "https://api.themoviedb.org/3/{$mediaType}/{$id}/watch/providers",
                    ['query' => ['api_key' => $apiKey]]
                );
                $pData = json_decode((string) $providers->getBody(), true);
                $es = is_array($pData) ? ($pData['results']['ES']['flatrate'] ?? []) : [];
                if (is_array($es)) {
                    foreach ($es as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $name = trim((string) ($item['provider_name'] ?? ''));
                        $logo = self::logoUrl(isset($item['logo_path']) ? (string) $item['logo_path'] : null);
                        if ($name === '') {
                            continue;
                        }
                        $plataformas[] = [
                            'nombre' => $name,
                            'logo' => $logo,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Logger::debug('TMDb providers failed: ' . $e->getMessage());
            }
        }

        return [
            'titulo' => $displayTitle !== '' ? $displayTitle : $originalTitle,
            'poster' => $poster,
            'plataformas' => $plataformas,
        ];
    }

    /**
     * @param list<mixed> $results
     * @return array<string, mixed>|null
     */
    private function firstMovieOrTv(array $results): ?array
    {
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['media_type'] ?? '');
            if ($type === 'movie' || $type === 'tv') {
                return $row;
            }
        }

        return null;
    }

    private function client(): Client
    {
        return $this->http ??= new Client(['timeout' => 8]);
    }

    private function cacheKey(string $query, string $apiKey): string
    {
        return 'tmdb:peticion:' . md5(mb_strtolower($query) . '|' . substr($apiKey, 0, 8));
    }

    private function cacheKeyImdb(string $imdbId, string $apiKey): string
    {
        return 'tmdb:imdb:' . md5(strtolower($imdbId) . '|' . substr($apiKey, 0, 8));
    }

    /**
     * @return array{titulo: string, poster: string, plataformas: list<array{nombre: string, logo: string}>, error: string}
     */
    private static function emptyResult(string $title, string $error): array
    {
        return [
            'titulo' => $title,
            'poster' => '',
            'plataformas' => [],
            'error' => $error,
        ];
    }
}
