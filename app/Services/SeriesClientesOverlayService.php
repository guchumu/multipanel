<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Import\ServicioServerMapper;
use App\Services\Peticiones\PeticionesConfig;
use App\Services\Peticiones\PeticionesDatabase;
use Core\Database;
use Core\Logger;

/**
 * Overlay de vencimientos / Telegram / email desde la BD remota `series.clientes`
 * (misma conexión que peticiones) hacia `media_users`.
 *
 * Reglas servicio:
 * - 1 y 5 (Plex): expires_at, email, notes (sanitizadas), telegram, matching.
 * - Resto (IPTV etc.): solo telegram_chat_id si falta; no usar fechafinal como caducidad Plex.
 */
final class SeriesClientesOverlayService
{
    /**
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   remote_rows: int,
     *   profiles: int,
     *   plex_profiles: int,
     *   matched: int,
     *   updated: int,
     *   telegram_filled: int,
     *   expires_filled: int,
     *   email_filled: int,
     *   notes_filled: int,
     *   telegram_from_plex: int,
     *   telegram_from_iptv_only: int,
     *   unmatched_profiles: int,
     *   overwrite: bool,
     *   sample_updated_ids: list<int>,
     *   errors: list<string>
     * }
     */
    public function syncFromRemote(int $tenantId, bool $overwrite = false): array
    {
        $empty = $this->emptyResult($overwrite);

        if (!PeticionesConfig::forTenant($tenantId)['configured']) {
            $empty['message'] = 'BD remota de peticiones/series no configurada. Ve a Configuración → Peticiones / BD remota.';
            $empty['errors'][] = $empty['message'];

            return $empty;
        }

        try {
            PeticionesDatabase::reset();
            $remote = PeticionesDatabase::getInstance($tenantId);
            $rows = $remote->fetchAll(
                'SELECT `id`, `idusuariotelegram`, `email1`, `email2`, `email3`, `email4`,
                        `fechapago`, `fechafinal`, `notas`, `notas2`, `servicio`
                 FROM `clientes`'
            );
        } catch (\Throwable $e) {
            Logger::error('series.clientes overlay: remote query failed', ['error' => $e->getMessage()]);
            $empty['message'] = 'Error al leer series.clientes: ' . $e->getMessage();
            $empty['errors'][] = $empty['message'];

            return $empty;
        }

        return $this->overlayRows($tenantId, $rows, $overwrite);
    }

