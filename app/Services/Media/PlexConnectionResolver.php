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
    public function resolve(Server $server, bool $quick = true): array
    {
        $token = trim((string) ($server->token ?? ''));
        $candidates = $this->buildCandidates($server);
        $tried = [];
        $lastError = 'No se pudo conectar al servidor Plex.';
        $maxProbes = $quick ? 4 : 50;
        $probes = 0;

        foreach ($candidates as $endpoint) {
            if ($probes >= $maxProbes) {
                break;
            }

            if ($quick && $this->isLocalEndpoint($endpoint)) {
                continue;
            }

            $label = ($endpoint['ssl'] ? 'https' : 'http') . "://{$endpoint['url']}:{$endpoint['port']}";
            $tried[] = $label;
            $error = $this->probe($endpoint, $token, $quick);
            $probes++;

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

    /**
     * @return array{probes: array<int, array<string, mixed>>, plex_tv: array<string, mixed>, final_error: ?string}
     */
    public function diagnose(Server $server): array
    {
        $token = trim((string) ($server->token ?? ''));
        $candidates = $this->buildCandidates($server);
        $probes = [];

        foreach ($candidates as $endpoint) {
            $scheme = $endpoint['ssl'] ? 'https' : 'http';
            $label = "{$scheme}://{$endpoint['url']}:{$endpoint['port']}/";
            $start = microtime(true);
            $error = $this->probe($endpoint, $token, false);
            $probes[] = [
                'url' => $label,
                'ok' => $error === null,
                'error' => $error,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'local' => str_contains($endpoint['url'], '192.168.')
                    || str_contains($endpoint['url'], '10.')
                    || str_starts_with($endpoint['url'], '172.'),
            ];
        }

        return [
            'probes' => $probes,
            'plex_tv' => $this->fetchPlexTvSummary($token, trim((string) ($server->machine_id ?? ''))),
            'final_error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function fetchPlexTvSummary(string $token, string $machineId): array
    {
        if ($token === '') {
            return ['ok' => false, 'error' => 'Sin token Plex', 'resources_found' => 0, 'servers' => []];
        }

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
                return ['ok' => false, 'error' => 'Respuesta inválida de plex.tv', 'resources_found' => 0, 'servers' => []];
            }

            $servers = [];
            foreach ($resources as $resource) {
                $product = strtolower((string) ($resource['product'] ?? ''));
                $provides = $resource['provides'] ?? '';
                $isServer = str_contains($product, 'plex media server')
                    || (is_array($provides) && in_array('server', $provides, true))
                    || str_contains((string) $provides, 'server');

                if (!$isServer) {
                    continue;
                }

                $connections = [];
                foreach ($resource['connections'] ?? [] as $conn) {
                    $parsed = $this->parseConnection($conn);
                    if ($parsed === null) {
                        continue;
                    }
                    $scheme = $parsed['ssl'] ? 'https' : 'http';
                    $connections[] = [
                        'url' => "{$scheme}://{$parsed['url']}:{$parsed['port']}/",
                        'local' => (bool) ($conn['local'] ?? false),
                        'relay' => (bool) ($conn['relay'] ?? false),
                    ];
                }

                $servers[] = [
                    'name' => (string) ($resource['name'] ?? 'Plex'),
                    'client_id' => (string) ($resource['clientIdentifier'] ?? ''),
                    'owned' => (bool) ($resource['owned'] ?? false),
                    'connections' => $connections,
                    'matches_machine_id' => $machineId !== '' && (string) ($resource['clientIdentifier'] ?? '') === $machineId,
                ];
            }

            return [
                'ok' => true,
                'resources_found' => count($servers),
                'servers' => $servers,
            ];
        } catch (GuzzleException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'resources_found' => 0, 'servers' => []];
        }
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
            'port' => (int) ($server->port ?: 32400),
            'ssl' => (bool) $server->ssl,
        ]);

        $token = trim((string) ($server->token ?? ''));
        $machineId = trim((string) ($server->machine_id ?? ''));

        if ($token !== '') {
            if ($machineId !== '') {
                foreach ($this->connectionsFromPlexTv($token, $machineId) as $endpoint) {
                    $add($endpoint);
                }
            } else {
                foreach ($this->allServerConnectionsFromPlexTv($token) as $endpoint) {
                    $add($endpoint);
                }
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
    private function allServerConnectionsFromPlexTv(string $token): array
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
                $product = strtolower((string) ($resource['product'] ?? ''));
                $provides = $resource['provides'] ?? '';
                $isServer = str_contains($product, 'plex media server')
                    || (is_array($provides) && in_array('server', $provides, true))
                    || str_contains((string) $provides, 'server');

                if (!$isServer) {
                    continue;
                }

                foreach ($resource['connections'] ?? [] as $conn) {
                    $parsed = $this->parseConnection($conn);
                    if ($parsed !== null) {
                        $parsed['local'] = (bool) ($conn['local'] ?? false);
                        $parsed['relay'] = (bool) ($conn['relay'] ?? false);
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
            Logger::warning('plex.tv all resources lookup failed', ['error' => $e->getMessage()]);
            return [];
        }
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
    private function probe(array $endpoint, string $token, bool $quick = true): ?string
    {
        $scheme = $endpoint['ssl'] ? 'https' : 'http';
        $uri = "{$scheme}://{$endpoint['url']}:{$endpoint['port']}/";

        try {
            $client = new Client([
                'timeout' => $quick ? 4 : 12,
                'connect_timeout' => $quick ? 2 : 8,
                'verify' => false,
            ]);
            $headers = [
                'Accept' => 'application/xml, application/json',
                'X-Plex-Client-Identifier' => 'multipanel-erp',
                'X-Plex-Product' => 'MultiPanel ERP',
            ];

            if ($token !== '') {
                $headers['X-Plex-Token'] = $token;
            }

            $response = $client->get($uri, ['headers' => $headers]);
            $parsed = PlexResponseParser::parseMediaContainer($response->getBody()->getContents());

            if (!$parsed['ok']) {
                return $parsed['error'] ?? 'El servidor respondió pero no devolvió datos Plex válidos.';
            }

            return null;
        } catch (GuzzleException $e) {
            return $e->getMessage();
        }
    }

    /** @param array{url: string, port: int, ssl: bool} $endpoint */
    private function isLocalEndpoint(array $endpoint): bool
    {
        $host = strtolower($endpoint['url']);
        return str_contains($host, '192.168.')
            || str_contains($host, '10.')
            || str_starts_with($host, '172.');
    }
}
