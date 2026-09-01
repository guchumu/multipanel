<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Media\PlaybackSessionKey;
use App\Services\Media\SessionClientIp;
use Core\Database;
use Core\Logger;

/**
 * Historial de reproducciones por usuario (desde En directo / cron streams).
 */
final class PlaybackHistoryService
{
    /**
     * @param array<int, array<string, mixed>> $sessions
     */
    public function recordFromActivitySnapshot(int $tenantId, array $sessions): void
    {
        if ($sessions === []) {
            return;
        }

        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        /** @var array<int, list<string>> $activeKeysByServer */
        $activeKeysByServer = [];

        foreach ($sessions as $session) {
            $serverId = (int) ($session['server_id'] ?? 0);
            if ($serverId <= 0) {
                continue;
            }

            $key = PlaybackSessionKey::forSession($session, $serverId);
            $activeKeysByServer[$serverId][] = $key;

            $mediaUserId = (int) ($session['media_user_id'] ?? 0);
            if ($mediaUserId <= 0) {
                continue;
            }

            $title = trim((string) ($session['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $ip = SessionClientIp::normalize((string) ($session['client_ip'] ?? $session['public_ip'] ?? ''));
            $payload = [
                'title' => mb_substr($title, 0, 500),
                'subtitle' => $this->subtitleForSession($session),
                'media_type' => $this->nullableString($session['media_type'] ?? null, 50),
                'player' => $this->nullableString($session['player'] ?? null, 100),
                'device' => $this->nullableString($session['platform'] ?? $session['product'] ?? null, 255),
                'quality' => $this->nullableString($session['play_method'] ?? null, 20),
                'media_user_id' => $mediaUserId,
                'external_session_id' => $key,
            ];
            if ($ip !== '') {
                $payload['ip_address'] = $ip;
            }

            try {
                $existing = $this->findOpenSession($db, $serverId, $session, $key);
                if ($existing) {
                    $db->update('playback_sessions', $payload, 'id = ?', [(int) $existing['id']]);
                    continue;
                }

                $db->insert('playback_sessions', array_merge($payload, [
                    'tenant_id' => $tenantId,
                    'server_id' => $serverId,
                    'started_at' => $now,
                ]));
            } catch (\Throwable $e) {
                Logger::debug('Playback history record failed', ['error' => $e->getMessage()]);
            }
        }

        foreach ($activeKeysByServer as $serverId => $keys) {
            $this->closeEndedSessions((int) $serverId, $keys, $now);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $mediaUserId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        try {
            return Database::getInstance()->fetchAll(
                "SELECT ps.*, s.name AS server_name
                 FROM playback_sessions ps
                 LEFT JOIN servers s ON s.id = ps.server_id
                 WHERE ps.media_user_id = ?
                 ORDER BY COALESCE(ps.ended_at, ps.started_at) DESC, ps.id DESC
                 LIMIT {$limit} OFFSET {$offset}",
                [$mediaUserId]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function countForUser(int $mediaUserId): int
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS total FROM playback_sessions WHERE media_user_id = ?',
                [$mediaUserId]
            );

            return (int) ($row['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $session
     * @return array{id: int}|null
     */
    private function findOpenSession(Database $db, int $serverId, array $session, string $canonicalKey): ?array
    {
        foreach (PlaybackSessionKey::lookupKeys($session, $serverId) as $key) {
            $row = $db->fetchOne(
                'SELECT id FROM playback_sessions
                 WHERE server_id = ? AND external_session_id = ? AND ended_at IS NULL
                 LIMIT 1',
                [$serverId, $key]
            );
            if ($row !== null) {
                return ['id' => (int) $row['id']];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function subtitleForSession(array $session): ?string
    {
        $subtitle = trim((string) ($session['subtitle'] ?? ''));
        if ($subtitle !== '') {
            return mb_substr($subtitle, 0, 500);
        }
        $year = trim((string) ($session['year'] ?? ''));

        return $year !== '' ? mb_substr($year, 0, 500) : null;
    }

    /** @param list<string> $activeKeys */
    private function closeEndedSessions(int $serverId, array $activeKeys, string $now): void
    {
        if ($activeKeys === []) {
            try {
                Database::getInstance()->query(
                    'UPDATE playback_sessions SET ended_at = ?, duration_seconds = TIMESTAMPDIFF(SECOND, started_at, ?)
                     WHERE server_id = ? AND ended_at IS NULL',
                    [$now, $now, $serverId]
                );
            } catch (\Throwable) {
            }

            return;
        }

        $placeholders = implode(',', array_fill(0, count($activeKeys), '?'));
        $params = array_merge([$now, $now, $serverId], $activeKeys);

        try {
            Database::getInstance()->query(
                "UPDATE playback_sessions SET ended_at = ?, duration_seconds = TIMESTAMPDIFF(SECOND, started_at, ?)
                 WHERE server_id = ? AND ended_at IS NULL AND external_session_id NOT IN ({$placeholders})",
                $params
            );
        } catch (\Throwable) {
        }
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? mb_substr($text, 0, $max) : null;
    }
}
