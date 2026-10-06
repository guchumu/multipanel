<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use Core\Cache;
use Core\Database;
use Core\Logger;

/**
 * Detecta y fusiona usuarios media duplicados (mismo username, mismo email,
 * parte local del email = username, o mismo Telegram) que llegaron a crearse
 * por sync/import/registro. Se queda con el registro más completo.
 */
final class MediaUserDedupeService
{
    private const RUN_CACHE_TTL = 21600; // 6h: evita re-escanear en cada carga de página

    /** @return array{groups_merged: int, records_removed: int, usernames_fixed: int} */
    public function mergeDuplicatesForTenant(int $tenantId, bool $force = false): array
    {
        $cacheKey = 'media_user_dedupe_ran_' . $tenantId;
        if (!$force && Cache::get($cacheKey) !== null) {
            return ['groups_merged' => 0, 'records_removed' => 0, 'usernames_fixed' => 0];
        }

        $usernamesFixed = $this->backfillBlankUsernames($tenantId);

        $groupsMerged = 0;
        $recordsRemoved = 0;

        foreach ($this->findDuplicateGroups($tenantId, 'username') as $ids) {
            $recordsRemoved += $this->mergeGroup($ids);
            $groupsMerged++;
        }

        foreach ($this->findDuplicateGroups($tenantId, 'email') as $ids) {
            $recordsRemoved += $this->mergeGroup($ids);
            $groupsMerged++;
        }

        foreach ($this->findDuplicateGroups($tenantId, 'telegram') as $ids) {
            $recordsRemoved += $this->mergeGroup($ids);
            $groupsMerged++;
        }

        foreach ($this->findEmailLocalUsernameGroups($tenantId) as $ids) {
            $recordsRemoved += $this->mergeGroup($ids);
            $groupsMerged++;
        }

        Cache::set($cacheKey, [
            'at' => date('Y-m-d H:i:s'),
            'groups' => $groupsMerged,
            'usernames_fixed' => $usernamesFixed,
        ], self::RUN_CACHE_TTL);

        if ($groupsMerged > 0 || $usernamesFixed > 0) {
            Logger::info('Media user dedupe run', [
                'tenant_id' => $tenantId,
                'groups_merged' => $groupsMerged,
                'records_removed' => $recordsRemoved,
                'usernames_fixed' => $usernamesFixed,
            ]);
        }

        return [
            'groups_merged' => $groupsMerged,
            'records_removed' => $recordsRemoved,
            'usernames_fixed' => $usernamesFixed,
        ];
    }

    /**
     * Fusiona dos fichas concretas (p. ej. desde la UI de duplicados).
     * Conserva la de mayor score y soft-borra la otra.
     *
     * @return array{success: bool, kept_id?: int, removed_id?: int, message: string}
     */
    public function mergePair(int $tenantId, int $keepId, int $removeId): array
    {
        if ($keepId <= 0 || $removeId <= 0 || $keepId === $removeId) {
            return ['success' => false, 'message' => 'IDs de fusión no válidos.'];
        }

        $keep = MediaUser::find($keepId);
        $remove = MediaUser::find($removeId);
        if ($keep === null || $remove === null
            || (int) ($keep->tenant_id ?? 0) !== $tenantId
            || (int) ($remove->tenant_id ?? 0) !== $tenantId
            || $keep->deleted_at !== null
            || $remove->deleted_at !== null
        ) {
            return ['success' => false, 'message' => 'No se encontraron ambas fichas activas.'];
        }

        // Si la ficha “a borrar” es claramente mejor, invertir.
        if ($this->score($remove) > $this->score($keep) + 5) {
            [$keep, $remove] = [$remove, $keep];
        }

        $before = $keep->toArray();
        $this->mergeFields($keep, $remove);
        $keep->save();

        $remove->deleted_at = now()->format('Y-m-d H:i:s');
        $remove->metaSet('merged_into_media_user_id', (int) $keep->id);
        $remove->save();

        AuditService::log('media_user.duplicates_merged', 'media_user', (int) $keep->id, $before, [
            'kept' => (string) $keep->uuid,
            'merged_from' => [[
                'id' => (int) $remove->id,
                'uuid' => (string) $remove->uuid,
                'username' => (string) $remove->username,
                'email' => $remove->email,
            ]],
            'manual' => true,
        ]);

        Cache::forget('media_user_dedupe_ran_' . $tenantId);

        return [
            'success' => true,
            'kept_id' => (int) $keep->id,
            'removed_id' => (int) $remove->id,
            'message' => sprintf(
                'Fusionado: se conserva #%d (%s) y se archiva #%d.',
                (int) $keep->id,
                (string) ($keep->display_name ?: $keep->username ?: $keep->email),
                (int) $remove->id
            ),
        ];
    }