    /**
     * Overlay desde filas ya cargadas (tests / dump local).
     *
     * @param list<array<string, mixed>> $rows
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   remote_rows: int,
     *   profiles: int,
     *   plex_profiles: int,
     *   matched: int,
     *   updated: int,
     *   telegram_filled: int,
     *   expires_filled: int,
     *   email_filled: int,
     *   notes_filled: int,
     *   telegram_from_plex: int,
     *   telegram_from_iptv_only: int,
     *   unmatched_profiles: int,
     *   overwrite: bool,
     *   sample_updated_ids: list<int>,
     *   errors: list<string>
     * }
     */
    public function overlayRows(int $tenantId, array $rows, bool $overwrite = false): array
    {
        @ini_set('memory_limit', '256M');
        @set_time_limit(180);

        $result = $this->emptyResult($overwrite);
        $result['ok'] = true;
        $result['remote_rows'] = count($rows);

        $profiles = $this->aggregateProfiles($rows);
        $result['profiles'] = count($profiles);
        $result['plex_profiles'] = count(array_filter(
            $profiles,
            static fn (array $p): bool => !empty($p['has_plex'])
        ));

        $db = Database::getInstance();
        $mediaUsers = $db->fetchAll(
            'SELECT `id`, `email`, `username`, `display_name`, `telegram_chat_id`,
                    `expires_at`, `notes`, `metadata`
             FROM `media_users`
             WHERE `tenant_id` = ? AND `deleted_at` IS NULL',
            [$tenantId]
        );

        $index = $this->indexMediaUsers($mediaUsers);
        /** @var array<int, true> */
        $matchedIds = [];
        /** @var list<int> */
        $sampleUpdatedIds = [];

        foreach ($profiles as $profile) {
            $userIds = $this->matchMediaUserIds($profile, $index);
            if ($userIds === []) {
                $result['unmatched_profiles']++;
                continue;
            }

            foreach ($userIds as $userId) {
                $matchedIds[$userId] = true;
                $user = $index['by_id'][$userId] ?? null;
                if ($user === null) {
                    continue;
                }

                $payload = $this->buildUpdatePayload($user, $profile, $overwrite);
                if ($payload['fields'] === []) {
                    continue;
                }

                try {
                    $db->update('media_users', $payload['fields'], 'id = ?', [$userId]);
                } catch (\Throwable $e) {
                    $result['errors'][] = 'media_user #' . $userId . ': ' . $e->getMessage();
                    continue;
                }

                $result['updated']++;
                if ($payload['telegram']) {
                    $result['telegram_filled']++;
                    if (!empty($profile['has_plex'])) {
                        $result['telegram_from_plex']++;
                    } else {
                        $result['telegram_from_iptv_only']++;
                    }
                }
                if ($payload['expires']) {
                    $result['expires_filled']++;
                }
                if ($payload['email']) {
                    $result['email_filled']++;
                }
                if ($payload['notes']) {
                    $result['notes_filled']++;
                }

                // Refrescar índice para no reescribir el mismo usuario con datos peores
                // en otro perfil solapado (poco habitual tras union-find).
                $merged = array_merge($user, $payload['fields']);
                $index['by_id'][$userId] = $merged;
                $this->reindexUser($index, $merged);

                if (count($sampleUpdatedIds) < 8) {
                    $sampleUpdatedIds[] = $userId;
                }
            }
        }

        $result['matched'] = count($matchedIds);
        $result['sample_updated_ids'] = $sampleUpdatedIds;
        $result['message'] = sprintf(
            'series.clientes: %d filas → %d perfiles (%d con Plex 1/5). Coincidencias %d, actualizados %d. Telegram %d (Plex %d / solo IPTV %d), caducidad %d, email %d, notas %d. Sin match: %d. overwrite=%s.',
            $result['remote_rows'],
            $result['profiles'],
            $result['plex_profiles'],
            $result['matched'],
            $result['updated'],
            $result['telegram_filled'],
            $result['telegram_from_plex'],
            $result['telegram_from_iptv_only'],
            $result['expires_filled'],
            $result['email_filled'],
            $result['notes_filled'],
            $result['unmatched_profiles'],
            $overwrite ? 'sí' : 'no'
        );

        try {
            AuditService::log(
                'series_clientes.overlay',
                'media_users',
                null,
                null,
                [
                    'remote_rows' => $result['remote_rows'],
                    'matched' => $result['matched'],
                    'updated' => $result['updated'],
                    'telegram_filled' => $result['telegram_filled'],
                    'expires_filled' => $result['expires_filled'],
                    'overwrite' => $overwrite,
                ],
                null,
                $tenantId
            );
        } catch (\Throwable) {
            // no bloquear overlay por auditoría
        }

        return $result;
    }

    /**
     * Agrupa filas de clientes por telegram/email compartidos y elige la mejor
     * caducidad Plex (MAX fechafinal entre servicio 1|5).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{
     *   telegram: ?string,
     *   emails: list<string>,
     *   email_primary: ?string,
     *   expires_at: ?string,
     *   fechapago: ?string,
     *   notes: ?string,
     *   has_plex: bool,
     *   plex_row_count: int,
     *   iptv_row_count: int,
     *   cliente_ids: list<int>
     * }>
     */
    public function aggregateProfiles(array $rows): array
    {
        $n = count($rows);
        if ($n === 0) {
            return [];
        }

        $parent = range(0, $n - 1);
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };
        $union = static function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        /** @var array<string, int> */
        $byTg = [];
        /** @var array<string, int> */
        $byEmail = [];

