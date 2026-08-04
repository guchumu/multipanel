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

    /** Base URL efectiva (scheme://host:port) usada por el client Guzzle. */
    private string $baseUrl;

    private ?string $lastError = null;

    private ?string $lastArtworkError = null;

    public function __construct(
        private Server $server,
    ) {
        $resolver = new PlexConnectionResolver();
        $endpoint = ServerEndpoint::normalize(
            (string) $server->url,
            (int) ($server->port ?: 32400),
            (bool) $server->ssl
        );

        $resolved = $resolver->resolve($server);
        if ($resolved['error'] !== null && $resolved['tried'] !== []) {
            // Todos los endpoints sondeados fallaron: marcamos el error ya para
            // que los métodos de la API (sesiones, bibliotecas, etc.) devuelvan
            // vacío al instante en vez de esperar otros 30s de timeout contra
            // un servidor que sabemos inaccesible. Si no se sondeó ninguno
            // (p. ej. solo hay candidatos locales en modo rápido), se intenta
            // igualmente con la URL configurada.
            $this->lastError = $resolved['error'];
        }
        if ($resolved['error'] === null) {
            $endpoint = $resolved['endpoint'];
            if ($this->shouldPersistEndpoint($endpoint)) {
                if (!ServerEndpoint::shouldPreferCurrentHost((string) $this->server->url, $endpoint)) {
                    $server->url = $endpoint['url'];
                    $server->port = $endpoint['port'];
                    $server->ssl = $endpoint['ssl'] ? 1 : 0;
                    $server->save();
                } elseif ((int) $endpoint['port'] !== (int) $this->server->port
                    || (bool) $endpoint['ssl'] !== (bool) $this->server->ssl) {
                    $server->port = $endpoint['port'];
                    $server->ssl = $endpoint['ssl'] ? 1 : 0;
                    $server->save();
                }
            }
        }

        $scheme = $endpoint['ssl'] ? 'https' : 'http';
        $this->baseUrl = "{$scheme}://{$endpoint['url']}:{$endpoint['port']}";
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'connect_timeout' => 15,
            'verify' => false,
            'headers' => array_merge([
                'Accept' => 'application/xml',
                'X-Plex-Client-Identifier' => 'multipanel-erp',
                'X-Plex-Product' => 'MultiPanel ERP',
                'X-Plex-Version' => '1.1.0',
            ], $this->authHeaders()),
        ]);
    }

    /** @param array{url: string, port: int, ssl: bool} $endpoint */
    private function shouldPersistEndpoint(array $endpoint): bool
    {
        return $endpoint['url'] !== (string) $this->server->url
            || (int) $endpoint['port'] !== (int) $this->server->port
            || (bool) $endpoint['ssl'] !== (bool) $this->server->ssl;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** @return array<string, mixed>|null */
    public function getServerInfo(): ?array
    {
        if ($this->lastError !== null) {
            return null;
        }

        try {
            $response = $this->client->get('/', [
                'headers' => $this->authHeaders(),
            ]);

            $parsed = PlexResponseParser::parseMediaContainer($response->getBody()->getContents());
            if (!$parsed['ok'] || $parsed['container'] === null) {
                $this->lastError = $parsed['error'] ?? 'Respuesta inválida del servidor Plex.';
                return null;
            }

            $c = $parsed['container'];

            return [
                'machine_id' => (string) ($c['machineIdentifier'] ?? $c['machine_identifier'] ?? ''),
                'version' => (string) ($c['version'] ?? ''),
                'platform' => (string) ($c['platform'] ?? ''),
                'name' => (string) ($c['friendlyName'] ?? $c['friendly_name'] ?? $this->server->name),
            ];
        } catch (GuzzleException $e) {
            $this->lastError = $e->getMessage();
            Logger::error('Plex server info failed', ['server_id' => $this->server->id, 'error' => $e->getMessage(), 'url' => $this->server->fullUrl()]);
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getUsers(): array
    {
        if ($this->lastError !== null) {
            return [];
        }

        foreach (['/api/users', '/accounts'] as $path) {
            $users = $this->fetchUsersFromPath($path);
            if ($users !== []) {
                return $users;
            }
        }

        return $this->fetchUsersFromPlexTv();
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchUsersFromPath(string $path): array
    {
        try {
            $response = $this->client->get($path, [
                'headers' => $this->authHeaders(),
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (is_array($data)) {
                return array_map(fn ($user) => [
                    'external_id' => (string) ($user['id'] ?? $user['key'] ?? ''),
                    'username' => $user['username'] ?? $user['title'] ?? $user['name'] ?? '',
                    'email' => $user['email'] ?? null,
                    'thumb' => $user['thumb'] ?? null,
                    'restricted' => $user['restricted'] ?? false,
                ], $data);
            }

            $xml = simplexml_load_string($body);
            if ($xml === false) {
                return [];
            }

            $users = [];
            foreach ($xml->User ?? $xml->Account ?? [] as $account) {
                $users[] = [
                    'external_id' => (string) ($account['id'] ?? $account['key'] ?? ''),
                    'username' => (string) ($account['title'] ?? $account['name'] ?? $account['defaultTitle'] ?? ''),
                    'email' => isset($account['email']) ? (string) $account['email'] : null,
                    'thumb' => (string) ($account['thumb'] ?? '') ?: null,
                    'restricted' => false,
                ];
            }

            return $users;
        } catch (GuzzleException $e) {
            Logger::debug('Plex users path failed', ['path' => $path, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchUsersFromPlexTv(): array
    {
        $token = trim((string) ($this->server->token ?? ''));
        $machineId = trim((string) ($this->server->machine_id ?? ''));

        if ($token === '' || $machineId === '') {
            return [];
        }

        try {
            $client = new Client([
                'base_uri' => 'https://plex.tv',
                'timeout' => 20,
                'verify' => true,
                'headers' => array_merge($this->authHeaders(), ['Accept' => 'application/json']),
            ]);

            $response = $client->get("/api/v2/servers/{$machineId}/users");
            $data = json_decode($response->getBody()->getContents(), true);

            if (!is_array($data)) {
                return [];
            }

            return array_map(static fn ($user) => [
                'external_id' => (string) ($user['id'] ?? ''),
                'username' => (string) ($user['username'] ?? $user['title'] ?? ''),
                'email' => $user['email'] ?? null,
                'thumb' => $user['thumb'] ?? null,
                'restricted' => $user['restricted'] ?? false,
            ], $data);
        } catch (GuzzleException $e) {
            Logger::debug('Plex.tv users failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getLibraries(): array
    {
        if ($this->lastError !== null) {
            return [];
        }

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
        if ($this->lastError !== null) {
            return [];
        }

        try {
            $response = $this->client->get('/status/sessions', [
                'headers' => array_merge($this->authHeaders(), [
                    'Accept' => 'application/json, application/xml',
                ]),
            ]);

            $body = $response->getBody()->getContents();
            $sessions = $this->parseSessionsBody($body);
            if ($sessions !== null) {
                return $sessions;
            }

            $this->lastError = 'Respuesta de sesiones Plex no reconocida.';
            return [];
        } catch (GuzzleException $e) {
            $this->lastError = $e->getMessage();
            Logger::error('Plex get sessions failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>>|null */
    private function parseSessionsBody(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        if ($body[0] === '{' || $body[0] === '[') {
            $json = json_decode($body, true);
            if (!is_array($json)) {
                return null;
            }

            $container = $json['MediaContainer'] ?? $json;
            if (!is_array($container)) {
                return [];
            }

            $items = $container['Metadata'] ?? $container['Video'] ?? $container['Track'] ?? $container['Photo'] ?? [];
            if (!is_array($items)) {
                return [];
            }

            if ($items !== [] && !array_is_list($items)) {
                $items = [$items];
            }

            return array_map(fn (array $session) => $this->parsePlexSessionArray($session), $items);
        }

        $xml = simplexml_load_string($body);
        if ($xml === false) {
            return null;
        }

        $sessions = [];
        foreach (['Video', 'Track', 'Photo'] as $tag) {
            foreach ($xml->{$tag} ?? [] as $session) {
                $sessions[] = $this->parsePlexSession($session);
            }
        }

        return $sessions;
    }

    /** @param array<string, mixed> $session */
    private function parsePlexSessionArray(array $session): array
    {
        $type = (string) ($session['type'] ?? 'video');
        $grandparent = (string) ($session['grandparentTitle'] ?? '');
        $parent = (string) ($session['parentTitle'] ?? '');
        $title = (string) ($session['title'] ?? '');

        if ($type === 'episode' && $grandparent !== '') {
            $displayTitle = $grandparent . ' · S' . ($session['parentIndex'] ?? '?') . 'E' . ($session['index'] ?? '?');
            $subtitle = $title;
        } elseif ($parent !== '' && $title !== '') {
            $displayTitle = $parent . ' — ' . $title;
            $subtitle = null;
        } else {
            $displayTitle = $title;
            $subtitle = null;
        }

        $transcode = is_array($session['TranscodeSession'] ?? null) ? $session['TranscodeSession'] : null;
        $videoDecision = $transcode ? (string) ($transcode['videoDecision'] ?? '') : 'copy';
        $audioDecision = $transcode ? (string) ($transcode['audioDecision'] ?? '') : 'copy';
        $playMethod = $this->resolvePlexPlayMethod($transcode !== null, $videoDecision, $audioDecision);

        $viewOffset = (int) ($session['viewOffset'] ?? 0);
        $duration = (int) ($session['duration'] ?? 0);
        $progress = $duration > 0 ? min(100, (int) round(($viewOffset / $duration) * 100)) : 0;

        $user = is_array($session['User'] ?? null) ? $session['User'] : [];
        $player = is_array($session['Player'] ?? null) ? $session['Player'] : [];
        // Igual que SERVEROLD: preferir carátula de la serie (grandparent)
        // frente al thumb del episodio, que a menudo es landscape o vacío.
        $thumb = $this->resolveSessionArtPath(
            (string) ($session['grandparentThumb'] ?? ''),
            (string) ($session['thumb'] ?? ''),
            (string) ($session['parentThumb'] ?? ''),
            (string) ($session['art'] ?? ''),
            (string) ($session['grandparentRatingKey'] ?? ''),
            (string) ($session['ratingKey'] ?? ''),
        );

        return [
            'session_id' => (string) ($session['sessionKey'] ?? ''),
            'title' => $displayTitle,
            'subtitle' => $subtitle,
            'user' => (string) ($user['title'] ?? ''),
            'player' => (string) ($player['title'] ?? ''),
            'platform' => (string) ($player['platform'] ?? $player['device'] ?? ''),
            'state' => (string) ($player['state'] ?? 'playing'),
            'media_type' => $type,
            'year' => (string) ($session['year'] ?? ''),
            'play_method' => $playMethod,
            'video_decision' => $videoDecision,
            'audio_decision' => $audioDecision,
            'progress' => $progress,
            'art_path' => $thumb,
            'thumb_url' => $this->mediaUrl($thumb),
        ];
    }

    /** @return array<string, mixed> */
    private function parsePlexSession(\SimpleXMLElement $session): array
    {
        $type = (string) ($session['type'] ?? 'video');
        $grandparent = (string) ($session['grandparentTitle'] ?? '');
        $parent = (string) ($session['parentTitle'] ?? '');
        $title = (string) ($session['title'] ?? '');

        if ($type === 'episode' && $grandparent !== '') {
            $displayTitle = $grandparent . ' · S' . ($session['parentIndex'] ?? '?') . 'E' . ($session['index'] ?? '?');
            $subtitle = $title;
        } elseif ($parent !== '' && $title !== '') {
            $displayTitle = $parent . ' — ' . $title;
            $subtitle = null;
        } else {
            $displayTitle = $title;
            $subtitle = null;
        }

        $transcode = $session->TranscodeSession ?? null;
        $videoDecision = $transcode ? (string) ($transcode['videoDecision'] ?? '') : 'copy';
        $audioDecision = $transcode ? (string) ($transcode['audioDecision'] ?? '') : 'copy';
        $playMethod = $this->resolvePlexPlayMethod($transcode !== null, $videoDecision, $audioDecision);

        $viewOffset = (int) ($session['viewOffset'] ?? 0);
        $duration = (int) ($session['duration'] ?? 0);
        $progress = $duration > 0 ? min(100, (int) round(($viewOffset / $duration) * 100)) : 0;

        $thumb = $this->resolveSessionArtPath(
            (string) ($session['grandparentThumb'] ?? ''),
            (string) ($session['thumb'] ?? ''),
            (string) ($session['parentThumb'] ?? ''),
            (string) ($session['art'] ?? ''),
            (string) ($session['grandparentRatingKey'] ?? ''),
            (string) ($session['ratingKey'] ?? ''),
        );

        return [
            'session_id' => (string) ($session['sessionKey'] ?? ''),
            'title' => $displayTitle,
            'subtitle' => $subtitle,
            'user' => (string) ($session->User['title'] ?? ''),
            'player' => (string) ($session->Player['title'] ?? ''),
            'platform' => (string) ($session->Player['platform'] ?? $session->Player['device'] ?? ''),
            'state' => (string) ($session->Player['state'] ?? 'playing'),
            'media_type' => $type,
            'year' => (string) ($session['year'] ?? ''),
            'play_method' => $playMethod,
            'video_decision' => $videoDecision,
            'audio_decision' => $audioDecision,
            'progress' => $progress,
            'art_path' => $thumb,
            'thumb_url' => $this->mediaUrl($thumb),
        ];
    }

    private function resolveSessionArtPath(
        string $grandparentThumb,
        string $thumb,
        string $parentThumb,
        string $art,
        string $grandparentRatingKey,
        string $ratingKey,
    ): string {
        $picked = $this->pickArtPath($grandparentThumb, $thumb, $parentThumb, $art);
        if ($picked !== '') {
            return $picked;
        }

        // Fallback: construir thumb desde ratingKey (a veces Plex omite *Thumb en JSON).
        $grandparentRatingKey = trim($grandparentRatingKey);
        if ($grandparentRatingKey !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $grandparentRatingKey)) {
            return '/library/metadata/' . $grandparentRatingKey . '/thumb';
        }

        $ratingKey = trim($ratingKey);
        if ($ratingKey !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $ratingKey)) {
            return '/library/metadata/' . $ratingKey . '/thumb';
        }

        return '';
    }

    private function pickArtPath(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeArtPath($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * Plex a veces devuelve rutas relativas (/library/...) y a veces URLs
     * absolutas (http://127.0.0.1:32400/library/...). Nos quedamos solo con
     * el path relativo, igual que hacía SERVEROLD con getServerBaseUrl + path.
     */
    private function normalizeArtPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        // Ignorar thumbs de avatar/plex.tv que no son carátulas de media.
        if (str_contains($path, 'plex.tv/') || str_contains($path, '/users/') || str_contains($path, 'photo.plex.tv')) {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parts = parse_url($path);
            $relative = (string) ($parts['path'] ?? '');
            if ($relative === '') {
                return '';
            }
            if (!empty($parts['query'])) {
                // Quitar tokens de la query residual; los añadimos nosotros.
                parse_str((string) $parts['query'], $qs);
                unset($qs['X-Plex-Token'], $qs['X-Plex-Token ']);
                if ($qs !== []) {
                    $relative .= '?' . http_build_query($qs);
                }
            }

            return $relative;
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    private function resolvePlexPlayMethod(bool $hasTranscode, string $videoDecision, string $audioDecision): string
    {
        if (!$hasTranscode || ($videoDecision === 'copy' && $audioDecision === 'copy')) {
            return 'direct_play';
        }

        if ($videoDecision === 'copy' || $audioDecision === 'copy') {
            return 'direct_stream';
        }

        return 'transcode';
    }

    private function mediaUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $base = rtrim($this->server->fullUrl(), '/');
        $token = trim((string) ($this->server->token ?? ''));
        $sep = str_contains($path, '?') ? '&' : '?';

        return $base . $path . ($token !== '' ? "{$sep}X-Plex-Token={$token}" : '');
    }

    /**
     * Descarga una carátula al estilo del panel antiguo (SERVEROLD/image-proxy.php):
     * 1) cURL simple a URL absoluta + X-Plex-Token en query (lo que funcionaba).
     * 2) Mismo client Guzzle que ya alcanza el PMS para las sesiones.
     * 3) Fallbacks photo/:/transcode.
     *
     * @return array{body: string, content_type: string}|null
     */
    public function fetchArtwork(string $path): ?array
    {
        $path = $this->normalizeArtPath($path);
        if ($path === '') {
            $this->lastArtworkError = 'Ruta de carátula vacía o no válida.';
            return null;
        }

        $cacheKey = 'plex_art_' . (int) $this->server->id . '_' . sha1($path);
        $cached = \Core\Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['body'], $cached['content_type']) && is_string($cached['body']) && $cached['body'] !== '') {
            $this->lastArtworkError = null;
            return $cached;
        }

        $token = trim((string) ($this->server->token ?? ''));
        $pathOnly = explode('?', $path, 2)[0];
        $bases = $this->artworkBaseUrls();
        $errors = [];

        // --- Paso A: client Guzzle principal (misma base que /status/sessions) ---
        if ($this->lastError === null && $pathOnly !== '') {
            try {
                $response = $this->client->get($pathOnly, [
                    'http_errors' => false,
                    'allow_redirects' => true,
                    'headers' => array_merge($this->authHeaders(), [
                        'Accept' => '*' . '/' . '*',
                    ]),
                    'query' => $token !== '' ? ['X-Plex-Token' => $token] : [],
                ]);
                $got = $this->artworkFromResponse(
                    $response->getStatusCode(),
                    $response->getBody()->getContents(),
                    $response->getHeaderLine('Content-Type'),
                    'client:' . $this->baseUrl . $pathOnly,
                    $errors
                );
                if ($got !== null) {
                    \Core\Cache::set($cacheKey, $got, 300);
                    return $got;
                }
            } catch (GuzzleException $e) {
                $errors[] = 'client → ' . $e->getMessage();
            }
        }

        // --- Paso B/C: URL absoluta por cada base (cURL como SERVEROLD, luego Guzzle) ---
        foreach ($bases as $base) {
            $candidates = [
                $pathOnly,
                '/photo/:/transcode?width=300&height=450&minSize=1&upscale=1&url=' . rawurlencode($pathOnly),
                '/photo/:/transcode?width=300&height=450&minSize=1&upscale=1&url='
                    . rawurlencode('http://127.0.0.1:32400' . $pathOnly),
            ];

            foreach ($candidates as $candidate) {
                $sep = str_contains($candidate, '?') ? '&' : '?';
                $url = rtrim($base, '/') . $candidate;
                if ($token !== '' && !str_contains($url, 'X-Plex-Token=')) {
                    $url .= $sep . 'X-Plex-Token=' . rawurlencode($token);
                }

                $got = $this->downloadArtworkUrl($url, $token, $errors);
                if ($got !== null) {
                    \Core\Cache::set($cacheKey, $got, 300);
                    return $got;
                }
            }
        }

        $this->lastArtworkError = $errors !== [] ? implode(' | ', array_slice($errors, 0, 6)) : 'Sin respuesta';
        Logger::warning('Plex artwork fetch failed', [
            'server_id' => $this->server->id,
            'path' => $path,
            'error' => $this->lastArtworkError,
        ]);

        return null;
    }

    /**
     * @param array<int, string> $errors
     * @return array{body: string, content_type: string}|null
     */
    private function downloadArtworkUrl(string $url, string $token, array &$errors): ?array
    {
        // 1) cURL nativo — idéntico en espíritu a SERVEROLD/image-proxy.php
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                $headers = ['Accept: ' . '*' . '/' . '*', 'User-Agent: MultiPanel ERP/1.1'];
                if ($token !== '') {
                    $headers[] = 'X-Plex-Token: ' . $token;
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER => $headers,
                ]);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($body === false) {
                    $errors[] = "curl {$url} → {$curlError}";
                } else {
                    $got = $this->artworkFromResponse($code, (string) $body, $contentType, 'curl:' . $url, $errors);
                    if ($got !== null) {
                        return $got;
                    }
                }
            }
        }

        // 2) Guzzle de respaldo
        try {
            $http = new Client([
                'timeout' => 10,
                'connect_timeout' => 5,
                'verify' => false,
                'allow_redirects' => true,
                'http_errors' => false,
                'headers' => [
                    'Accept' => '*' . '/' . '*',
                    'User-Agent' => 'MultiPanel ERP/1.1',
                    'X-Plex-Client-Identifier' => 'multipanel-erp',
                    'X-Plex-Product' => 'MultiPanel ERP',
                ],
            ]);
            $headers = [];
            if ($token !== '') {
                $headers['X-Plex-Token'] = $token;
            }
            $response = $http->get($url, ['headers' => $headers]);
            return $this->artworkFromResponse(
                $response->getStatusCode(),
                $response->getBody()->getContents(),
                $response->getHeaderLine('Content-Type'),
                'guzzle:' . $url,
                $errors
            );
        } catch (GuzzleException $e) {
            $errors[] = "guzzle {$url} → " . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<int, string> $errors
     * @return array{body: string, content_type: string}|null
     */
    private function artworkFromResponse(int $code, string $body, string $contentType, string $label, array &$errors): ?array
    {
        if ($code >= 200 && $code < 300 && $body !== '' && $this->looksLikeImage($contentType, $body)) {
            $this->lastArtworkError = null;
            $ct = trim(explode(';', $contentType)[0]);
            if ($ct === '' || !str_starts_with(strtolower($ct), 'image/')) {
                $ct = $this->guessImageContentType($body);
            }

            return [
                'body' => $body,
                'content_type' => $ct,
            ];
        }

        $errors[] = "{$label} → HTTP {$code}" . ($contentType !== '' ? " ({$contentType})" : '')
            . ($body !== '' ? ' bytes=' . strlen($body) : '');
        return null;
    }

    /** @return array<int, string> bases http(s)://host:port a probar, sin barra final */
    private function artworkBaseUrls(): array
    {
        $bases = [];
        $add = static function (string $base) use (&$bases): void {
            $base = rtrim($base, '/');
            if ($base !== '' && !in_array($base, $bases, true)) {
                $bases[] = $base;
            }
        };

        // 1) Endpoint ya resuelto (el del client Guzzle principal)
        $add($this->baseUrl);

        // 2) URL configurada en BD (como public_ip del panel antiguo)
        $add($this->server->fullUrl());

        // 3) Sin puerto explícito en 80/443 por si el reverse-proxy lo espera así
        $configured = ServerEndpoint::normalize(
            (string) $this->server->url,
            (int) ($this->server->port ?: 32400),
            (bool) $this->server->ssl
        );
        if ($configured['ssl'] && (int) $configured['port'] === 443) {
            $add('https://' . $configured['url']);
        }
        if (!$configured['ssl'] && (int) $configured['port'] === 80) {
            $add('http://' . $configured['url']);
        }

        return $bases;
    }

    private function looksLikeImage(string $contentType, string $body): bool
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));
        if (str_starts_with($contentType, 'image/')) {
            return true;
        }

        if ($body === '') {
            return false;
        }

        // Rechazar XML/HTML de error aunque venga con 200.
        $trim = ltrim($body);
        if ($trim !== '' && ($trim[0] === '<' || str_starts_with($trim, '{'))) {
            return false;
        }

        $head = substr($body, 0, 12);
        if (str_starts_with($head, "\xFF\xD8\xFF") // jpeg
            || str_starts_with($head, "\x89PNG") // png
            || str_starts_with($head, 'GIF8')
            || str_starts_with($head, 'RIFF') // webp
            || str_starts_with($head, "\x00\x00\x00")) { // heic/isom-ish
            return true;
        }

        // Algunos PMS mandan application/octet-stream sin magic bytes claros.
        if (($contentType === 'application/octet-stream' || $contentType === 'binary/octet-stream' || $contentType === '')
            && strlen($body) > 256) {
            return true;
        }

        return false;
    }

    private function guessImageContentType(string $body): string
    {
        $head = substr($body, 0, 12);
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF')) {
            return 'image/webp';
        }

        return 'image/jpeg';
    }

    public function getLastArtworkError(): ?string
    {
        return $this->lastArtworkError;
    }

    public function terminateSession(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        try {
            $this->client->get('/status/sessions/terminate', [
                'headers' => $this->authHeaders(),
                'query' => [
                    'sessionId' => $sessionId,
                    'reason' => 'Acceso suspendido desde MultiPanel',
                ],
            ]);

            return true;
        } catch (GuzzleException $e) {
            Logger::error('Plex terminate session failed', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Detiene todas las reproducciones activas cuyo usuario coincida con alguno
     * de los nombres/emails dados (comparación case-insensitive).
     *
     * @return int Número de sesiones terminadas
     */
    public function terminateSessionsForUser(string ...$names): int
    {
        $needles = [];
        foreach ($names as $name) {
            $normalized = mb_strtolower(trim($name));
            if ($normalized !== '') {
                $needles[$normalized] = true;
            }
        }
        if ($needles === []) {
            return 0;
        }

        // Aunque el sondeo inicial haya fallado, intentamos listar sesiones
        // contra la URL configurada para poder cortar streams al suspender.
        $savedError = $this->lastError;
        $this->lastError = null;
        $sessions = $this->getActiveSessions();
        if ($this->lastError !== null && $sessions === []) {
            $this->lastError = $savedError;
        }

        $killed = 0;
        foreach ($sessions as $session) {
            $sessionUser = mb_strtolower(trim((string) ($session['user'] ?? '')));
            if ($sessionUser === '' || !isset($needles[$sessionUser])) {
                continue;
            }
            $sessionId = (string) ($session['session_id'] ?? '');
            if ($sessionId !== '' && $this->terminateSession($sessionId)) {
                $killed++;
            }
        }

        return $killed;
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

    /** @param array<int, int|string> $librarySectionIds Plex library section keys */
    public function inviteUserByEmail(string $email, array $librarySectionIds): bool
    {
        $machineId = trim((string) ($this->server->machine_id ?? ''));
        $token = trim((string) ($this->server->token ?? ''));

        if ($machineId === '' || $token === '' || $email === '') {
            Logger::error('Plex invite missing machine_id, token or email', [
                'server_id' => $this->server->id,
            ]);
            return false;
        }

        $sectionIds = array_values(array_filter(array_map('intval', $librarySectionIds)));
        if ($sectionIds === []) {
            Logger::error('Plex invite missing library sections', ['server_id' => $this->server->id]);
            return false;
        }

        try {
            $client = new Client([
                'base_uri' => 'https://plex.tv',
                'timeout' => 30,
                'verify' => true,
                'http_errors' => false,
            ]);

            // Mismo endpoint que SERVEROLD/inviteUser: POST /api/v2/shared_servers
            $response = $client->post('/api/v2/shared_servers', [
                'headers' => array_merge($this->authHeaders(), [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Plex-Client-Identifier' => 'multipanel-erp',
                ]),
                'json' => [
                    'machineIdentifier' => $machineId,
                    'invitedEmail' => $email,
                    'librarySectionIDs' => $sectionIds,
                    'settings' => [
                        'allowSync' => false,
                        'allowCameraUpload' => false,
                        'allowChannels' => false,
                    ],
                ],
            ]);

            $code = $response->getStatusCode();
            // 200/201 = enviado; 422 = ya invitado/compartido → también OK para restaurar
            if (($code >= 200 && $code < 300) || $code === 422) {
                return true;
            }

            Logger::error('Plex invite user HTTP error', [
                'server_id' => $this->server->id,
                'email' => $email,
                'http' => $code,
                'body' => substr($response->getBody()->getContents(), 0, 300),
            ]);
            return false;
        } catch (GuzzleException $e) {
            Logger::error('Plex invite user failed', [
                'server_id' => $this->server->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Lista los "friends" con acceso compartido a este servidor (shared_servers de Plex.tv).
     * Igual que SERVEROLD: GET /api/servers/{machineId}/shared_servers (XML).
     *
     * @return array<int, array{id: int, user_id: string, username: string, email: string, library_section_ids: array<int, int>}>
     */
    public function getSharedServers(): array
    {
        $result = $this->fetchSharedServers();

        return $result['ok'] ? $result['shares'] : [];
    }

    /**
     * Igual que getSharedServers pero distingue fallo de red/API de lista vacía.
     * Sin esto, un error de plex.tv se interpretaba como "ya no hay shares" y
     * el panel daba por cortado un acceso que seguía activo.
     *
     * @return array{ok: bool, shares: array<int, array{id: int, user_id: string, username: string, email: string, library_section_ids: array<int, int>}>, error: ?string}
     */
    public function fetchSharedServers(): array
    {
        $machineId = trim((string) ($this->server->machine_id ?? ''));
        $token = trim((string) ($this->server->token ?? ''));

        if ($machineId === '') {
            return ['ok' => false, 'shares' => [], 'error' => 'El servidor no tiene machine_id configurado.'];
        }
        if ($token === '') {
            return ['ok' => false, 'shares' => [], 'error' => 'El servidor no tiene token Plex configurado.'];
        }

        try {
            $client = new Client([
                'base_uri' => 'https://plex.tv',
                'timeout' => 20,
                'verify' => false,
                'http_errors' => false,
            ]);

            $response = $client->get("/api/servers/{$machineId}/shared_servers", [
                'headers' => [
                    'Accept' => 'application/xml',
                    'X-Plex-Token' => $token,
                    'X-Plex-Client-Identifier' => 'multipanel-erp',
                    'X-Plex-Product' => 'MultiPanel ERP',
                ],
                'query' => ['X-Plex-Token' => $token],
            ]);

            $code = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            if ($code < 200 || $code >= 300) {
                Logger::error('Plex get shared_servers HTTP error', [
                    'server_id' => $this->server->id,
                    'machine_id' => $machineId,
                    'http' => $code,
                    'body_preview' => substr($body, 0, 200),
                ]);

                return ['ok' => false, 'shares' => [], 'error' => "plex.tv shared_servers HTTP {$code}"];
            }

            $xml = simplexml_load_string($body);
            if ($xml === false) {
                Logger::warning('Plex shared_servers XML parse failed', [
                    'server_id' => $this->server->id,
                    'body_preview' => substr($body, 0, 200),
                ]);

                return ['ok' => false, 'shares' => [], 'error' => 'No se pudo parsear la respuesta XML de shared_servers.'];
            }

            $shares = [];
            foreach ($xml->SharedServer ?? [] as $shared) {
                $sectionIds = [];
                foreach ($shared->Section ?? [] as $section) {
                    $sectionIds[] = (int) ($section['id'] ?? $section['key'] ?? 0);
                }
                $sectionIds = array_values(array_filter($sectionIds, static fn (int $id): bool => $id > 0));

                $shares[] = [
                    'id' => (int) $shared['id'],
                    'user_id' => (string) ($shared['userID'] ?? $shared['invitedId'] ?? ''),
                    'username' => (string) ($shared['username'] ?? ''),
                    'email' => (string) ($shared['email'] ?? ''),
                    'library_section_ids' => $sectionIds,
                ];
            }

            return ['ok' => true, 'shares' => $shares, 'error' => null];
        } catch (GuzzleException $e) {
            Logger::error('Plex get shared_servers failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'shares' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{id: int, user_id: string, username: string, email: string, library_section_ids: array<int, int>}|null
     */
    public function findSharedServerFor(?string $email, ?string $username = null, ?string $userId = null): ?array
    {
        $email = trim((string) $email);
        $username = trim((string) $username);
        $userId = trim((string) $userId);
        if ($email === '' && $username === '' && $userId === '') {
            return null;
        }

        $result = $this->fetchSharedServers();
        if (!$result['ok']) {
            return null;
        }

        foreach ($result['shares'] as $share) {
            if ($email !== '' && strcasecmp($share['email'], $email) === 0) {
                return $share;
            }
            if ($username !== '' && strcasecmp($share['username'], $username) === 0) {
                return $share;
            }
            if ($userId !== '' && (string) $share['user_id'] === $userId) {
                return $share;
            }
            // A veces external_id guarda el id del SharedServer, no el userID.
            if ($userId !== '' && (string) $share['id'] === $userId) {
                return $share;
            }
        }

        return null;
    }

    /** @param array<int, int> $sectionIds */
    public function updateSharedServerLibraries(int $sharedServerId, array $sectionIds): bool
    {
        $machineId = trim((string) ($this->server->machine_id ?? ''));
        $token = trim((string) ($this->server->token ?? ''));

        if ($machineId === '' || $token === '' || $sharedServerId <= 0) {
            return false;
        }

        $ids = array_values(array_map('intval', $sectionIds));

        try {
            $client = new Client([
                'base_uri' => 'https://plex.tv',
                'timeout' => 20,
                'verify' => false,
                'http_errors' => false,
            ]);

            $path = "/api/servers/{$machineId}/shared_servers/{$sharedServerId}";
            $headers = [
                'Accept' => 'application/json, application/xml',
                'X-Plex-Token' => $token,
                'X-Plex-Client-Identifier' => 'multipanel-erp',
                'X-Plex-Product' => 'MultiPanel ERP',
            ];

            // 1) JSON (API moderna)
            $response = $client->put($path, [
                'headers' => array_merge($headers, ['Content-Type' => 'application/json']),
                'query' => ['X-Plex-Token' => $token],
                'json' => [
                    'server_id' => $machineId,
                    'shared_server' => [
                        'library_section_ids' => $ids,
                    ],
                ],
            ]);
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return true;
            }

            // 2) form-urlencoded (lo que usan muchos paneles antiguos / Ombi).
            // Guzzle no admite bien claves repetidas en arrays asociativos.
            $parts = [];
            if ($ids === []) {
                $parts[] = 'shared_server%5Blibrary_section_ids%5D%5B%5D=';
            } else {
                foreach ($ids as $id) {
                    $parts[] = 'shared_server%5Blibrary_section_ids%5D%5B%5D=' . (int) $id;
                }
            }
            $response = $client->put($path, [
                'headers' => array_merge($headers, [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ]),
                'query' => ['X-Plex-Token' => $token],
                'body' => implode('&', $parts),
            ]);

            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                return true;
            }

            Logger::error('Plex update shared_server libraries HTTP error', [
                'server_id' => $this->server->id,
                'shared_server_id' => $sharedServerId,
                'http' => $code,
                'body' => substr($response->getBody()->getContents(), 0, 300),
            ]);
            return false;
        } catch (GuzzleException $e) {
            Logger::error('Plex update shared_server libraries failed', [
                'server_id' => $this->server->id,
                'shared_server_id' => $sharedServerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Revoca el acceso como hacía SERVEROLD: DELETE del shared_server en plex.tv.
     * Eso corta bibliotecas y suele forzar la desconexión del cliente.
     *
     * Ojo: un HTTP 404 por sí solo NO se considera éxito (podría ser un id
     * incorrecto). Usa revokeFriendAccess() que verifica después.
     */
    public function removeSharedServer(int $sharedServerId): bool
    {
        $result = $this->plexTvDelete(
            "/api/servers/{machine}/shared_servers/{$sharedServerId}",
            $sharedServerId
        );

        return $result['ok'];
    }

    /**
     * Revoca el acceso al estilo SERVEROLD/removeUserMultipleAttempts:
     * prueba varios endpoints de plex.tv y SOLO considera éxito si una
     * reconsulta EXITOSA confirma que el share ya no existe (o sin libs).
     *
     * @return array{ok: bool, method: ?string, attempts: array<int, array<string, mixed>>, verified: bool, error?: string}
     */
    public function revokeFriendAccess(?string $email, ?string $username = null, ?string $externalId = null): array
    {
        $lookup = $this->fetchSharedServers();
        if (!$lookup['ok']) {
            return [
                'ok' => false,
                'method' => null,
                'attempts' => [['type' => 'lookup', 'ok' => false, 'detail' => $lookup['error']]],
                'verified' => false,
                'error' => (string) $lookup['error'],
            ];
        }

        $share = $this->matchShareInList($lookup['shares'], $email, $username, $externalId);
        $attempts = [];

        if ($share === null) {
            return [
                'ok' => false,
                'method' => null,
                'attempts' => [['type' => 'lookup', 'ok' => false, 'detail' => 'No aparece en shared_servers de plex.tv']],
                'verified' => false,
                'error' => 'No aparece en shared_servers de plex.tv (¿machine_id incorrecto o usuario Home?).',
            ];
        }

        $shareId = (int) $share['id'];
        $userId = trim((string) ($share['user_id'] ?? ''));
        $machineId = trim((string) ($this->server->machine_id ?? ''));

        // Orden inspirado en SERVEROLD: share id, share con userID, friend, invite, home, vaciar libs.
        $endpoints = [
            'share_id' => $shareId > 0 && $machineId !== ''
                ? "/api/servers/{$machineId}/shared_servers/{$shareId}"
                : null,
            'share_userid' => ($userId !== '' && $machineId !== '')
                ? "/api/servers/{$machineId}/shared_servers/{$userId}"
                : null,
            'friend' => $userId !== '' ? "/api/friends/{$userId}" : null,
            'invite' => $userId !== '' ? "/api/invites/invited/{$userId}" : null,
            'home' => $userId !== '' ? "/api/home/users/{$userId}" : null,
        ];

        $methodWorked = null;
        foreach ($endpoints as $type => $path) {
            if ($path === null) {
                continue;
            }
            $result = $this->plexTvDelete($path, $shareId);
            $attempts[] = [
                'type' => $type,
                'path' => $path,
                'http' => $result['http'],
                'ok' => $result['ok'],
                'body' => $result['body'],
            ];
            if ($result['ok'] || $result['http'] === 404) {
                $methodWorked = $type;
                // Como SERVEROLD: pequeña espera y reconsulta.
                usleep(800000);
                $check = $this->accessStatusAfterRevoke($email, $username, $externalId, $shareId);
                $attempts[] = ['type' => 'verify_after_' . $type, 'ok' => $check['ok'], 'status' => $check['status'], 'detail' => $check['error']];
                if ($check['status'] === 'cut') {
                    return [
                        'ok' => true,
                        'method' => $type,
                        'attempts' => $attempts,
                        'verified' => true,
                    ];
                }
                // Si la verificación falló (API error), no afirmamos éxito.
                if ($check['status'] === 'unknown') {
                    return [
                        'ok' => false,
                        'method' => $type,
                        'attempts' => $attempts,
                        'verified' => false,
                        'error' => 'DELETE respondió pero no se pudo verificar en plex.tv: ' . (string) $check['error'],
                    ];
                }
            }
        }

        // Último recurso: vaciar bibliotecas (PUT).
        if ($shareId > 0) {
            $zeroed = $this->updateSharedServerLibraries($shareId, []);
            $attempts[] = [
                'type' => 'zero_libraries',
                'ok' => $zeroed,
                'http' => $zeroed ? 200 : 0,
            ];
            if ($zeroed) {
                usleep(800000);
                $check = $this->accessStatusAfterRevoke($email, $username, $externalId, $shareId);
                $attempts[] = ['type' => 'verify_after_zero', 'ok' => $check['ok'], 'status' => $check['status'], 'detail' => $check['error']];
                if ($check['status'] === 'cut') {
                    return [
                        'ok' => true,
                        'method' => 'zero_libraries',
                        'attempts' => $attempts,
                        'verified' => true,
                    ];
                }
            }
        }

        Logger::warning('Plex revokeFriendAccess did not verify cut', [
            'server_id' => $this->server->id,
            'email' => $email,
            'username' => $username,
            'share_id' => $shareId,
            'user_id' => $userId,
            'method_http_ok' => $methodWorked,
            'attempts' => $attempts,
        ]);

        return [
            'ok' => false,
            'method' => $methodWorked,
            'attempts' => $attempts,
            'verified' => false,
            'error' => 'Plex.tv no confirmó el corte tras varios intentos.',
        ];
    }

    /**
     * @param array<int, array{id: int, user_id: string, username: string, email: string, library_section_ids: array<int, int>}> $shares
     * @return array{id: int, user_id: string, username: string, email: string, library_section_ids: array<int, int>}|null
     */
    private function matchShareInList(array $shares, ?string $email, ?string $username, ?string $externalId): ?array
    {
        $email = trim((string) $email);
        $username = trim((string) $username);
        $externalId = trim((string) $externalId);

        foreach ($shares as $share) {
            if ($email !== '' && strcasecmp((string) $share['email'], $email) === 0) {
                return $share;
            }
            if ($username !== '' && strcasecmp((string) $share['username'], $username) === 0) {
                return $share;
            }
            if ($externalId !== '' && (
                (string) $share['user_id'] === $externalId || (string) $share['id'] === $externalId
            )) {
                return $share;
            }
        }

        return null;
    }

    /**
     * Reconsulta plex.tv tras un intento de corte.
     * - cut: share ausente O presente sin bibliotecas (solo tras PUT vacío verificado)
     * - active: sigue con bibliotecas
     * - unknown: no pudimos consultar (NO equivale a cortado)
     *
     * @return array{ok: bool, status: 'cut'|'active'|'unknown', error: ?string}
     */
    public function accessStatusAfterRevoke(
        ?string $email,
        ?string $username = null,
        ?string $externalId = null,
        ?int $shareId = null
    ): array {
        $result = $this->fetchSharedServers();
        if (!$result['ok']) {
            return ['ok' => false, 'status' => 'unknown', 'error' => $result['error']];
        }

        $match = null;
        foreach ($result['shares'] as $share) {
            $isMatch = false;
            if ($shareId !== null && (int) $share['id'] === $shareId) {
                $isMatch = true;
            }
            $email = trim((string) $email);
            $username = trim((string) $username);
            $externalId = trim((string) $externalId);
            if ($email !== '' && strcasecmp((string) $share['email'], $email) === 0) {
                $isMatch = true;
            }
            if ($username !== '' && strcasecmp((string) $share['username'], $username) === 0) {
                $isMatch = true;
            }
            if ($externalId !== '' && (
                (string) $share['user_id'] === $externalId || (string) $share['id'] === $externalId
            )) {
                $isMatch = true;
            }
            if ($isMatch) {
                $match = $share;
                break;
            }
        }

        if ($match === null) {
            return ['ok' => true, 'status' => 'cut', 'error' => null];
        }

        // Si el share sigue listado, solo contamos "cut" si ya no tiene secciones.
        // (Una lista de secciones vacía real = bibliotecas quitadas.)
        if (($match['library_section_ids'] ?? []) === []) {
            return ['ok' => true, 'status' => 'cut', 'error' => null];
        }

        return ['ok' => true, 'status' => 'active', 'error' => null];
    }

    /**
     * @deprecated Usa accessStatusAfterRevoke(); se mantiene por compatibilidad.
     */
    public function stillHasLibraryAccess(
        ?string $email,
        ?string $username = null,
        ?string $externalId = null,
        ?int $shareId = null
    ): bool {
        $status = $this->accessStatusAfterRevoke($email, $username, $externalId, $shareId);

        // Ante duda (unknown), asumimos que SIGUE con acceso: nunca un falso "cortado".
        return $status['status'] !== 'cut';
    }

    /**
     * @return array{ok: bool, http: int, body: string}
     */
    private function plexTvDelete(string $path, int $contextId = 0): array
    {
        $machineId = trim((string) ($this->server->machine_id ?? ''));
        $token = trim((string) ($this->server->token ?? ''));

        if ($token === '') {
            return ['ok' => false, 'http' => 0, 'body' => 'Sin token'];
        }

        $path = str_replace('{machine}', $machineId, $path);
        if (str_contains($path, '//') || str_contains($path, '/shared_servers/0') || str_ends_with($path, '/shared_servers/')) {
            return ['ok' => false, 'http' => 0, 'body' => 'Path inválido'];
        }

        try {
            $client = new Client([
                'base_uri' => 'https://plex.tv',
                'timeout' => 20,
                'verify' => false,
                'http_errors' => false,
            ]);

            $response = $client->delete($path, [
                'headers' => [
                    'Accept' => 'application/json, application/xml',
                    'X-Plex-Token' => $token,
                    'X-Plex-Product' => 'MultiPanel ERP',
                    'X-Plex-Client-Identifier' => 'multipanel-erp',
                ],
                'query' => ['X-Plex-Token' => $token],
            ]);

            $code = (int) $response->getStatusCode();
            $body = substr($response->getBody()->getContents(), 0, 300);
            // Solo 2xx es éxito real. El 404 se verifica aparte (id incorrecto ≠ cortado).
            $ok = $code >= 200 && $code < 300;

            if (!$ok) {
                Logger::debug('Plex.tv DELETE attempt', [
                    'server_id' => $this->server->id,
                    'path' => $path,
                    'context_id' => $contextId,
                    'http' => $code,
                    'body' => $body,
                ]);
            }

            return ['ok' => $ok, 'http' => $code, 'body' => $body];
        } catch (GuzzleException $e) {
            return ['ok' => false, 'http' => 0, 'body' => $e->getMessage()];
        }
    }

    /** @return array<int, int> IDs de todas las secciones/bibliotecas del servidor */
    public function allLibrarySectionIds(): array
    {
        return array_values(array_map(
            static fn (array $lib): int => (int) $lib['external_id'],
            $this->getLibraries()
        ));
    }

    public function testConnection(): bool
    {
        if ($this->lastError !== null) {
            return false;
        }

        $this->lastError = null;
        $ok = $this->getServerInfo() !== null;
        if (!$ok && $this->lastError === null) {
            $this->lastError = 'El servidor Plex no respondió. Comprueba URL pública, puerto y token.';
        }
        return $ok;
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
