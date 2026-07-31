<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Server;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Logger;

/**
 * Finds a reachable Plex endpoint (configured URL or via plex.tv connections).
 */
final class PlexConnectionResolver
{
    /** @return array{endpoint: array{url: string, port: int, ssl: bool}, error: ?string, tried: array<int, string>} */
    public function resolve(Server $server): array
    {
        $token = trim((string) ($server->token ?? ''));
        $candidates = $this->buildCandidates($server);
        $tried = [];
        $lastError = 'No se pudo conectar al servidor Plex.';

        foreach ($candidates as $endpoint) {
            $label = ($endpoint['ssl'] ? 'https' : 'http') . "://{$endpoint['url']}:{$endpoint['port']}";
            $tried[] = $label;
            $error = $this->probe($endpoint, $token);

            if ($error === null) {
                return ['endpoint' => $endpoint, 'error' => null, 'tried' => $tried];
            }

            $lastError = $error;
            Logger::debug('Plex probe failed', ['url' => $label, 'error' => $error]);
        }

        if ($tried !== []) {
            $lastError .= ' URLs probadas: ' . implode(', ', $tried);
        }

        $configured = ServerEndpoint::normalize(
            (string) $server->url,
            (int) $server->port,
            (bool) $server->ssl
        );

        return ['endpoint' => $configured, 'error' => $lastError, 'tried' => $tried];
    }

    /** @return array<int, array{url: string, port: int, ssl: bool}> */
    private function buildCandidates(Server $server): array
    {
        $seen = [];
        $list = [];

        $add = function (array $endpoint) use (&$seen, &$list): void {
            $endpoint = ServerEndpoint::normalize($endpoint['url'], $endpoint['port'], $endpoint['ssl']);
            if ($endpoint['url'] === '') {
                return;
            }

            $key = $endpoint['url'] . ':' . $endpoint['port'] . ':' . (int) $endpoint['ssl'];
            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $list[] = $endpoint;
        };

        $add([
            'url' => (string) $server->url,
            'port' => (int) $server->port,
            'ssl' => (bool) $server->ssl,
        ]);

        $token = trim((string) ($server->token ?? ''));
        $machineId = trim((string) ($server->machine_id ?? ''));

        if ($token !== '' && $machineId !== '') {
            foreach ($this->connectionsFromPlexTv($token, $machineId) as $endpoint) {
                $add($endpoint);
            }
        }

        usort($list, static function (array $a, array $b) {
            $score = static fn (array $e) => (str_contains($e['url'], '192.168.') ? 100 : 0)
                + (str_contains($e['url'], '10.') ? 100 : 0);
            return $score($a) <=> $score($b);
        });

        return $list;
    }

    /** @return array<int, array{url: string, port: int, ssl: bool}> */
    private function connectionsFromPlexTv(string $token, string $machineId): array
    {
        $client = new Client([
            'base_uri' => 'https://plex.tv',
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'X-Plex-Token' => $token,
                'X-Plex-Client-Identifier' => 'multipanel-erp',
                'X-Plex-Product' => 'MultiPanel ERP',
            ],
        ]);

        try {
            $response = $client->get('/api/v2/resources', [
                'query' => ['includeHttps' => 1, 'includeRelay' => 1],
            ]);

            $resources = json_decode($response->getBody()->getContents(), true);
            if (!is_array($resources)) {
                return [];
            }

            $endpoints = [];
            foreach ($resources as $resource) {
                $clientId = (string) ($resource['clientIdentifier'] ?? '');
                if ($clientId !== $machineId) {
                    continue;
                }

                foreach ($resource['connections'] ?? [] as $conn) {
                    $parsed = $this->parseConnection($conn);
                    if ($parsed !== null) {
                        $parsed['relay'] = (bool) ($conn['relay'] ?? false);
                        $parsed['local'] = (bool) ($conn['local'] ?? false);
                        $endpoints[] = $parsed;
                    }
                }
            }

            usort($endpoints, static function ($a, $b) {
                $score = static fn ($e) => (($e['local'] ?? false) ? 10 : 0) + (($e['relay'] ?? false) ? 5 : 0);
                return $score($a) <=> $score($b);
            });

            return array_map(static fn ($e) => [
                'url' => $e['url'],
                'port' => $e['port'],
                'ssl' => $e['ssl'],
            ], $endpoints);
        } catch (GuzzleException $e) {
            Logger::warning('plex.tv resources lookup failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** @param array<string, mixed> $conn */
    private function parseConnection(array $conn): ?array
    {
        if (!empty($conn['uri'])) {
            $parts = parse_url((string) $conn['uri']);
            if ($parts !== false && !empty($parts['host'])) {
                $scheme = $parts['scheme'] ?? 'http';
                return [
                    'url' => $parts['host'],
                    'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 32400)),
                    'ssl' => $scheme === 'https',
                ];
            }
        }

        $address = (string) ($conn['address'] ?? '');
        if ($address === '') {
            return null;
        }

        $protocol = (string) ($conn['protocol'] ?? 'http');

        return [
            'url' => $address,
            'port' => (int) ($conn['port'] ?? ($protocol === 'https' ? 443 : 32400)),
            'ssl' => $protocol === 'https',
        ];
    }

    /** @param array{url: string, port: int, ssl: bool} $endpoint */
    private function probe(array $endpoint, string $token): ?string
    {
        $scheme = $endpoint['ssl'] ? 'https' : 'http';
        $uri = "{$scheme}://{$endpoint['url']}:{$endpoint['port']}/";

        try {
            $client = new Client(['timeout' => 12, 'connect_timeout' => 8, 'verify' => false]);
            $headers = [
                'Accept' => 'application/xml',
                'X-Plex-Client-Identifier' => 'multipanel-erp',
                'X-Plex-Product' => 'MultiPanel ERP',
            ];

            if ($token !== '') {
                $headers['X-Plex-Token'] = $token;
            }

            $response = $client->get($uri, ['headers' => $headers]);
            $xml = simplexml_load_string($response->getBody()->getContents());

            if ($xml === false) {
                return 'El servidor respondió pero no devolvió XML válido.';
            }

            if (!isset($xml['machineIdentifier']) && !isset($xml['friendlyName'])) {
                return 'Respuesta Plex no reconocida (¿token inválido?).';
            }

            return null;
        } catch (GuzzleException $e) {
            return $e->getMessage();
        }
    }
}
