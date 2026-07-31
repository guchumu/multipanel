<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Server;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * Plex Media Server API integration.
 *
 * @see https://developer.plex.tv/pms/
 */
final class PlexService
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
                'X-Plex-Client-Identifier' => 'multipanel-erp',
                'X-Plex-Product' => 'MultiPanel ERP',
                'X-Plex-Version' => '1.0.0',
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getServerInfo(): ?array
    {
        try {
            $response = $this->client->get('/', [
                'headers' => $this->authHeaders(),
            ]);

            $xml = simplexml_load_string($response->getBody()->getContents());
            if ($xml === false) {
                return null;
            }

            return [
                'machine_id' => (string) ($xml['machineIdentifier'] ?? ''),
                'version' => (string) ($xml['version'] ?? ''),
                'platform' => (string) ($xml['platform'] ?? ''),
                'name' => (string) ($xml['friendlyName'] ?? $this->server->name),
            ];
        } catch (GuzzleException $e) {
            Logger::error('Plex server info failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getUsers(): array
    {
        try {
            $response = $this->client->get('/accounts', [
                'headers' => $this->authHeaders(),
            ]);

            $body = $response->getBody()->getContents();
            $xml = simplexml_load_string($body);
            if ($xml !== false && isset($xml->Account)) {
                $users = [];
                foreach ($xml->Account as $account) {
                    $users[] = [
                        'external_id' => (string) ($account['id'] ?? $account['key'] ?? ''),
                        'username' => (string) ($account['name'] ?? $account['defaultTitle'] ?? ''),
                        'email' => null,
                        'thumb' => (string) ($account['thumb'] ?? '') ?: null,
                        'restricted' => false,
                    ];
                }

                if ($users !== []) {
                    return $users;
                }
            }

            $response = $this->client->get('/api/users', [
                'headers' => $this->authHeaders(),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return [];
            }

            return array_map(fn ($user) => [
                'external_id' => (string) ($user['id'] ?? ''),
                'username' => $user['username'] ?? $user['title'] ?? '',
                'email' => $user['email'] ?? null,
                'thumb' => $user['thumb'] ?? null,
                'restricted' => $user['restricted'] ?? false,
            ], $data);
        } catch (GuzzleException $e) {
            Logger::error('Plex get users failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getLibraries(): array
    {
        try {
            $response = $this->client->get('/library/sections', [
                'headers' => $this->authHeaders(),
            ]);

            $xml = simplexml_load_string($response->getBody()->getContents());
            if ($xml === false) {
                return [];
            }

            $libraries = [];
            foreach ($xml->Directory as $dir) {
                $libraries[] = [
                    'external_id' => (string) $dir['key'],
                    'name' => (string) $dir['title'],
                    'type' => (string) $dir['type'],
                    'path' => null,
                ];
            }

            return $libraries;
        } catch (GuzzleException $e) {
            Logger::error('Plex get libraries failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getActiveSessions(): array
    {
        try {
            $response = $this->client->get('/status/sessions', [
                'headers' => $this->authHeaders(),
            ]);

            $xml = simplexml_load_string($response->getBody()->getContents());
            if ($xml === false) {
                return [];
            }

            $sessions = [];
            foreach ($xml->Video ?? $xml->Track ?? [] as $session) {
                $sessions[] = [
                    'title' => (string) ($session['title'] ?? ''),
                    'user' => (string) ($session->User['title'] ?? ''),
                    'player' => (string) ($session->Player['title'] ?? ''),
                    'state' => (string) ($session->Player['state'] ?? ''),
                ];
            }

            return $sessions;
        } catch (GuzzleException $e) {
            Logger::error('Plex get sessions failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function createUser(string $username, string $password, ?string $email = null): ?array
    {
        try {
            $response = $this->client->post('https://plex.tv/api/v2/users', [
                'headers' => array_merge($this->authHeaders(), [
                    'Content-Type' => 'application/json',
                ]),
                'json' => [
                    'username' => $username,
                    'password' => $password,
                    'email' => $email,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Logger::error('Plex create user failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function shareLibrary(string $userId, int $libraryId): bool
    {
        try {
            $this->client->put("/library/sections/{$libraryId}/shared", [
                'headers' => $this->authHeaders(),
                'query' => ['userId' => $userId],
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Plex share library failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteUser(string $userId): bool
    {
        try {
            $this->client->delete("/api/users/{$userId}", [
                'headers' => $this->authHeaders(),
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Plex delete user failed', ['error' => $e->getMessage()]);
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
        if ($this->server->token) {
            $headers['X-Plex-Token'] = $this->server->token;
        }
        return $headers;
    }
}
