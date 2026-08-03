<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use Core\Cache;
use Core\Database;
use Core\Logger;

/**
 * Detecta y fusiona usuarios media duplicados (mismo username o mismo email
 * dentro del tenant) que llegaron a crearse antes de tener validación de
 * duplicados, o por importaciones antiguas. Se queda con el registro más
 * completo y traslada a él cualquier dato que le falte, en vez de borrar
 * información sin revisar.
 */
final class MediaUserDedupeService
{
    private const RUN_CACHE_TTL = 21600; // 6h: evita re-escanear en cada carga de página

    /** @return array{groups_merged: int, records_removed: int} */
    public function mergeDuplicatesForTenant(int $tenantId, bool $force = false): array
    {
        $cacheKey = 'media_user_dedupe_ran_' . $tenantId;
        if (!$force && Cache::get($cacheKey) !== null) {
            return ['groups_merged' => 0, 'records_removed' => 0];
        }

        $db = Database::getInstance();
        $groupsMerged = 0;
        $recordsRemoved = 0;

        foreach ($this->findDuplicateGroups($tenantId, 'username') as $ids) {
            $recordsRemoved += $this->mergeGroup($ids);
            $groupsMerged++;
        }

        // Segunda pasada por email, ya con los duplicados de username resueltos,
        // por si hay cuentas con distinto username pero el mismo email real.
        foreach ($this->findDuplicateGroups($tenantId, 'email') as $ids) {
            $recordsRemoved += $this->mergeGroup($ids);
            $groupsMerged++;
        }

        Cache::set($cacheKey, ['at' => date('Y-m-d H:i:s'), 'groups' => $groupsMerged], self::RUN_CACHE_TTL);

        if ($groupsMerged > 0) {
            Logger::info('Media user dedupe run', [
                'tenant_id' => $tenantId,
                'groups_merged' => $groupsMerged,
                'records_removed' => $recordsRemoved,
            ]);
        }

        unset($db);

        return ['groups_merged' => $groupsMerged, 'records_removed' => $recordsRemoved];
    }

    /** @return array<int, array<int, int>> lista de grupos, cada grupo es una lista de IDs duplicados */
    private function findDuplicateGroups(int $tenantId, string $field): array
    {
        $db = Database::getInstance();
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

        $groups = [];
        foreach ($rows as $row) {
            $ids = array_map('intval', explode(',', (string) $row['ids']));
            if (count($ids) > 1) {
                $groups[] = $ids;
            }
        }

        return $groups;
    }

    /** Fusiona un grupo de IDs duplicados en un único registro. Devuelve cuántos se eliminaron. */
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
            $mergedFrom[] = ['id' => (int) $other->id, 'uuid' => (string) $other->uuid, 'username' => (string) $other->username, 'email' => $other->email];
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
        if ($otherRank > $primaryRank) {
            $primary->status = $other->status;
        }

        $primaryMeta = $primary->metaAll();
        $otherMeta = $other->metaAll();
        foreach ($otherMeta as $key => $value) {
            if (!array_key_exists($key, $primaryMeta) || $this->isBlank($primaryMeta[$key])) {
                $primary->metaSet($key, $value);
            }
        }
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function score(MediaUser $user): int
    {
        $score = 0;
        $score += !$this->isBlank($user->email ?? null) ? 20 : 0;
        $score += !$this->isBlank($user->external_id ?? null) ? 15 : 0;
        $score += !$this->isBlank($user->telegram_chat_id ?? null) ? 8 : 0;
        $score += !$this->isBlank($user->notes ?? null) ? 5 : 0;
        $score += !empty($user->server_id) ? 5 : 0;
        $score += ((string) $user->status) === 'active' ? 4 : 0;
        $score += !$this->isBlank($user->metadata ?? null) ? 3 : 0;

        return $score;
    }
}
