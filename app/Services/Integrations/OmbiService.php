<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/** Ombi media request integration. */
final class OmbiService
{
    private Client $client;

    public function __construct(private string $url, private string $apiKey)
    {
        $this->client = new Client([
            'base_uri' => rtrim($url, '/') . '/api/v1/',
            'timeout' => 30,
            'headers' => ['ApiKey' => $apiKey, 'Accept' => 'application/json'],
        ]);
    }

    public function testConnection(): bool
    {
        try { $this->client->get('Request/count'); return true; } catch (GuzzleException) { return false; }
    }

    public function getStats(): array
    {
        try {
            $r = $this->client->get('Request/count');
            $data = json_decode($r->getBody()->getContents(), true);
            return [
                'pending' => $data['pending'] ?? 0,
                'approved' => $data['approved'] ?? 0,
                'available' => $data['available'] ?? 0,
            ];
        } catch (GuzzleException) { return ['pending' => 0, 'approved' => 0, 'available' => 0]; }
    }
}
