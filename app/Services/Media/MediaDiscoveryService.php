<?php

declare(strict_types=1);

namespace App\Services\Media;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * Discover media servers and tokens using username/password (one-time, not stored).
 */
final class MediaDiscoveryService
{
    private const CLIENT_ID = 'multipanel-erp-discovery';

    /** @return array{token: string, servers: array<int, array<string, mixed>>} */
    public function discoverPlex(string $login, string $password): array
    {
        $client = new Client([
            'base_uri' => 'https://plex.tv',
            'timeout' => 30,
            'headers' => $this->plexHeaders(),
        ]);

        try {
            $response = $client->post('/api/v2/login', [
                'json' => [
                    'login' => $login,
                    'password' => $password,
                    'rememberMe' => false,
                ],
            ]);

            $auth = json_decode($response->getBody()->getContents(), true);
            $token = $auth['authToken'] ?? null;

            if (!is_string($token) || $token === '') {
                throw new \RuntimeException('Credenciales Plex incorrectas.');
            }

            $resources = $client->get('/api/v2/resources', [
                'query' => ['includeHttps' => 1],
                'headers' => array_merge($this->plexHeaders(), ['X-Plex-Token' => $token]),
            ]);

            $data = json_decode($resources->getBody()->getContents(), true);
            $servers = [];

            foreach (is_array($data) ? $data : [] as $resource) {
                if (($resource['provides'] ?? '') !== 'server' && !str_contains((string) ($resource['product'] ?? ''), 'Plex Media Server')) {
                    continue;
                }

                foreach ($resource['connections'] ?? [] as $conn) {
                    $parsed = $this->parseConnectionUri((string) ($conn['uri'] ?? ''));
                    if ($parsed === null) {
                        continue;
                    }

                    $servers[] = [
                        'name' => (string) ($resource['name'] ?? 'Plex Server'),
                        'client_id' => (string) ($resource['clientIdentifier'] ?? ''),
                        'url' => $parsed['host'],
                        'port' => $parsed['port'],
                        'ssl' => $parsed['ssl'],
                        'local' => (bool) ($conn['local'] ?? false),
                        'token' => $token,
                        'type' => 'plex',
                    ];
                }
            }

            if ($servers === []) {
                throw new \RuntimeException('No se encontraron servidores Plex en esta cuenta.');
            }

            return ['token' => $token, 'servers' => $this->dedupeServers($servers)];
        } catch (GuzzleException $e) {
            Logger::error('Plex discovery failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('No se pudo conectar con Plex. Comprueba usuario y contraseña.');
        }
    }

    /** @return array{api_key: string, server: array<string, mixed>} */
    public function discoverJellyfin(string $host, int $port, bool $ssl, string $username, string $password): array
    {
        $scheme = $ssl ? 'https' : 'http';
        $baseUri = "{$scheme}://{$host}:{$port}";

        $client = new Client([
            'base_uri' => $baseUri,
            'timeout' => 30,
            'verify' => false,
        ]);

        try {
            $response = $client->post('/Users/authenticatebyname', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Emby-Authorization' => $this->jellyfinAuthHeader(),
                ],
                'json' => [
                    'Username' => $username,
                    'Pw' => $password,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $token = $data['AccessToken'] ?? null;

            if (!is_string($token) || $token === '') {
                throw new \RuntimeException('Credenciales Jellyfin incorrectas.');
            }

            $infoResponse = $client->get('/System/Info/Public');
            $info = json_decode($infoResponse->getBody()->getContents(), true);

            return [
                'api_key' => $token,
                'server' => [
                    'name' => (string) ($info['ServerName'] ?? 'Jellyfin'),
                    'url' => $host,
                    'port' => $port,
                    'ssl' => $ssl,
                    'type' => 'jellyfin',
                    'version' => (string) ($info['Version'] ?? ''),
                    'api_key' => $token,
                ],
            ];
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin discovery failed', ['host' => $host, 'error' => $e->getMessage()]);
            throw new \RuntimeException('No se pudo conectar con Jellyfin. Comprueba URL, puerto y credenciales.');
        }
    }

    /** @return array<string, mixed>|null */
    private function parseConnectionUri(string $uri): ?array
    {
        if ($uri === '') {
            return null;
        }

        $parts = parse_url($uri);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 32400));

        return [
            'host' => $parts['host'],
            'port' => $port,
            'ssl' => $scheme === 'https',
        ];
    }

    /** @param array<int, array<string, mixed>> $servers */
    private function dedupeServers(array $servers): array
    {
        $seen = [];
        $result = [];

        foreach ($servers as $server) {
            $key = ($server['client_id'] ?? '') . '|' . ($server['url'] ?? '') . ':' . ($server['port'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $server;
        }

        usort($result, fn ($a, $b) => ($b['local'] ?? false) <=> ($a['local'] ?? false));

        return $result;
    }

    /** @return array<string, string> */
    private function plexHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Plex-Client-Identifier' => self::CLIENT_ID,
            'X-Plex-Product' => 'MultiPanel ERP',
            'X-Plex-Version' => '1.1.0',
            'X-Plex-Device' => 'MultiPanel',
            'X-Plex-Platform' => 'Web',
        ];
    }

    private function jellyfinAuthHeader(): string
    {
        return 'MediaBrowser Client="MultiPanel", Device="Web", DeviceId="' . self::CLIENT_ID . '", Version="1.1.0"';
    }
}
