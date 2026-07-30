<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Server;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * Jellyfin Media Server API integration.
 *
 * @see https://api.jellyfin.org/
 */
final class JellyfinService
{
    private Client $client;

    public function __construct(
        private Server $server,
    ) {
        $this->client = new Client([
            'base_uri' => $this->server->fullUrl(),
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getServerInfo(): ?array
    {
        try {
            $response = $this->client->get('/System/Info/Public');
            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'machine_id' => $data['Id'] ?? '',
                'version' => $data['Version'] ?? '',
                'platform' => $data['OperatingSystem'] ?? '',
                'name' => $data['ServerName'] ?? $this->server->name,
            ];
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin server info failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getUsers(): array
    {
        try {
            $response = $this->client->get('/Users', [
                'headers' => $this->authHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }

            return array_map(fn ($user) => [
                'external_id' => $user['Id'] ?? '',
                'username' => $user['Name'] ?? '',
                'email' => null,
                'thumb' => null,
                'restricted' => !($user['Policy']['IsAdministrator'] ?? false),
            ], $data);
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin get users failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getLibraries(): array
    {
        try {
            $response = $this->client->get('/Library/VirtualFolders', [
                'headers' => $this->authHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }

            return array_map(fn ($lib) => [
                'external_id' => $lib['ItemId'] ?? $lib['Name'],
                'name' => $lib['Name'] ?? '',
                'type' => $lib['CollectionType'] ?? 'mixed',
                'path' => $lib['Locations'][0] ?? null,
            ], $data);
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin get libraries failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getActiveSessions(): array
    {
        try {
            $response = $this->client->get('/Sessions', [
                'headers' => $this->authHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }

            return array_map(fn ($session) => [
                'title' => $session['NowPlayingItem']['Name'] ?? '',
                'user' => $session['UserName'] ?? '',
                'player' => $session['Client'] ?? '',
                'state' => $session['PlayState']['IsPaused'] ?? false ? 'paused' : 'playing',
            ], $data);
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin get sessions failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function createUser(string $username, string $password): ?array
    {
        try {
            $response = $this->client->post('/Users/New', [
                'headers' => $this->authHeaders(),
                'json' => [
                    'Name' => $username,
                    'Password' => $password,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin create user failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function deleteUser(string $userId): bool
    {
        try {
            $this->client->delete("/Users/{$userId}", [
                'headers' => $this->authHeaders(),
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin delete user failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function disableUser(string $userId): bool
    {
        try {
            $response = $this->client->get("/Users/{$userId}", [
                'headers' => $this->authHeaders(),
            ]);
            $user = json_decode($response->getBody()->getContents(), true);
            $user['Policy']['IsDisabled'] = true;

            $this->client->post("/Users/{$userId}", [
                'headers' => $this->authHeaders(),
                'json' => $user,
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin disable user failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function testConnection(): bool
    {
        return $this->getServerInfo() !== null;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        $headers = [];
        if ($this->server->api_key) {
            $headers['Authorization'] = 'MediaBrowser Token="' . $this->server->api_key . '"';
        }
        return $headers;
    }
}