        foreach ($rows as $i => $row) {
            $tg = $this->normalizeTelegramId($row['idusuariotelegram'] ?? null);
            if ($tg !== null) {
                if (isset($byTg[$tg])) {
                    $union($i, $byTg[$tg]);
                }
                $byTg[$tg] = $i;
            }
            foreach ($this->rowEmails($row) as $email) {
                if (isset($byEmail[$email])) {
                    $union($i, $byEmail[$email]);
                }
                $byEmail[$email] = $i;
            }
        }

        /** @var array<int, list<array<string, mixed>>> */
        $groups = [];
        foreach ($rows as $i => $row) {
            $groups[$find($i)][] = $row;
        }

        $profiles = [];
        foreach ($groups as $groupRows) {
            $profiles[] = $this->buildProfile($groupRows);
        }

        return $profiles;
    }

    /**
     * Quita credenciales IPTV / URLs con password de notas.
     */
    public function sanitizeNotes(?string $notas, ?string $notas2 = null): ?string
    {
        $parts = [];
        foreach ([$notas, $notas2] as $raw) {
            $cleaned = $this->stripCredentialNoise((string) ($raw ?? ''));
            if ($cleaned !== null && $cleaned !== '') {
                $parts[] = $cleaned;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param list<array<string, mixed>> $groupRows
     * @return array{
     *   telegram: ?string,
     *   emails: list<string>,
     *   email_primary: ?string,
     *   expires_at: ?string,
     *   fechapago: ?string,
     *   notes: ?string,
     *   has_plex: bool,
     *   plex_row_count: int,
     *   iptv_row_count: int,
     *   cliente_ids: list<int>
     * }
     */
    private function buildProfile(array $groupRows): array
    {
        $emails = [];
        $telegram = null;
        $telegramFromPlex = null;
        $bestExpires = null;
        $bestExpiresTs = null;
        $bestEmailPrimary = null;
        $bestFechapago = null;
        $bestNotes = null;
        $plexCount = 0;
        $iptvCount = 0;
        $clienteIds = [];

        foreach ($groupRows as $row) {
            $clienteIds[] = (int) ($row['id'] ?? 0);
            $servicio = (int) ($row['servicio'] ?? 0);
            $isPlex = ServicioServerMapper::isAllowed($servicio);
            $tg = $this->normalizeTelegramId($row['idusuariotelegram'] ?? null);

            if ($tg !== null) {
                if ($isPlex && $telegramFromPlex === null) {
                    $telegramFromPlex = $tg;
                }
                if ($telegram === null) {
                    $telegram = $tg;
                }
            }

            foreach ($this->rowEmails($row) as $email) {
                $emails[$email] = true;
            }

            if ($isPlex) {
                $plexCount++;
                $expires = $this->dateToDatetime($row['fechafinal'] ?? null);
                $expiresTs = $expires !== null ? strtotime($expires) : false;
                $rowNotes = $this->sanitizeNotes(
                    isset($row['notas']) ? (string) $row['notas'] : null,
                    isset($row['notas2']) ? (string) $row['notas2'] : null
                );
                if ($expires !== null && $expiresTs !== false
                    && ($bestExpiresTs === null || $expiresTs > $bestExpiresTs)
                ) {
                    $bestExpires = $expires;
                    $bestExpiresTs = $expiresTs;
                    $email1 = $this->normalizeEmail($row['email1'] ?? null);
                    $bestEmailPrimary = $email1;
                    $bestFechapago = $this->dateToDatetime($row['fechapago'] ?? null, '00:00:00');
                    if ($rowNotes !== null) {
                        $bestNotes = $rowNotes;
                    }
                } elseif ($bestNotes === null && $rowNotes !== null) {
                    $bestNotes = $rowNotes;
                }
            } else {
                $iptvCount++;
            }
        }

        $emailList = array_keys($emails);
        sort($emailList);

        return [
            'telegram' => $telegramFromPlex ?? $telegram,
            'emails' => $emailList,
            'email_primary' => $bestEmailPrimary ?? ($emailList[0] ?? null),
            'expires_at' => $bestExpires,
            'fechapago' => $bestFechapago,
            'notes' => $bestNotes,
            'has_plex' => $plexCount > 0,
            'plex_row_count' => $plexCount,
            'iptv_row_count' => $iptvCount,
            'cliente_ids' => array_values(array_filter($clienteIds, static fn (int $id): bool => $id > 0)),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array{
     *   telegram: ?string,
     *   emails: list<string>,
     *   email_primary: ?string,
     *   expires_at: ?string,
     *   fechapago: ?string,
     *   notes: ?string,
     *   has_plex: bool,
     *   plex_row_count: int,
     *   iptv_row_count: int,
     *   cliente_ids: list<int>
     * } $profile
     * @return array{
     *   fields: array<string, mixed>,
     *   telegram: bool,
     *   expires: bool,
     *   email: bool,
     *   notes: bool
     * }
     */
    private function buildUpdatePayload(array $user, array $profile, bool $overwrite): array
    {
        $fields = [];
        $flags = ['telegram' => false, 'expires' => false, 'email' => false, 'notes' => false];

        $currentTg = normalize_telegram_chat_id($user['telegram_chat_id'] ?? null);
        $remoteTg = $profile['telegram'] ?? null;
        if ($remoteTg !== null && $remoteTg !== '') {
            if ($overwrite || $currentTg === '') {
                if ($currentTg !== $remoteTg) {
                    $fields['telegram_chat_id'] = $remoteTg;
                    $flags['telegram'] = true;
                }
            }
        }

        if (!empty($profile['has_plex']) && !empty($profile['expires_at'])) {
            $remoteExpires = (string) $profile['expires_at'];
            $currentExpires = trim((string) ($user['expires_at'] ?? ''));
            $shouldSetExpires = false;
            if ($overwrite) {
                $shouldSetExpires = $currentExpires !== $remoteExpires;
            } elseif ($currentExpires === '' || str_starts_with($currentExpires, '0000-00-00')) {
                $shouldSetExpires = true;
            } else {
                $curTs = strtotime($currentExpires);
                $remTs = strtotime($remoteExpires);
                if ($curTs !== false && $remTs !== false && $remTs > $curTs) {
                    $shouldSetExpires = true; // stale: remoto más reciente
                }
            }
            if ($shouldSetExpires) {
                $fields['expires_at'] = $remoteExpires;
                $flags['expires'] = true;
            }
        }

        $currentEmail = $this->normalizeEmail($user['email'] ?? null);
        $primary = $profile['email_primary'] ?? null;
        if ($primary !== null && ($overwrite || $currentEmail === null)) {
            if ($currentEmail !== $primary) {
                $fields['email'] = $primary;
                $flags['email'] = true;
            }
        }

        $remoteNotes = $profile['notes'] ?? null;
        $currentNotes = trim((string) ($user['notes'] ?? ''));
        if ($remoteNotes !== null && $remoteNotes !== '') {
            if ($overwrite || $currentNotes === '') {
                if ($currentNotes !== $remoteNotes) {
                    $fields['notes'] = $remoteNotes;
                    $flags['notes'] = true;
                }
            }
        }

        // Solo tocar metadata si hubo cambio útil (no hinchar "updated" con stamps vacíos).
        if ($fields !== []) {
            $metaPatch = [
                'series_clientes_overlay_at' => date('c'),
                'series_clientes_ids' => $profile['cliente_ids'] ?? [],
            ];
            if (!empty($profile['fechapago'])) {
                $metaPatch['series_fechapago'] = $profile['fechapago'];
            }
            if (!empty($profile['has_plex'])) {
                $metaPatch['series_servicio_plex'] = true;
            }

            $mergedMeta = $this->mergeMetadata($user['metadata'] ?? null, $metaPatch);
            if ($mergedMeta !== null) {
                $fields['metadata'] = $mergedMeta;
            }
        }

        return [
            'fields' => $fields,
            'telegram' => $flags['telegram'],
            'expires' => $flags['expires'],
            'email' => $flags['email'],
            'notes' => $flags['notes'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $mediaUsers
     * @return array{
     *   by_id: array<int, array<string, mixed>>,
     *   by_email: array<string, list<int>>,
     *   by_telegram: array<string, list<int>>,
     *   by_username: array<string, list<int>>
     * }
     */
    private function indexMediaUsers(array $mediaUsers): array
    {
        $index = [
            'by_id' => [],
            'by_email' => [],
            'by_telegram' => [],
            'by_username' => [],
        ];

        foreach ($mediaUsers as $user) {
            $id = (int) ($user['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $index['by_id'][$id] = $user;
            $this->reindexUser($index, $user);
        }

        return $index;
    }

    /**
     * @param array{
     *   by_id: array<int, array<string, mixed>>,
     *   by_email: array<string, list<int>>,
     *   by_telegram: array<string, list<int>>,
     *   by_username: array<string, list<int>>
     * } $index
     * @param array<string, mixed> $user
     */
    private function reindexUser(array &$index, array $user): void
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $email = $this->normalizeEmail($user['email'] ?? null);
        if ($email !== null) {
            $index['by_email'][$email] ??= [];
            if (!in_array($id, $index['by_email'][$email], true)) {
                $index['by_email'][$email][] = $id;
            }
        }

        $tg = normalize_telegram_chat_id($user['telegram_chat_id'] ?? null);
        if ($tg !== '' && preg_match('/^-?\d{5,20}$/', $tg) === 1) {
            $index['by_telegram'][$tg] ??= [];
            if (!in_array($id, $index['by_telegram'][$tg], true)) {
                $index['by_telegram'][$tg][] = $id;
            }
        }

        foreach (['username', 'display_name'] as $field) {
            $name = mb_strtolower(trim((string) ($user[$field] ?? '')));
            if ($name !== '' && strlen($name) >= 3) {
                $index['by_username'][$name] ??= [];
                if (!in_array($id, $index['by_username'][$name], true)) {
                    $index['by_username'][$name][] = $id;
                }
            }
        }
    }

    /**
     * @param array{
     *   telegram: ?string,
     *   emails: list<string>,
     *   email_primary: ?string,
     *   expires_at: ?string,
     *   fechapago: ?string,
     *   notes: ?string,
     *   has_plex: bool,
     *   plex_row_count: int,
     *   iptv_row_count: int,
     *   cliente_ids: list<int>
     * } $profile
     * @param array{
     *   by_id: array<int, array<string, mixed>>,
     *   by_email: array<string, list<int>>,
     *   by_telegram: array<string, list<int>>,
     *   by_username: array<string, list<int>>
     * } $index
     * @return list<int>
     */
    private function matchMediaUserIds(array $profile, array $index): array
    {
        /** @var array<int, true> */
        $ids = [];

        foreach ($profile['emails'] as $email) {
            foreach ($index['by_email'][$email] ?? [] as $id) {
                $ids[$id] = true;
            }
            $local = strstr($email, '@', true);
            $local = is_string($local) ? mb_strtolower(trim($local)) : '';
            if ($local !== '' && strlen($local) >= 3) {
                foreach ($index['by_username'][$local] ?? [] as $id) {
                    $ids[$id] = true;
                }
            }
        }

        $tg = $profile['telegram'] ?? null;
        if ($tg !== null && $tg !== '') {
            foreach ($index['by_telegram'][$tg] ?? [] as $id) {
                $ids[$id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** @param array<string, mixed> $row */
    private function rowEmails(array $row): array
    {
        $out = [];
        foreach (['email1', 'email2', 'email3', 'email4'] as $field) {
            $email = $this->normalizeEmail($row[$field] ?? null);
            if ($email !== null) {
                $out[$email] = true;
            }
        }

        return array_keys($out);
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) ($value ?? '')));
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        return $email;
    }

    private function normalizeTelegramId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);
        $value = trim($value, "\"'");
        if ($value === '' || $value === '0') {
            return null;
        }
        if (preg_match('/^-?\d{5,20}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function dateToDatetime(mixed $date, string $time = '23:59:59'): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $dateStr = trim((string) $date);
        if ($dateStr === '' || str_starts_with($dateStr, '0000-00-00')) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) === 1) {
            return $dateStr . ' ' . $time;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', $dateStr) === 1) {
            try {
                return (new \DateTimeImmutable($dateStr))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                return null;
            }
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $dateStr);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d') . ' ' . $time;
            }
        }

        try {
            return (new \DateTimeImmutable($dateStr))->format('Y-m-d') . ' ' . $time;
        } catch (\Exception) {
            return null;
        }
    }

    private function stripCredentialNoise(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $text = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\n"], $text);

        $looksLikeIptv = preg_match('/get\.php\?.*password=/i', $text) === 1
            || preg_match('/I-P-T-V|m3u_plus|m-3-u/i', $text) === 1
            || (
                preg_match('/Username\s*:/i', $text) === 1
                && preg_match('/Password\s*:/i', $text) === 1
            );

        if ($looksLikeIptv) {
            $lines = preg_split('/\R/u', $text) ?: [];
            $keep = [];
            foreach ($lines as $line) {
                $l = trim($line);
                if ($l === '') {
                    continue;
                }
                if (preg_match('#https?://\S+#i', $l) === 1) {
                    continue;
                }
                if (preg_match('/^(url|username|password|user|pass)\s*:/i', $l) === 1) {
                    continue;
                }
                if (preg_match('/I-P-T-V|m3u|mpegts|get\.php|m-3-u/i', $l) === 1) {
                    continue;
                }
                if (preg_match('/password=/i', $l) === 1) {
                    continue;
                }
                $keep[] = $l;
            }
            $text = implode("\n", $keep);
        } else {
            $text = preg_replace('#https?://[^\s]*password=[^\s]*#i', '', $text) ?? $text;
        }

        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $patch */
    private function mergeMetadata(mixed $existing, array $patch): ?string
    {
        $base = [];
        if (is_string($existing) && $existing !== '') {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $base = $decoded;
            }
        } elseif (is_array($existing)) {
            $base = $existing;
        }

        $merged = array_merge($base, $patch);

        try {
            return json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return array{
     *   ok: bool,
     *   remote_rows: int,
     *   profiles: int,
     *   plex_profiles: int,
     *   matched: int,
     *   updated: int,
     *   telegram_filled: int,
     *   expires_filled: int,
     *   email_filled: int,
     *   notes_filled: int,
     *   telegram_from_plex: int,
     *   telegram_from_iptv_only: int,
     *   unmatched_profiles: int,
     *   overwrite: bool,
     *   sample_updated_ids: list<int>,
     *   errors: list<string>
     * }
     */
    private function emptyResult(bool $overwrite): array
    {
        return [
            'ok' => false,
            'remote_rows' => 0,
            'profiles' => 0,
            'plex_profiles' => 0,
            'matched' => 0,
            'updated' => 0,
            'telegram_filled' => 0,
            'expires_filled' => 0,
            'email_filled' => 0,
            'notes_filled' => 0,
            'telegram_from_plex' => 0,
            'telegram_from_iptv_only' => 0,
            'unmatched_profiles' => 0,
            'overwrite' => $overwrite,
            'sample_updated_ids' => [],
            'errors' => [],
        ];
    }
}
