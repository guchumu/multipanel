<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use Core\Database;
use Core\Logger;

/**
 * Detecta usuarios probablemente IPTV mezclados en servidores Plex
 * (típico tras importar plex_manager) y permite soft-delete o detach seguro.
 *
 * Heurística (documentada en docs/IPTV_CLEANUP.md):
 * - Servidor tipo Plex (o sin servidor pero metadata de import).
 * - Señales: email_type ≠ real, notas/username con iptv|xtream|m3u,
 *   import plex_manager sin external_id, username solo dígitos, emails sintéticos.
 * - Nunca hard-delete; solo soft-delete (deleted_at) o detach (server_id=null).
 */
final class IptvCleanupService
{
    public const ACTION_SOFT_DELETE = 'soft_delete';
    public const ACTION_DETACH = 'detach';

    /**
     * @return array{
     *   candidates: array<int, array{user: MediaUser, score: int, reasons: array<int, string>}>,
     *   heuristic: array<int, string>
     * }
     */
    public function findCandidates(int $tenantId, ?int $serverId = null): array
    {
        $db = Database::getInstance();
        $params = [$tenantId];
        $sql = 'SELECT mu.*, s.name AS server_name, s.type AS server_type, s.uuid AS server_uuid
                FROM media_users mu
                LEFT JOIN servers s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.tenant_id = ? AND mu.deleted_at IS NULL
                  AND (s.type = \'plex\' OR mu.server_id IS NULL OR mu.metadata IS NOT NULL)';

        if ($serverId !== null && $serverId > 0) {
            $sql .= ' AND mu.server_id = ?';
            $params[] = $serverId;
        }

        $sql .= ' ORDER BY mu.id DESC LIMIT 2000';

        $rows = $db->fetchAll($sql, $params);
        $candidates = [];

        foreach ($rows as $row) {
            $user = new MediaUser($row);
            $user->server_name = $row['server_name'] ?? null;
            $user->server_type = $row['server_type'] ?? null;
            $serverType = (string) ($row['server_type'] ?? '');
            // Solo Plex / sin servidor / imports con metadata (IPTV mezclado).
            $importedFrom = mb_strtolower(trim((string) ($user->metaGet('imported_from') ?? '')));
            if ($serverType !== 'plex' && $serverType !== '' && $importedFrom !== 'plex_manager') {
                continue;
            }
            [$score, $reasons] = $this->scoreUser($user, $serverType);
            if ($score < 2) {
                continue;
            }
            $candidates[] = [
                'user' => $user,
                'score' => $score,
                'reasons' => $reasons,
            ];
        }

        usort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'candidates' => $candidates,
            'heuristic' => $this->heuristicDescription(),
        ];
    }

