<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use Core\Cache;
use Core\Logger;

/**
 * Busca un título en las bibliotecas Plex/Jellyfin del tenant.
 */
final class MediaCatalogSearchService
{
    /** @var array<int, PlexService|JellyfinService> */
    private array $clients = [];

    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    /**
     * @return array{title: string, server: string, type: string}|null
     */
    public function findTitle(int $tenantId, string $title): ?array
    {
        $query = TmdbPeticionLookup::searchQuery($title);
        if ($query === '' || $tenantId <= 0) {
            return null;
        }

        $cacheKey = 'catalog:peticion:' . md5(mb_strtolower($query) . '|' . $tenantId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && array_key_exists('found', $cached)) {
            if (empty($cached['found'])) {
                return null;
            }

            return [
                'title' => (string) ($cached['title'] ?? $query),
                'server' => (string) ($cached['server'] ?? ''),
                'type' => (string) ($cached['type'] ?? ''),
            ];
        }

        foreach ($this->servers->allByTenant($tenantId) as $server) {
            $hits = $this->searchServer($server->id, $server, $query);
            foreach ($hits as $hit) {
                if (!CatalogTitleMatcher::matches($query, (string) ($hit['title'] ?? ''))) {
                    continue;
                }
                $found = [
                    'found' => true,
                    'title' => (string) $hit['title'],
                    'server' => (string) ($server->name ?? ''),
                    'type' => (string) ($hit['type'] ?? ''),
                ];
                Cache::set($cacheKey, $found, 3600);

                return [
                    'title' => $found['title'],
                    'server' => $found['server'],
                    'type' => $found['type'],
                ];
            }
        }

        Cache::set($cacheKey, ['found' => false], 1800);

        return null;
    }

    /**
     * @return list<array{title: string, type: string, year: string}>
     */
    private function searchServer(int $serverId, mixed $server, string $query): array
    {
        try {
            $client = $this->clients[$serverId] ??= MediaServerFactory::make($server);
            if (!method_exists($client, 'searchCatalog')) {
                return [];
            }

            /** @var list<array{title: string, type: string, year?: string}> $hits */
            $hits = $client->searchCatalog($query);

            return is_array($hits) ? $hits : [];
        } catch (\Throwable $e) {
            Logger::debug('Búsqueda de catálogo falló', [
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