    /**
     * Parejas sospechosas que el auto-merge aún no ha unido (para revisión manual).
     *
     * @return list<array{
     *   reason: string,
     *   a: array<string, mixed>,
     *   b: array<string, mixed>
     * }>
     */
    public function listSuspectedPairs(int $tenantId, int $limit = 80): array
    {
        $db = Database::getInstance();
        $pairs = [];
        $seen = [];

        $add = static function (array $a, array $b, string $reason) use (&$pairs, &$seen, $limit): void {
            if (count($pairs) >= $limit) {
                return;
            }
            $idA = (int) ($a['id'] ?? 0);
            $idB = (int) ($b['id'] ?? 0);
            if ($idA <= 0 || $idB <= 0 || $idA === $idB) {
                return;
            }
            $key = $idA < $idB ? $idA . ':' . $idB : $idB . ':' . $idA;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $pairs[] = ['reason' => $reason, 'a' => $a, 'b' => $b];
        };

        // Mismo email (no deberían quedar tras auto-merge; por si el cache lo bloqueó).
        $rows = $db->fetchAll(
            "SELECT LOWER(TRIM(email)) AS k, GROUP_CONCAT(id ORDER BY id) AS ids
             FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND email IS NOT NULL AND TRIM(email) != ''
             GROUP BY k HAVING COUNT(*) > 1
             LIMIT 40",
            [$tenantId]
        );
        foreach ($rows as $row) {
            $ids = array_map('intval', explode(',', (string) ($row['ids'] ?? '')));
            $users = $this->loadSummaryRows($ids);
            if (count($users) >= 2) {
                $add($users[0], $users[1], 'Mismo email');
            }
        }

        // Parte local del email = username de otra ficha.
        $emailLocal = $db->fetchAll(
            "SELECT a.id AS a_id, b.id AS b_id
             FROM media_users a
             INNER JOIN media_users b
               ON a.tenant_id = b.tenant_id
              AND a.id < b.id
              AND a.deleted_at IS NULL AND b.deleted_at IS NULL
             WHERE a.tenant_id = ?
               AND (
                 (a.email IS NOT NULL AND TRIM(a.email) != ''
                   AND LOWER(SUBSTRING_INDEX(TRIM(a.email), '@', 1)) = LOWER(TRIM(b.username)))
                 OR
                 (b.email IS NOT NULL AND TRIM(b.email) != ''
                   AND LOWER(SUBSTRING_INDEX(TRIM(b.email), '@', 1)) = LOWER(TRIM(a.username)))
               )
             LIMIT 60",
            [$tenantId]
        );
        foreach ($emailLocal as $row) {
            $users = $this->loadSummaryRows([(int) $row['a_id'], (int) $row['b_id']]);
            if (count($users) >= 2) {
                $add($users[0], $users[1], 'Email ↔ username (misma cuenta Plex)');
            }
        }

        // Mismo Telegram.
        $telegram = $db->fetchAll(
            "SELECT TRIM(telegram_chat_id) AS k, GROUP_CONCAT(id ORDER BY id) AS ids
             FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND telegram_chat_id IS NOT NULL AND TRIM(telegram_chat_id) != ''
               AND LOWER(TRIM(telegram_chat_id)) != 'null'
             GROUP BY k HAVING COUNT(*) > 1
             LIMIT 40",
            [$tenantId]
        );
        foreach ($telegram as $row) {
            $ids = array_map('intval', explode(',', (string) ($row['ids'] ?? '')));
            $users = $this->loadSummaryRows($ids);
            if (count($users) >= 2) {
                $add($users[0], $users[1], 'Mismo Telegram');
            }
        }

        return $pairs;
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function loadSummaryRows(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $db = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->fetchAll(
            "SELECT mu.id, mu.uuid, mu.username, mu.display_name, mu.email, mu.status,
                    mu.expires_at, mu.telegram_chat_id, mu.external_id, mu.on_server,
                    s.name AS server_name
             FROM media_users mu
             LEFT JOIN servers s ON s.id = mu.server_id AND s.deleted_at IS NULL
             WHERE mu.id IN ({$placeholders}) AND mu.deleted_at IS NULL
             ORDER BY mu.id ASC",
            $ids
        );

        return array_values($rows);
    }

    /** @return array<int, array<int, int>> */
    private function findDuplicateGroups(int $tenantId, string $field): array
    {
        $db = Database::getInstance();

        if ($field === 'telegram') {
            $rows = $db->fetchAll(
                "SELECT TRIM(telegram_chat_id) AS key_value, GROUP_CONCAT(id ORDER BY id) AS ids
                 FROM `media_users`
                 WHERE `tenant_id` = ? AND `deleted_at` IS NULL
                   AND telegram_chat_id IS NOT NULL AND TRIM(telegram_chat_id) != ''
                   AND LOWER(TRIM(telegram_chat_id)) != 'null'
                 GROUP BY key_value
                 HAVING COUNT(*) > 1",
                [$tenantId]
            );
        } else {
            $column = $field === 'email' ? 'email' : 'username';
            $rows = $db->fetchAll(
                "SELECT LOWER(TRIM(`{$column}`)) AS key_value, GROUP_CONCAT(id ORDER BY id) AS ids
                 FROM `media_users`
                 WHERE `tenant_id` = ? AND `deleted_at` IS NULL
                   AND `{$column}` IS NOT NULL AND TRIM(`{$column}`) != ''
                 GROUP BY key_value
                 HAVING COUNT(*) > 1",
                [$tenantId]
            );
        }

        $groups = [];
        foreach ($rows as $row) {
            $ids = array_map('intval', explode(',', (string) $row['ids']));
            if (count($ids) > 1) {
                $groups[] = $ids;
            }
        }

        return $groups;
    }

    /**
     * Grupos donde la parte local del email de A coincide con el username de B.
     *
     * @return array<int, array<int, int>>
     */
    private function findEmailLocalUsernameGroups(int $tenantId): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT a.id AS a_id, b.id AS b_id
             FROM media_users a
             INNER JOIN media_users b
               ON a.tenant_id = b.tenant_id
              AND a.id < b.id
              AND a.deleted_at IS NULL AND b.deleted_at IS NULL
             WHERE a.tenant_id = ?
               AND (
                 (a.email IS NOT NULL AND TRIM(a.email) != ''
                   AND LOWER(SUBSTRING_INDEX(TRIM(a.email), '@', 1)) = LOWER(TRIM(b.username))
                   AND TRIM(b.username) != '')
                 OR
                 (b.email IS NOT NULL AND TRIM(b.email) != ''
                   AND LOWER(SUBSTRING_INDEX(TRIM(b.email), '@', 1)) = LOWER(TRIM(a.username))
                   AND TRIM(a.username) != '')
               )",
            [$tenantId]
        );

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = [(int) $row['a_id'], (int) $row['b_id']];
        }

        return $groups;
    }

    /** @param list<int> $ids */
    private function mergeGroup(array $ids): int
    {
        $users = array_filter(array_map(static fn (int $id) => MediaUser::find($id), $ids));
        $users = array_values(array_filter($users, static fn (?MediaUser $u) => $u !== null && $u->deleted_at === null));

        if (count($users) < 2) {
            return 0;
        }

        usort($users, fn (MediaUser $a, MediaUser $b) => $this->score($b) <=> $this->score($a));
        $primary = $users[0];
        $others = array_slice($users, 1);

        $before = $primary->toArray();
        $mergedFrom = [];

        foreach ($others as $other) {
            $this->mergeFields($primary, $other);
            $mergedFrom[] = [
                'id' => (int) $other->id,
                'uuid' => (string) $other->uuid,
                'username' => (string) $other->username,
                'email' => $other->email,
            ];
        }

        $primary->save();

        $removed = 0;
        foreach ($others as $other) {
            $other->deleted_at = now()->format('Y-m-d H:i:s');
            $other->metaSet('merged_into_media_user_id', (int) $primary->id);
            $other->save();
            $removed++;
        }

        AuditService::log('media_user.duplicates_merged', 'media_user', (int) $primary->id, $before, [
            'kept' => (string) $primary->uuid,
            'merged_from' => $mergedFrom,
        ]);

        return $removed;
    }

    private function mergeFields(MediaUser $primary, MediaUser $other): void
    {
        foreach (['email', 'display_name', 'telegram_chat_id', 'notes', 'external_id', 'password'] as $field) {
            if ($this->isBlank($primary->{$field} ?? null) && !$this->isBlank($other->{$field} ?? null)) {
                $primary->{$field} = $other->{$field};
            }
        }

        // Preferir username tipo handle (jordiprtl) frente a nombre con espacios.
        $primaryUser = trim((string) ($primary->username ?? ''));
        $otherUser = trim((string) ($other->username ?? ''));
        if ($primaryUser === '' && $otherUser !== '') {
            $primary->username = $otherUser;
        } elseif ($otherUser !== '' && $this->looksLikeDisplayName($primaryUser) && !$this->looksLikeDisplayName($otherUser)) {
            if ($this->isBlank($primary->display_name ?? null)) {
                $primary->display_name = $primaryUser;
            }
            $primary->username = $otherUser;
        }

        // Si el primario no tiene nombre legible y el otro sí (p. ej. "Jordi Portela").
        if ($this->isBlank($primary->display_name ?? null)
            || (!$this->looksLikeDisplayName((string) $primary->display_name)
                && $this->looksLikeDisplayName((string) ($other->display_name ?? '')))
        ) {
            if (!$this->isBlank($other->display_name ?? null)) {
                $primary->display_name = $other->display_name;
            }
        }

        if (empty($primary->server_id) && !empty($other->server_id)) {
            $primary->server_id = $other->server_id;
        }

        $primaryStreams = (int) ($primary->max_streams ?? 0);
        $otherStreams = (int) ($other->max_streams ?? 0);
        if ($otherStreams > $primaryStreams) {
            $primary->max_streams = $otherStreams;
        }

        $primaryDevices = (int) ($primary->max_devices ?? 0);
        $otherDevices = (int) ($other->max_devices ?? 0);
        if ($otherDevices > $primaryDevices) {
            $primary->max_devices = $otherDevices;
        }

        $primaryExpires = $primary->expires_at ? strtotime((string) $primary->expires_at) : 0;
        $otherExpires = $other->expires_at ? strtotime((string) $other->expires_at) : 0;
        if ($otherExpires > $primaryExpires) {
            $primary->expires_at = $other->expires_at;
        }

        $statusRank = ['active' => 3, 'invited' => 2, 'suspended' => 1, 'pending' => 1, 'expired' => 0];
        $primaryRank = $statusRank[(string) $primary->status] ?? 0;
        $otherRank = $statusRank[(string) $other->status] ?? 0;
        // Si el otro tiene fecha futura y el primario está suspended/expired, reactivar.
        if ($otherExpires > time() && in_array((string) $primary->status, ['suspended', 'expired'], true)
            && in_array((string) $other->status, ['active', 'invited'], true)
        ) {
            $primary->status = $other->status;
        } elseif ($otherRank > $primaryRank) {
            $primary->status = $other->status;
        }

        if (((int) ($primary->on_server ?? -1) !== 1) && ((int) ($other->on_server ?? -1) === 1)) {
            $primary->on_server = 1;
        }

        $primaryMeta = $primary->metaAll();
        $otherMeta = $other->metaAll();
        foreach ($otherMeta as $key => $value) {
            if (!array_key_exists($key, $primaryMeta) || $this->isBlank($primaryMeta[$key])) {
                $primary->metaSet($key, $value);
            }
        }
    }

    private function looksLikeDisplayName(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return str_contains($value, ' ') || preg_match('/[À-ÿ]/u', $value) === 1;
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }
        $trimmed = trim($value);

        return $trimmed === '' || strcasecmp($trimmed, 'null') === 0;
    }

    private function score(MediaUser $user): int
    {
        $score = 0;
        $score += !$this->isBlank($user->email ?? null) ? 20 : 0;
        $score += !$this->isBlank($user->external_id ?? null) ? 15 : 0;
        $score += ((int) ($user->on_server ?? 0) === 1) ? 12 : 0;
        $score += !$this->isBlank($user->telegram_chat_id ?? null) ? 8 : 0;
        $score += !$this->isBlank($user->expires_at ?? null) ? 8 : 0;
        $score += !$this->isBlank($user->notes ?? null) ? 5 : 0;
        $score += !empty($user->server_id) ? 5 : 0;
        $score += ((string) $user->status) === 'active' ? 4 : 0;
        $score += !$this->isBlank($user->metadata ?? null) ? 3 : 0;
        // Preferir handle Plex frente a "Nombre Apellido" como username.
        $uname = trim((string) ($user->username ?? ''));
        if ($uname !== '' && !$this->looksLikeDisplayName($uname)) {
            $score += 6;
        }
        if ($uname === '') {
            $score -= 10;
        }

        return $score;
    }

    private function backfillBlankUsernames(int $tenantId): int
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT id, email, display_name, username
             FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND (username IS NULL OR TRIM(username) = '')",
            [$tenantId]
        );

        $fixed = 0;
        foreach ($rows as $row) {
            $suggested = $this->suggestUsername(
                (string) ($row['email'] ?? ''),
                (string) ($row['display_name'] ?? ''),
                (int) $row['id']
            );
            if ($suggested === '') {
                continue;
            }
            $db->update('media_users', ['username' => $suggested], 'id = ?', [(int) $row['id']]);
            $fixed++;
        }

        return $fixed;
    }

    private function suggestUsername(string $email, string $displayName, int $excludeId): string
    {
        $email = trim($email);
        $candidates = [];
        if ($email !== '' && str_contains($email, '@')) {
            $local = strtolower(trim(strstr($email, '@', true) ?: ''));
            if ($local !== '') {
                $candidates[] = $local;
            }
        }

        $displayName = trim($displayName);
        if ($displayName !== '') {
            $slug = strtolower($displayName);
            $slug = preg_replace('/[^a-z0-9]+/i', '', $slug) ?? '';
            if ($slug !== '') {
                $candidates[] = $slug;
            }
        }

        $db = Database::getInstance();
        foreach ($candidates as $base) {
            $base = substr($base, 0, 80);
            $candidate = $base;
            $n = 0;
            while ($n < 20) {
                $exists = $db->fetchOne(
                    'SELECT id FROM media_users
                     WHERE deleted_at IS NULL AND LOWER(username) = LOWER(?) AND id != ?
                     LIMIT 1',
                    [$candidate, $excludeId]
                );
                if (!$exists) {
                    return $candidate;
                }
                $n++;
                $candidate = $base . $n;
            }
        }

        return $excludeId > 0 ? 'user' . $excludeId : '';
    }
}