    /**
     * @param array<int, string> $uuids
     * @return array{processed: int, soft_deleted: int, detached: int, skipped: int, errors: array<int, string>}
     */
    public function apply(int $tenantId, array $uuids, string $action, string $confirmPhrase): array
    {
        $stats = [
            'processed' => 0,
            'soft_deleted' => 0,
            'detached' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        if (trim($confirmPhrase) !== 'LIMPIAR IPTV') {
            $stats['errors'][] = 'Confirmación incorrecta. Escribe exactamente: LIMPIAR IPTV';
            return $stats;
        }

        if ($action !== self::ACTION_SOFT_DELETE && $action !== self::ACTION_DETACH) {
            $stats['errors'][] = 'Acción no válida.';
            return $stats;
        }

        $uuids = array_values(array_unique(array_filter(array_map(
            static fn ($u) => trim((string) $u),
            $uuids
        ), static fn (string $u) => $u !== '')));

        if ($uuids === []) {
            $stats['errors'][] = 'No hay usuarios seleccionados.';
            return $stats;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($uuids as $uuid) {
            $row = Database::getInstance()->fetchOne(
                'SELECT * FROM media_users WHERE uuid = ? AND tenant_id = ? AND deleted_at IS NULL LIMIT 1',
                [$uuid, $tenantId]
            );
            if ($row === null) {
                $stats['skipped']++;
                continue;
            }

            $user = new MediaUser($row);
            [$score] = $this->scoreUser($user, $this->serverTypeFor((int) ($user->server_id ?? 0)));
            if ($score < 2) {
                $stats['skipped']++;
                $stats['errors'][] = ($user->email ?? $user->username) . ': score bajo, omitido por seguridad.';
                continue;
            }

            try {
                if ($action === self::ACTION_SOFT_DELETE) {
                    $user->deleted_at = $now;
                    $user->metaSet('iptv_cleanup_at', $now);
                    $user->metaSet('iptv_cleanup_action', self::ACTION_SOFT_DELETE);
                    $user->save();
                    AuditService::log('media_user.iptv_soft_deleted', 'media_user', (int) $user->id, null, [
                        'score' => $score,
                    ]);
                    $stats['soft_deleted']++;
                } else {
                    $oldServerId = $user->server_id;
                    $user->server_id = null;
                    $user->external_id = null;
                    $user->status = 'suspended';
                    $user->metaSet('iptv_cleanup_at', $now);
                    $user->metaSet('iptv_cleanup_action', self::ACTION_DETACH);
                    $user->metaSet('iptv_detached_from_server_id', $oldServerId);
                    $user->save();
                    AuditService::log('media_user.iptv_detached', 'media_user', (int) $user->id, null, [
                        'score' => $score,
                        'from_server_id' => $oldServerId,
                    ]);
                    $stats['detached']++;
                }
                $stats['processed']++;
            } catch (\Throwable $e) {
                Logger::error('IPTV cleanup failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
                $stats['errors'][] = ($user->email ?? $user->username) . ': ' . $e->getMessage();
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /** @return array{0: int, 1: array<int, string>} */
    private function scoreUser(MediaUser $user, string $serverType): array
    {
        $score = 0;
        $reasons = [];
        $meta = $user->metaAll();
        $notes = mb_strtolower((string) ($user->notes ?? ''));
        $username = trim((string) ($user->username ?? ''));
        $email = mb_strtolower(trim((string) ($user->email ?? '')));
        $externalId = trim((string) ($user->external_id ?? ''));
        $emailType = mb_strtolower(trim((string) ($meta['email_type'] ?? '')));
        $importedFrom = mb_strtolower(trim((string) ($meta['imported_from'] ?? '')));
        $haystack = $notes . ' ' . mb_strtolower($username) . ' ' . $email;

        if ($serverType === 'plex' || $serverType === '') {
            // base context, no points alone
        }

        if ($emailType !== '' && $emailType !== 'real') {
            $score += 3;
            $reasons[] = "email_type={$emailType}";
        }

        if (preg_match('/\b(iptv|xtream|m3u8?|bouquet|stalker)\b/i', $haystack)) {
            $score += 4;
            $reasons[] = 'texto IPTV/xtream/m3u';
        }

        if ($importedFrom === 'plex_manager' && $externalId === '') {
            $score += 3;
            $reasons[] = 'import plex_manager sin external_id Plex';
        }

        if ($serverType === 'plex' && $externalId === '' && $importedFrom === 'plex_manager') {
            $score += 1;
            $reasons[] = 'asignado a Plex sin ID remoto';
        }

        if ($username !== '' && preg_match('/^\d{4,}$/', $username)) {
            $score += 2;
            $reasons[] = 'username solo numérico';
        }

        if ($email !== '' && preg_match('/@(iptv\.|tv\.local|local$|example\.|test\.|invalid$)/i', $email)) {
            $score += 3;
            $reasons[] = 'email sintético/IPTV';
        }

        if ($email === '' && $externalId === '' && $serverType === 'plex') {
            $score += 1;
            $reasons[] = 'sin email ni external_id en Plex';
        }

        // Evitar marcar invitaciones Plex reales recientes
        if (in_array((string) $user->status, ['invited', 'pending'], true) && $externalId === '' && $email !== '' && $emailType === 'real') {
            $score = 0;
            $reasons = ['omitido: invitación Plex real'];
        }

        return [$score, array_values(array_unique($reasons))];
    }

    private function serverTypeFor(int $serverId): string
    {
        if ($serverId <= 0) {
            return '';
        }
        $server = Server::find($serverId);

        return $server ? (string) $server->type : '';
    }

    /** @return array<int, string> */
    public function heuristicDescription(): array
    {
        return [
            'Solo candidatos con score ≥ 2 (varias señales combinadas).',
            'email_type en metadata distinto de "real" (legado plex_manager).',
            'Notas/usuario/email con iptv, xtream, m3u, bouquet, stalker.',
            'Importado desde plex_manager sin external_id de Plex.',
            'Username solo dígitos (típico código IPTV) o email sintético.',
            'Las invitaciones Plex reales (status invited/pending + email real) se excluyen.',
            'Acciones: soft-delete (deleted_at) o detach (quita server_id, suspende). Sin hard-delete.',
        ];
    }
}
