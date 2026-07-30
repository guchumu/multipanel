<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * Tautulli (Plex monitoring) API integration.
 */
final class TautulliService
{
    private Client $client;

    public function __construct(
        private string $url,
        private string $apiKey,
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($url, '/') . '/api/v2',
            'timeout' => 30,
        ]);
    }

    private function request(string $cmd, array $params = []): ?array
    {
        try {
            $r = $this->client->get('', [
                'query' => array_merge(['apikey' => $this->apiKey, 'cmd' => $cmd], $params),
            ]);
            $data = json_decode($r->getBody()->getContents(), true);
            return $data['response']['data'] ?? null;
        } catch (GuzzleException $e) {
            Logger::error("Tautulli {$cmd} failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function testConnection(): bool
    {
        return $this->request('get_server_info') !== null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getActivity(): array
    {
        return $this->request('get_activity') ?: [];
    }

    /** @return array<string, mixed>|null */
    public function getHomeStats(): ?array
    {
        return $this->request('get_home_stats', ['time_range' => 30]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getUsers(): array
    {
        return $this->request('get_users') ?: [];
    }

    public function getStats(): array
    {
        $home = $this->getHomeStats();
        $activity = $this->getActivity();

        return [
            'active_streams' => count($activity),
            'total_plays' => $home['total_plays'] ?? 0,
            'total_duration' => $home['total_duration'] ?? 0,
            'top_movies' => $home['top_movies'] ?? [],
            'top_tv' => $home['top_tv'] ?? [],
        ];
    }
}
