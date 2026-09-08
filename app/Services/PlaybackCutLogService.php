<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;
use Core\Updater;

/**
 * Registro de cortes: qué se cortó, mensaje al cliente y por qué.
 */
final class PlaybackCutLogService
{
    public const KIND_VIDEO_TRANSCODE = 'video_transcode';

    public const KIND_STREAM_HOME = 'stream_home';

    public const KIND_STREAM_AWAY = 'stream_away';

    public const KIND_STREAM_GENERIC = 'stream_generic';

    /**
     * @param array<string, mixed> $detail
     */
    public function log(
        int $tenantId,
        string $kind,
        string $cutWhy,
        ?string $clientMessage = null,
        ?string $username = null,
        ?string $title = null,
        ?string $sessionId = null,
        ?int $mediaUserId = null,
        ?int $serverId = null,
        bool $killed = true,
        array $detail = [],
    ): void {
        if ($tenantId <= 0 || trim($kind) === '' || trim($cutWhy) === '') {
            return;
        }

        try {
            $this->ensureTable();
            Database::getInstance()->insert('playback_cut_logs', [
                'tenant_id' => $tenantId,
                'media_user_id' => ($mediaUserId !== null && $mediaUserId > 0) ? $mediaUserId : null,
                'server_id' => ($serverId !== null && $serverId > 0) ? $serverId : null,
                'username' => $username !== null && $username !== '' ? mb_substr($username, 0, 255) : null,
                'kind' => mb_substr(trim($kind), 0, 40),
                'title' => $title !== null && $title !== '' ? mb_substr($title, 0, 500) : null,
                'session_id' => $sessionId !== null && $sessionId !== '' ? mb_substr($sessionId, 0, 128) : null,
                'client_message' => $clientMessage !== null && $clientMessage !== ''
                    ? mb_substr($clientMessage, 0, 500)
                    : null,
                'cut_why' => mb_substr(trim($cutWhy), 0, 500),
                'detail_json' => $detail !== []
                    ? json_encode($detail, JSON_UNESCAPED_UNICODE)
                    : null,
                'killed' => $killed ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Could not log playback cut', [
                'tenant_id' => $tenantId,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForTenant(int $tenantId, int $limit = 100, ?string $kind = null): array
    {
        $this->ensureTable();
        $limit = max(1, min(500, $limit));
        $kind = $kind !== null ? trim($kind) : '';

        try {
            if ($kind !== '') {
                return Database::getInstance()->fetchAll(
                    'SELECT l.*, s.name AS server_name, mu.uuid AS media_user_uuid
                     FROM playback_cut_logs l
                     LEFT JOIN servers s ON s.id = l.server_id
                     LEFT JOIN media_users mu ON mu.id = l.media_user_id
                     WHERE l.tenant_id = ? AND l.kind = ?
                     ORDER BY l.id DESC
                     LIMIT ' . $limit,
                    [$tenantId, $kind]
                );
            }

            return Database::getInstance()->fetchAll(
                'SELECT l.*, s.name AS server_name, mu.uuid AS media_user_uuid
                 FROM playback_cut_logs l
                 LEFT JOIN servers s ON s.id = l.server_id
                 LEFT JOIN media_users mu ON mu.id = l.media_user_id
                 WHERE l.tenant_id = ?
                 ORDER BY l.id DESC
                 LIMIT ' . $limit,
                [$tenantId]
            );
        } catch (\Throwable $e) {
            Logger::warning('Could not list playback cut logs', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_VIDEO_TRANSCODE => 'Transcode (calidad)',
            self::KIND_STREAM_HOME => 'Límite en casa',
            self::KIND_STREAM_AWAY => 'Fuera de casa',
            self::KIND_STREAM_GENERIC => 'Límite streams',
            default => $kind,
        };
    }

    private function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT 1 AS ok FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                ['playback_cut_logs']
            );
            if ($row !== null) {
                $done = true;

                return;
            }
            (new Updater())->runMigrations();
        } catch (\Throwable) {
            // ignore
        }

        $done = true;
    }
}
