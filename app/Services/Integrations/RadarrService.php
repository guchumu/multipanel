<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * Radarr API integration.
 *
 * @see https://radarr.video/docs/api/
 */
final class RadarrService
{
    private Client $client;

    public function __construct(
        private string $url,
        private string $apiKey,
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($url, '/') . '/api/v3/',
            'timeout' => 30,
            'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
        ]);
    }

    public function testConnection(): bool
    {
        try {
            $this->client->get('system/status');
            return true;
        } catch (GuzzleException) {
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getMovies(): array
    {
        try {
            $r = $this->client->get('movie');
            return json_decode($r->getBody()->getContents(), true) ?: [];
        } catch (GuzzleException) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getQueue(): array
    {
        try {
            $r = $this->client->get('queue');
            $data = json_decode($r->getBody()->getContents(), true);
            return $data['records'] ?? [];
        } catch (GuzzleException) {
            return [];
        }
    }

    public function getStats(): array
    {
        $movies = $this->getMovies();
        $queue = $this->getQueue();
        $downloaded = count(array_filter($movies, fn ($m) => $m['hasFile'] ?? false));

        return [
            'total_movies' => count($movies),
            'downloaded' => $downloaded,
            'missing' => count($movies) - $downloaded,
            'queue_items' => count($queue),
        ];
    }
}
