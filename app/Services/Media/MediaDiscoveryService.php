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
        $token = $this->loginPlex($login, $password);

        $client = new Client([
            'base_uri' => 'https://plex.tv',
            'timeout' => 30,
            'headers' => array_merge($this->plexHeaders(), ['X-Plex-Token' => $token]),
        ]);

        try {
            $resources = $client->get('/api/v2/resources', [
                'query' => ['includeHttps' => 1, 'includeRelay' => 1],
            ]);

            $data = json_decode($resources->getBody()->getContents(), true);
            $servers = [];

            foreach (is_array($data) ? $data : [] as $resource) {
                if (!$this->isPlexServer($resource)) {
                    continue;
                }

                foreach ($resource['connections'] ?? [] as $conn) {
                    $parsed = $this->parseConnection($conn);
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
                        'relay' => (bool) ($conn['relay'] ?? false),
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
            Logger::error('Plex resources failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Login OK pero no se pudieron listar servidores: ' . $e->getMessage());
        }
    }

    private function loginPlex(string $login, string $password): string
    {
        $client = new Client(['timeout' => 30, 'verify' => true]);

        try {
            $response = $client->post('https://plex.tv/api/v2/login', [
                'headers' => $this->plexHeaders(),
                'json' => [
                    'login' => $login,
                    'password' => $password,
                    'rememberMe' => false,
                ],
            ]);

            $auth = json_decode($response->getBody()->getContents(), true);
            $token = $auth['authToken'] ?? null;
            if (is_string($token) && $token !== '') {
                return $token;
            }
        } catch (GuzzleException $e) {
            Logger::warning('Plex v2 login failed', ['error' => $e->getMessage()]);
        }

        try {
            $response = $client->post('https://plex.tv/users/sign_in.json', [
                'headers' => $this->plexHeaders(),
                'form_params' => [
                    'user[login]' => $login,
                    'user[password]' => $password,
                ],
            ]);

            $headers = $response->getHeader('X-Plex-Token');
            if ($headers !== []) {
                return $headers[0];
            }

            $body = json_decode($response->getBody()->getContents(), true);
            if (is_array($body) && !empty($body['user']['auth_token'])) {
                return (string) $body['user']['auth_token'];
            }
        } catch (GuzzleException $e) {
            Logger::error('Plex sign_in failed', ['error' => $e->getMessage()]);
        }

        throw new \RuntimeException('Credenciales Plex incorrectas o cuenta con 2FA (usa token manual).');
    }

    /** @param array<string, mixed> $resource */
    private function isPlexServer(array $resource): bool
    {
        $product = (string) ($resource['product'] ?? '');
        if (str_contains(strtolower($product), 'plex media server')) {
            return true;
        }

        $provides = $resource['provides'] ?? '';
        if (is_array($provides)) {
            return in_array('server', $provides, true);
        }

        return str_contains((string) $provides, 'server');
    }

    /** @param array<string, mixed> $conn */
    private function parseConnection(array $conn): ?array
    {
        if (!empty($conn['uri'])) {
            return $this->parseConnectionUri((string) $conn['uri']);
        }

        $address = (string) ($conn['address'] ?? '');
        if ($address === '') {
            return null;
        }

        $protocol = (string) ($conn['protocol'] ?? 'http');
        $port = (int) ($conn['port'] ?? ($protocol === 'https' ? 443 : 32400));

        return [
            'host' => $address,
            'port' => $port,
            'ssl' => $protocol === 'https',
        ];
    }

    /** @return array{api_key: string, server: array<string, mixed>} */
    public function discoverJellyfin(string $host, int $port, bool $ssl, string $username, string $password): array
    {
        $endpoint = ServerEndpoint::normalize($host, $port, $ssl);
        $scheme = $endpoint['ssl'] ? 'https' : 'http';
        $baseUri = "{$scheme}://{$endpoint['url']}:{$endpoint['port']}";

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
                    'url' => $endpoint['url'],
                    'port' => $endpoint['port'],
                    'ssl' => $endpoint['ssl'],
                    'type' => 'jellyfin',
                    'version' => (string) ($info['Version'] ?? ''),
                    'api_key' => $token,
                ],
            ];
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin discovery failed', ['host' => $host, 'error' => $e->getMessage()]);
            throw new \RuntimeException('No se pudo conectar con Jellyfin: ' . $e->getMessage());
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

        // Prefer remote/public connections (VPS no alcanza 192.168.x)
        usort($result, function ($a, $b) {
            $score = static fn ($s) => (($s['local'] ?? false) ? 10 : 0) + (($s['relay'] ?? false) ? 5 : 0);
            return $score($a) <=> $score($b);
        });

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
