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
            'connect_timeout' => 10,
            'verify' => false,
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

            $sessions = [];
            foreach ($data as $session) {
                $item = $session['NowPlayingItem'] ?? null;
                if (!is_array($item) || ($item['Name'] ?? '') === '') {
                    continue;
                }

                $playState = $session['PlayState'] ?? [];
                $transcoding = $session['TranscodingInfo'] ?? null;
                $playMethodRaw = (string) ($playState['PlayMethod'] ?? '');
                $playMethod = match (strtolower($playMethodRaw)) {
                    'directplay', 'direct play' => 'direct_play',
                    'directstream', 'direct stream' => 'direct_stream',
                    'transcode' => 'transcode',
                    default => $transcoding ? 'transcode' : 'direct_play',
                };

                $position = (int) ($playState['PositionTicks'] ?? 0);
                $runtime = (int) ($item['RunTimeTicks'] ?? 0);
                $progress = $runtime > 0 ? min(100, (int) round(($position / $runtime) * 100)) : 0;

                $series = (string) ($item['SeriesName'] ?? '');
                $season = $item['ParentIndexNumber'] ?? null;
                $episode = $item['IndexNumber'] ?? null;
                $name = (string) ($item['Name'] ?? '');

                if ($series !== '' && $episode !== null) {
                    $displayTitle = $series . ' · S' . ($season ?? '?') . 'E' . $episode;
                    $subtitle = $name;
                } else {
                    $displayTitle = $name;
                    $subtitle = null;
                }

                $mediaStreams = is_array($item['MediaStreams'] ?? null) ? $item['MediaStreams'] : [];
                $transcodingArr = is_array($transcoding) ? $transcoding : null;
                $streamInfo = SessionStreamInfo::fromJellyfin(
                    $playMethod,
                    $transcodingArr,
                    is_array($playState) ? $playState : [],
                    $mediaStreams,
                    $item,
                );

                // Decisiones resumen (compat UI antigua): copy vs codec destino.
                $videoDecision = $playMethod === 'direct_play'
                    ? 'copy'
                    : (string) ($transcodingArr['VideoCodec'] ?? (!empty($transcodingArr['IsVideoDirect']) ? 'copy' : 'transcode'));
                $audioDecision = $playMethod === 'direct_play'
                    ? 'copy'
                    : (string) ($transcodingArr['AudioCodec'] ?? (!empty($transcodingArr['IsAudioDirect']) ? 'copy' : 'transcode'));
                if (!empty($transcodingArr['IsVideoDirect'])) {
                    $videoDecision = 'copy';
                }
                if (!empty($transcodingArr['IsAudioDirect'])) {
                    $audioDecision = 'copy';
                }

                $sessions[] = [
                    'session_id' => (string) ($session['Id'] ?? ''),
                    'title' => $displayTitle,
                    'subtitle' => $subtitle,
                    'user' => (string) ($session['UserName'] ?? ''),
                    'player' => (string) ($session['Client'] ?? $session['DeviceName'] ?? ''),
                    'platform' => (string) ($session['DeviceName'] ?? ''),
                    'state' => !empty($playState['IsPaused']) ? 'paused' : 'playing',
                    'media_type' => (string) ($item['Type'] ?? 'video'),
                    'year' => (string) ($item['ProductionYear'] ?? ''),
                    'play_method' => $playMethod,
                    'video_decision' => $videoDecision,
                    'audio_decision' => $audioDecision,
                    'stream_info' => $streamInfo,
                    'progress' => $progress,
                    'item_id' => (string) ($item['Id'] ?? ''),
                    'thumb_url' => $this->itemImageUrl($item),
                ];

            }

            return $sessions;
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin get sessions failed', ['server_id' => $this->server->id, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @param array<string, mixed> $item */
    private function itemImageUrl(array $item): string
    {
        $itemId = $item['Id'] ?? null;
        if ($itemId === null || $itemId === '') {
            return '';
        }

        $base = rtrim($this->server->fullUrl(), '/');
        $tag = isset($item['ImageTags']['Primary']) ? '&tag=' . rawurlencode((string) $item['ImageTags']['Primary']) : '';
        $apiKey = trim((string) ($this->server->api_key ?? ''));

        return "{$base}/Items/{$itemId}/Images/Primary?maxHeight=400{$tag}" . ($apiKey !== '' ? '&api_key=' . rawurlencode($apiKey) : '');
    }

    public function fetchItemImage(string $itemId): ?array
    {
        if ($itemId === '') {
            return null;
        }

        $apiKey = trim((string) ($this->server->api_key ?? ''));
        $query = ['maxHeight' => 600, 'quality' => 90];
        if ($apiKey !== '') {
            $query['api_key'] = $apiKey;
        }

        try {
            $response = $this->client->get("/Items/{$itemId}/Images/Primary", [
                'http_errors' => false,
                'headers' => array_merge($this->authHeaders(), [
                    'Accept' => '*' . '/' . '*',
                ]),
                'query' => $query,
            ]);

            $code = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $contentType = $response->getHeaderLine('Content-Type');

            if ($code < 200 || $code >= 300 || $body === '') {
                Logger::debug('Jellyfin image fetch failed', [
                    'item_id' => $itemId,
                    'http' => $code,
                    'content_type' => $contentType,
                ]);
                return null;
            }

            // Rechazar JSON/HTML de error.
            $trim = ltrim($body);
            if ($trim !== '' && ($trim[0] === '<' || $trim[0] === '{')) {
                return null;
            }

            $ct = trim(explode(';', $contentType)[0]);
            if ($ct === '' || !str_starts_with(strtolower($ct), 'image/')) {
                $ct = 'image/jpeg';
            }

            return [
                'body' => $body,
                'content_type' => $ct,
            ];
        } catch (GuzzleException $e) {
            Logger::debug('Jellyfin image fetch failed', ['item_id' => $itemId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Envía un mensaje en pantalla al cliente Jellyfin (si el player lo soporta).
     */
    public function sendSessionMessage(string $sessionId, string $header, string $text, int $timeoutMs = 8000): bool
    {
        if ($sessionId === '' || trim($text) === '') {
            return false;
        }

        try {
            $this->client->post("/Sessions/{$sessionId}/Message", [
                'headers' => $this->authHeaders(),
                'json' => [
                    'Header' => $header !== '' ? $header : 'MultiPanel',
                    'Text' => $text,
                    'TimeoutMs' => max(1000, $timeoutMs),
                ],
            ]);

            return true;
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin session message failed', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function terminateSession(string $sessionId, ?string $reason = null): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $reason = trim((string) $reason);
        if ($reason !== '') {
            // Best-effort: mostrar aviso antes de cortar (no bloquea el stop).
            $this->sendSessionMessage($sessionId, 'Reproducción detenida', $reason);
        }

        try {
            $response = $this->client->post("/Sessions/{$sessionId}/Playing/Stop", [
                'http_errors' => false,
                'headers' => $this->authHeaders(),
            ]);

            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                return true;
            }

            // 404: sesión ya cerrada. Otros códigos: si ya no está activa, OK.
            if ($code === 404 || !$this->sessionStillActive($sessionId)) {
                return true;
            }

            Logger::error('Jellyfin stop session failed', [
                'session_id' => $sessionId,
                'http' => $code,
                'body' => substr($response->getBody()->getContents(), 0, 300),
            ]);

            return false;
        } catch (GuzzleException $e) {
            // Red intermitente: si la sesión ya no está, el objetivo se cumplió.
            if (!$this->sessionStillActive($sessionId)) {
                return true;
            }
            Logger::error('Jellyfin stop session failed', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function sessionStillActive(string $sessionId): bool
    {
        foreach ($this->getActiveSessions() as $session) {
            if ((string) ($session['session_id'] ?? '') === $sessionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detiene reproducciones activas del usuario (por nombre).
     *
     * @return int Número de sesiones detenidas
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

        $killed = 0;
        foreach ($this->getActiveSessions() as $session) {
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

    /** @return array{external_id: string, username: string}|null */
    public function findUserByName(string $username): ?array
    {
        $needle = mb_strtolower(trim($username));
        if ($needle === '') {
            return null;
        }

        foreach ($this->getUsers() as $user) {
            if (mb_strtolower(trim((string) ($user['username'] ?? ''))) === $needle) {
                $id = (string) ($user['external_id'] ?? '');
                if ($id === '') {
                    return null;
                }

                return [
                    'external_id' => $id,
                    'username' => (string) ($user['username'] ?? $username),
                ];
            }
        }

        return null;
    }

    /**
     * Cambia la contraseña de un usuario (API key de admin; CurrentPw vacío).
     */
    public function updateUserPassword(string $userId, string $newPassword): bool
    {
        $userId = trim($userId);
        $newPassword = trim($newPassword);
        if ($userId === '' || $newPassword === '') {
            return false;
        }

        try {
            $this->client->post("/Users/{$userId}/Password", [
                'headers' => $this->authHeaders(),
                'json' => [
                    'Id' => $userId,
                    'CurrentPw' => '',
                    'NewPw' => $newPassword,
                ],
            ]);

            return true;
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin update password failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
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
        return $this->setUserDisabled($userId, true);
    }

    public function enableUser(string $userId): bool
    {
        return $this->setUserDisabled($userId, false);
    }

    private function setUserDisabled(string $userId, bool $disabled): bool
    {
        try {
            $response = $this->client->get("/Users/{$userId}", [
                'headers' => $this->authHeaders(),
            ]);
            $user = json_decode($response->getBody()->getContents(), true);
            $policy = is_array($user['Policy'] ?? null) ? $user['Policy'] : [];
            $policy['IsDisabled'] = $disabled;

            // Jellyfin expone la política de un usuario en un endpoint dedicado,
            // no en el POST /Users/{id} (que no persiste IsDisabled).
            $this->client->post("/Users/{$userId}/Policy", [
                'headers' => $this->authHeaders(),
                'json' => $policy,
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Jellyfin user disable toggle failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
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
