<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * Overseerr (media requests) API integration.
 */
final class OverseerrService
{
    private Client $client;

    public function __construct(
        private string $url,
        private string $apiKey,
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($url, '/') . '/api/v1/',
            'timeout' => 30,
            'headers' => ['X-Api-Key' => $apiKey, 'Accept' => 'application/json'],
        ]);
    }

    public function testConnection(): bool
    {
        try {
            $this->client->get('status');
            return true;
        } catch (GuzzleException) {
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getRequests(int $take = 20): array
    {
        try {
            $r = $this->client->get('request', ['query' => ['take' => $take, 'sort' => 'added']]);
            $data = json_decode($r->getBody()->getContents(), true);
            return $data['results'] ?? [];
        } catch (GuzzleException) {
            return [];
        }
    }

    public function getStats(): array
    {
        try {
            $r = $this->client->get('request/count');
            $data = json_decode($r->getBody()->getContents(), true);
            return [
                'pending' => $data['pending'] ?? 0,
                'approved' => $data['approved'] ?? 0,
                'processing' => $data['processing'] ?? 0,
                'available' => $data['available'] ?? 0,
            ];
        } catch (GuzzleException) {
            return ['pending' => 0, 'approved' => 0, 'processing' => 0, 'available' => 0];
        }
    }
}
