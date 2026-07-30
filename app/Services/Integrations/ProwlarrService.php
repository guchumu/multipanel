<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/** Prowlarr API integration. */
final class ProwlarrService
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
            $r = $this->client->get('indexer');
            $indexers = json_decode($r->getBody()->getContents(), true) ?: [];
            return ['total_indexers' => count($indexers), 'active' => count(array_filter($indexers, fn ($i) => $i['enable'] ?? false))];
        } catch (GuzzleException) { return ['total_indexers' => 0, 'active' => 0]; }
    }
}
