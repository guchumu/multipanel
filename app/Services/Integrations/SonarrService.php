<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * Sonarr API integration.
 *
 * @see https://sonarr.tv/docs/api/
 */
final class SonarrService
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

    /** @return array<string, mixed>|null */
    public function getStatus(): ?array
    {
        try {
            $r = $this->client->get('system/status');
            return json_decode($r->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Logger::error('Sonarr status failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getSeries(): array
    {
        try {
            $r = $this->client->get('series');
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
        $series = $this->getSeries();
        $queue = $this->getQueue();

        return [
            'total_series' => count($series),
            'queue_items' => count($queue),
            'episodes' => array_sum(array_map(fn ($s) => $s['statistics']['episodeCount'] ?? 0, $series)),
        ];
    }
}
