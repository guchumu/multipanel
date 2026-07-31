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

    private ?string $lastError = null;

    public function __construct(
        private Server $server,
    ) {
        $resolver = new PlexConnectionResolver();
        $resolved = $resolver->resolve($server);
        $endpoint = $resolved['endpoint'];

        if ($resolved['error'] !== null) {
            $this->lastError = $resolved['error'];
        } elseif ($this->shouldPersistEndpoint($endpoint)) {
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

        $scheme = $endpoint['ssl'] ? 'https' : 'http';
        $this->client = new Client([
            'base_uri' => "{$scheme}://{$endpoint['url']}:{$endpoint['port']}",
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
                'headers' => $this->authHeaders(),
            ]);

            $xml = simplexml_load_string($response->getBody()->getContents());
            if ($xml === false) {
                return [];
            }

            $sessions = [];
            foreach (['Video', 'Track', 'Photo'] as $tag) {
                foreach ($xml->{$tag} ?? [] as $session) {
                    $sessions[] = $this->parsePlexSession($session);
                }
            }

            return $sessions;
        } catch (GuzzleException $e) {
            Logger::error('Plex get sessions failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
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

        $thumb = (string) ($session['thumb'] ?? $session['art'] ?? '');

        return [
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
            'thumb_url' => $this->mediaUrl($thumb),
        ];
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
