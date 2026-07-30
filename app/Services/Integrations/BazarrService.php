<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/** Bazarr API integration. */
final class BazarrService
{
    private Client $client;

    public function __construct(private string $url, private string $apiKey)
    {
        $this->client = new Client([
            'base_uri' => rtrim($url, '/') . '/api/',
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
            $r = $this->client->get('movies');
            $movies = json_decode($r->getBody()->getContents(), true);
            $total = is_array($movies) ? count($movies) : ($movies['total'] ?? 0);
            return ['total_movies' => $total];
        } catch (GuzzleException) { return ['total_movies' => 0]; }
    }
}
