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
        $thumb = $this->pickArtPath(
            (string) ($session['grandparentThumb'] ?? ''),
            (string) ($session['thumb'] ?? ''),
            (string) ($session['parentThumb'] ?? ''),
            (string) ($session['art'] ?? ''),
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

        $thumb = $this->pickArtPath(
            (string) ($session['grandparentThumb'] ?? ''),
            (string) ($session['thumb'] ?? ''),
            (string) ($session['parentThumb'] ?? ''),
            (string) ($session['art'] ?? ''),
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

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parts = parse_url($path);
            $relative = (string) ($parts['path'] ?? '');
            if ($relative === '') {
                return '';
            }
            if (!empty($parts['query'])) {
                $relative .= '?' . $parts['query'];
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
     * URL absoluta del PMS + X-Plex-Token en la query, Accept */*, follow redirects.
     * No depende del lastError del resolve: aunque el sondeo haya fallado, se
     * intenta igual con la URL configurada (que es lo que usaba el panel viejo).
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

        $token = trim((string) ($this->server->token ?? ''));
        $bases = $this->artworkBaseUrls();
        $errors = [];

        // Cliente dedicado para imágenes: NO hereda Accept: application/xml del
        // client principal (eso hacía que algunos PMS devolvieran XML/error).
        $http = new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'verify' => false,
            'allow_redirects' => true,
            'http_errors' => false,
            'headers' => [
                'Accept' => '*/*',
                'User-Agent' => 'MultiPanel ERP/1.1',
                'X-Plex-Client-Identifier' => 'multipanel-erp',
                'X-Plex-Product' => 'MultiPanel ERP',
            ],
        ]);

        $pathOnly = explode('?', $path, 2)[0];

        foreach ($bases as $base) {
            $candidates = [
                $path,
                // Fallback vía photo transcoder (como hacen muchos clientes Plex).
                '/photo/:/transcode?width=300&height=450&minSize=1&upscale=1&url='
                    . rawurlencode($pathOnly),
                '/photo/:/transcode?width=300&height=450&minSize=1&upscale=1&url='
                    . rawurlencode('http://127.0.0.1:32400' . $pathOnly),
            ];

            foreach ($candidates as $candidate) {
                $sep = str_contains($candidate, '?') ? '&' : '?';
                $url = rtrim($base, '/') . $candidate;
                if ($token !== '' && !str_contains($url, 'X-Plex-Token=')) {
                    $url .= $sep . 'X-Plex-Token=' . rawurlencode($token);
                }

                try {
                    $response = $http->get($url);
                    $code = $response->getStatusCode();
                    $body = $response->getBody()->getContents();
                    $contentType = $response->getHeaderLine('Content-Type');

                    if ($code >= 200 && $code < 300 && $body !== '' && $this->looksLikeImage($contentType, $body)) {
                        $this->lastArtworkError = null;

                        return [
                            'body' => $body,
                            'content_type' => $contentType !== '' ? $contentType : 'image/jpeg',
                        ];
                    }

                    $errors[] = "{$url} → HTTP {$code}" . ($contentType !== '' ? " ({$contentType})" : '');
                } catch (GuzzleException $e) {
                    $errors[] = "{$url} → " . $e->getMessage();
                }
            }
        }

        $this->lastArtworkError = $errors !== [] ? implode(' | ', array_slice($errors, 0, 4)) : 'Sin respuesta';
        Logger::warning('Plex artwork fetch failed', [
            'server_id' => $this->server->id,
            'path' => $path,
            'error' => $this->lastArtworkError,
        ]);

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

        return $bases;
    }

    private function looksLikeImage(string $contentType, string $body): bool
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));
        if (str_starts_with($contentType, 'image/')) {
            return true;
        }

        // Algunos PMS no mandan Content-Type; detectamos magic bytes básicos.
        if ($body === '') {
            return false;
        }

        $head = substr($body, 0, 12);
        return str_starts_with($head, "\xFF\xD8\xFF") // jpeg
            || str_starts_with($head, "\x89PNG") // png
            || str_starts_with($head, 'GIF8')
            || str_starts_with($head, 'RIFF'); // webp
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
        $machineId = trim((string) ($this->server->machine_id ?? ''));
        $token = trim((string) ($this->server->token ?? ''));

        if ($machineId === '' || $token === '') {
            return [];
        }

        try {
            $client = new Client([
                'base_uri' => 'https://plex.tv',
                'timeout' => 20,
                'verify' => true,
            ]);

            $response = $client->get("/api/servers/{$machineId}/shared_servers", [
                'headers' => array_merge($this->authHeaders(), [
                    'Accept' => 'application/xml',
                ]),
            ]);

            $body = $response->getBody()->getContents();
            $xml = simplexml_load_string($body);
            if ($xml === false) {
                Logger::debug('Plex shared_servers XML parse failed', [
                    'server_id' => $this->server->id,
                    'body_preview' => substr($body, 0, 200),
                ]);
                return [];
            }

            $shares = [];
            foreach ($xml->SharedServer ?? [] as $shared) {
                $sectionIds = [];
                foreach ($shared->Section ?? [] as $section) {
                    $sectionIds[] = (int) $section['id'];
                }

                $shares[] = [
                    'id' => (int) $shared['id'],
                    'user_id' => (string) ($shared['userID'] ?? $shared['invitedId'] ?? ''),
                    'username' => (string) ($shared['username'] ?? ''),
                    'email' => (string) ($shared['email'] ?? ''),
                    'library_section_ids' => $sectionIds,
                ];
            }

            return $shares;
        } catch (GuzzleException $e) {
            Logger::error('Plex get shared_servers failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
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

        foreach ($this->getSharedServers() as $share) {
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

        try {
            $client = new Client([
                'base_uri' => 'https://plex.tv',
                'timeout' => 20,
                'verify' => true,
                'http_errors' => false,
            ]);

            $response = $client->put("/api/servers/{$machineId}/shared_servers/{$sharedServerId}", [
                'headers' => array_merge($this->authHeaders(), [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]),
                'json' => [
                    'server_id' => $machineId,
                    'shared_server' => [
                        'library_section_ids' => array_values(array_map('intval', $sectionIds)),
                    ],
                ],
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
     */
    public function removeSharedServer(int $sharedServerId): bool
    {
        $machineId = trim((string) ($this->server->machine_id ?? ''));
        $token = trim((string) ($this->server->token ?? ''));

        if ($machineId === '' || $token === '' || $sharedServerId <= 0) {
            return false;
        }

        try {
            $client = new Client([
                'base_uri' => 'https://plex.tv',
                'timeout' => 20,
                'verify' => true,
                'http_errors' => false,
            ]);

            // Igual que SERVEROLD: token en query + cabecera (algunos entornos
            // de plex.tv son más fiables con el query param).
            $response = $client->delete("/api/servers/{$machineId}/shared_servers/{$sharedServerId}", [
                'headers' => array_merge($this->authHeaders(), [
                    'Accept' => 'application/json',
                    'X-Plex-Product' => 'MultiPanel ERP',
                ]),
                'query' => ['X-Plex-Token' => $token],
            ]);

            $code = $response->getStatusCode();
            // 404 = ya no existe el share → acceso ya cortado
            if (($code >= 200 && $code < 300) || $code === 404) {
                return true;
            }

            Logger::error('Plex remove shared_server HTTP error', [
                'server_id' => $this->server->id,
                'shared_server_id' => $sharedServerId,
                'http' => $code,
                'body' => substr($response->getBody()->getContents(), 0, 300),
            ]);
            return false;
        } catch (GuzzleException $e) {
            Logger::error('Plex remove shared_server failed', [
                'server_id' => $this->server->id,
                'shared_server_id' => $sharedServerId,
                'error' => $e->getMessage(),
            ]);
            return false;
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
