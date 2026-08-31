<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Server;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Core\Cache;
use Core\Logger;

/**
 * Finds a reachable Plex endpoint (configured URL or via plex.tv connections).
 */
final class PlexConnectionResolver
{
    /**
     * Cuánto tiempo se reutiliza un endpoint ya verificado sin volver a sondear
     * candidatos ni consultar plex.tv. Esto es crítico para las carátulas: la
     * vista "En directo" pide una imagen por sesión activa (varias peticiones
     * en paralelo) y sin caché cada una repetía la resolución completa
     * (candidatos + sondeo con timeouts de hasta varios segundos cada uno),
     * lo que provocaba timeouts y carátulas que nunca cargaban.
     */
    private const ENDPOINT_CACHE_TTL = 120;

    /**
     * Cuando NINGÚN candidato responde (servidor apagado, caído, firewall, etc.),
     * cacheamos también ese fallo un rato corto. Sin esto, una vista con varias
     * carátulas a la vez (o varios refrescos seguidos) repite la ronda completa
     * de sondeos (hasta 4 intentos x 4s = ~16s) por cada imagen mientras el
     * servidor sigue caído, dejando la página colgada. Con el fallo cacheado,
     * solo se paga ese coste una vez cada 20s.
     */
    private const FAILURE_CACHE_TTL = 20;

