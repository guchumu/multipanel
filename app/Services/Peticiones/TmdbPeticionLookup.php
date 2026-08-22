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
     * Título sin el año entre paréntesis (mejor hit en TMDb).
     */
    public static function shortTitle(string $title): string
    {
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
     * @return array{
     *   titulo: string,
     *   poster: string,
     *   plataformas: list<array{nombre: string, logo: string}>,
     *   error?: string
     * }
     */
    public function lookup(string $title, string $apiKey, bool $force = false): array
    {
        $title = trim($title);
        $apiKey = trim($apiKey);
        $query = self::shortTitle($title);

        if ($apiKey === '') {
            return self::emptyResult($title, 'Sin clave TMDb.');
        }
        if ($query === '') {
            return self::emptyResult($title, 'Título vacío.');
        }

        $cacheKey = $this->cacheKey($query, $apiKey);
        if (!$force && $this->useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && array_key_exists('poster', $cached)) {
                return $cached;
            }
        }

        $result = $this->fetch($query, $title, $apiKey);
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
    public function cached(string $title, string $apiKey): ?array
    {
        if (!$this->useCache) {
            return null;
        }

        $query = self::shortTitle($title);
        $apiKey = trim($apiKey);
        if ($query === '' || $apiKey === '') {
            return null;
        }

        $cached = Cache::get($this->cacheKey($query, $apiKey));

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

            $id = (int) ($hit['id'] ?? 0);
            $mediaType = (string) ($hit['media_type'] ?? '');
            $displayTitle = trim((string) ($hit['title'] ?? $hit['name'] ?? $originalTitle));
            $poster = self::posterUrl(isset($hit['poster_path']) ? (string) $hit['poster_path'] : null);

            $plataformas = [];
            if ($id > 0) {
                $providers = $client->get(
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
            }

            return [
                'titulo' => $displayTitle !== '' ? $displayTitle : $originalTitle,
                'poster' => $poster,
                'plataformas' => $plataformas,
            ];
        } catch (\Throwable $e) {
            Logger::warning('TMDb streaming lookup failed: ' . $e->getMessage());

            return self::emptyResult($originalTitle, 'Error al consultar TMDb.');
        }
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
