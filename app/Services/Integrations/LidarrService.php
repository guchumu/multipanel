<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/** Lidarr API integration. */
final class LidarrService
{
    private Client $client;

    public function __construct(private string $url, private string $apiKey)
    {
        $this->client = new Client([
            'base_uri' => rtrim($url, '/') . '/api/v1/',
            'timeout' => 30,
            'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
        ]);
    }

    public function testConnection(): bool
    {
        try { $this->client->get('system/status'); return true; } catch (GuzzleException) { return false; }
    }

    public function getStats(): array
    {
        try {
            $r = $this->client->get('artist');
            $artists = json_decode($r->getBody()->getContents(), true) ?: [];
            return ['total_artists' => count($artists)];
        } catch (GuzzleException) { return ['total_artists' => 0]; }
    }
}