    /** @return array{endpoint: array{url: string, port: int, ssl: bool}, error: ?string, tried: array<int, string>} */
    public function resolve(Server $server, bool $quick = true): array
    {
        $cacheKey = 'plex_resolved_endpoint_' . (int) $server->id;
        $failureCacheKey = 'plex_resolve_failed_' . (int) $server->id;

        if ($quick) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['url'], $cached['port'], $cached['ssl'])) {
                return ['endpoint' => $cached, 'error' => null, 'tried' => []];
            }

            $failedRecently = Cache::get($failureCacheKey);
            if (is_array($failedRecently) && isset($failedRecently['error'])) {
                return [
                    'endpoint' => $failedRecently['endpoint'],
                    'error' => (string) $failedRecently['error'],
                    'tried' => $failedRecently['tried'] ?? [],
                ];
            }
        }

        $token = trim((string) ($server->token ?? ''));
        $candidates = $this->buildCandidates($server);
        $tried = [];
        $seenProbes = [];
        $lastError = 'No se pudo conectar al servidor Plex.';
        $maxProbes = $quick ? 4 : 50;
        $probes = 0;

        foreach ($candidates as $endpoint) {
            if ($probes >= $maxProbes) {
                break;
            }

            // El panel suele estar fuera de la LAN del cliente: no perder tiempo con
            // 192.168.x ni con *.plex.direct que codifican IP privada.
            if ($this->isLocalEndpoint($endpoint)) {
                continue;
            }

            $probeEndpoint = self::asHttpProbe($endpoint);
            $probeKey = $probeEndpoint['url'] . ':' . $probeEndpoint['port'];
            if (isset($seenProbes[$probeKey])) {
                continue;
            }
            $seenProbes[$probeKey] = true;

            $label = "http://{$probeEndpoint['url']}:{$probeEndpoint['port']}";
            $tried[] = $label;
            $error = $this->probe($probeEndpoint, $token, $quick);
            $probes++;

            if ($error === null) {
                Cache::set($cacheKey, $probeEndpoint, self::ENDPOINT_CACHE_TTL);
                return ['endpoint' => $probeEndpoint, 'error' => null, 'tried' => $tried];
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

        if ($quick) {
            Cache::set($failureCacheKey, [
                'endpoint' => $configured,
                'error' => $lastError,
                'tried' => $tried,
            ], self::FAILURE_CACHE_TTL);
        }

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
        $seenProbes = [];

        foreach ($candidates as $endpoint) {
            if ($this->isLocalEndpoint($endpoint)) {
                continue;
            }

            $probeEndpoint = self::asHttpProbe($endpoint);
            $probeKey = $probeEndpoint['url'] . ':' . $probeEndpoint['port'];
            if (isset($seenProbes[$probeKey])) {
                continue;
            }
            $seenProbes[$probeKey] = true;

            $label = "http://{$probeEndpoint['url']}:{$probeEndpoint['port']}/";
            $start = microtime(true);
            $error = $this->probe($probeEndpoint, $token, false);
            $probes[] = [
                'url' => $label,
                'ok' => $error === null,
                'error' => $error,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'local' => self::isLocalHost((string) $probeEndpoint['url']),
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

        $configured = ServerEndpoint::normalize(
            (string) $server->url,
            (int) ($server->port ?: 32400),
            (bool) $server->ssl,
        );
        $configuredHost = $configured['url'];
        $hasCustomHost = ServerEndpoint::isHostname($configuredHost)
            && !self::isPlexDirectHost($configuredHost);

        $add($configured);

        $configuredPort = (int) ($server->port ?: 32400);
        $addFromPlexTv = function (array $endpoint) use ($add, $configuredPort, $hasCustomHost): void {
            if ((int) ($endpoint['port'] ?? 0) !== $configuredPort) {
                return;
            }
            if ($hasCustomHost && self::isPlexDirectHost((string) ($endpoint['url'] ?? ''))) {
                return;
            }
            $add($endpoint);
        };

        $token = trim((string) ($server->token ?? ''));
        $machineId = trim((string) ($server->machine_id ?? ''));

        if ($token !== '') {
            if ($machineId !== '') {
                foreach ($this->connectionsFromPlexTv($token, $machineId) as $endpoint) {
                    $addFromPlexTv($endpoint);
                }
            } else {
                foreach ($this->allServerConnectionsFromPlexTv($token) as $endpoint) {
                    $addFromPlexTv($endpoint);
                }
            }
        }

        usort($list, function (array $a, array $b) use ($configuredHost) {
            // Tu dominio del panel siempre primero; luego públicos HTTP; LAN al final.
            $score = fn (array $e): int => (strtolower((string) $e['url']) === strtolower($configuredHost) ? -1000 : 0)
                + ($this->isLocalEndpoint($e) ? 100 : 0)
                + (!empty($e['ssl']) ? 10 : 0);

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
                    if (!empty($conn['local'])) {
                        continue;
                    }
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

            return array_values(array_filter(array_map(static fn ($e) => [
                'url' => $e['url'],
                'port' => $e['port'],
                'ssl' => $e['ssl'],
            ], $endpoints), static function (array $e): bool {
                return !self::isLocalHost((string) ($e['url'] ?? ''));
            }));
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
                    if (!empty($conn['local'])) {
                        continue;
                    }
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

            return array_values(array_filter(array_map(static fn ($e) => [
                'url' => $e['url'],
                'port' => $e['port'],
                'ssl' => $e['ssl'],
            ], $endpoints), static function (array $e): bool {
                return !self::isLocalHost((string) ($e['url'] ?? ''));
            }));
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
        $endpoint = self::asHttpProbe($endpoint);
        $connect = self::resolveConnectHost((string) $endpoint['url']);
        $uri = "http://{$connect['connect_host']}:{$endpoint['port']}/";

        try {
            $client = new Client([
                'timeout' => $quick ? 4 : 12,
                'connect_timeout' => $quick ? 4 : 8,
                'verify' => false,
            ]);
            $headers = [
                'Accept' => 'application/xml, application/json',
                'X-Plex-Client-Identifier' => 'multipanel-erp',
                'X-Plex-Product' => 'MultiPanel ERP',
            ];

            if ($connect['host_header'] !== null) {
                $headers['Host'] = $connect['host_header'];
            }

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

    /**
     * Host *.plex.direct codifica la IP (79-116-40-195 → 79.116.40.195).
     * El DNS de plex.direct a veces falla o tarda en VPS; conectar por IP evita la resolución.
     *
     * @return array{connect_host: string, host_header: ?string}
     */
    public static function resolveConnectHost(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $ip = self::ipv4FromPlexDirectHost($hostname);

        if ($ip !== null) {
            return ['connect_host' => $ip, 'host_header' => $hostname];
        }

        return ['connect_host' => $hostname, 'host_header' => null];
    }

    public static function ipv4FromPlexDirectHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if (!self::isPlexDirectHost($host)) {
            return null;
        }

        $firstLabel = explode('.', $host)[0] ?? '';
        if (preg_match('/^(\d{1,3})-(\d{1,3})-(\d{1,3})-(\d{1,3})$/', $firstLabel, $m) !== 1) {
            return null;
        }

        $ip = "{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}";

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
    }

    public static function isPlexDirectHost(string $host): bool
    {
        return str_ends_with(strtolower(trim($host)), '.plex.direct');
    }

    /**
     * Mismo host y puerto que el candidato, pero sin SSL (HTTP).
     *
     * @param array{url: string, port: int, ssl: bool} $endpoint
     * @return array{url: string, port: int, ssl: bool}
     */
    public static function asHttpProbe(array $endpoint): array
    {
        $port = (int) ($endpoint['port'] ?? 32400);

        return [
            'url' => (string) ($endpoint['url'] ?? ''),
            'port' => $port > 0 ? $port : 32400,
            'ssl' => false,
        ];
    }

    /** @param array{url: string, port: int, ssl: bool} $endpoint */
    private function isLocalEndpoint(array $endpoint): bool
    {
        return self::isLocalHost((string) ($endpoint['url'] ?? ''));
    }

    /**
     * IP privada, localhost o hostname plex.direct que codifica LAN (p. ej. 192-168-1-100.*.plex.direct).
     */
    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::isPrivateIpv4($host);
        }

        if (str_ends_with($host, '.plex.direct')) {
            $ip = self::ipv4FromPlexDirectHost($host);
            if ($ip !== null) {
                return self::isPrivateIpv4($ip);
            }
        }

        return false;
    }

    private static function isPrivateIpv4(string $ip): bool
    {
        $parts = array_map('intval', explode('.', $ip));
        if (count($parts) !== 4) {
            return false;
        }

        return $parts[0] === 10
            || ($parts[0] === 192 && $parts[1] === 168)
            || ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31)
            || ($parts[0] === 127);
    }
}
