<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Services\Import\ServicioServerMapper;
use App\Services\Import\SqlInsertParser;
use Core\Database;
use Core\Logger;
use Ramsey\Uuid\Uuid;

/**
 * One-time migration from legacy plex_manager SQL dumps (phpMyAdmin).
 *
 * Solo se aplican filas con servicio IN (1, 5) — ver config/import_servicio.php.
 * 1 = Server10, 5 = NucBox. El resto (IPTV u otros packs) se ignora.
 */
final class PlexManagerImportService
{
    /** Importa/crea usuarios + CRM (filtrado por servicio 1/5). */
    public const MODE_FULL = 'full';

    /** Solo actualiza fechas/Telegram/email sobre media_users ya existentes (tras wipe+sync). */
    public const MODE_OVERLAY = 'overlay';

    public function __construct(
        private AuditService $audit = new AuditService(),
        private ServerSyncService $sync = new ServerSyncService(),
        private ServicioServerMapper $servicioMapper = new ServicioServerMapper(),
    ) {
    }

    /**
     * @return array{
     *   servers: int, users: int, customers: int, subscriptions: int, libraries: int, skipped: int,
     *   skipped_servicio: int, matched: int, updated: int, mode: string,
     *   parsed: array{servers: int, users: int},
     *   sync: array<int, array{name: string, ok: bool, error: ?string}>,
     *   errors: array<int, string>, telegram_backfilled?: int, probe?: array<string, mixed>
     * }
     */
    public function importFromSqlFile(string $filePath, int $tenantId, string $mode = self::MODE_FULL): array
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $mode = $mode === self::MODE_OVERLAY ? self::MODE_OVERLAY : self::MODE_FULL;

        $sql = file_get_contents($filePath);
        if ($sql === false || $sql === '') {
            return $this->result(0, 0, 0, 0, 0, ['servers' => 0, 'users' => 0], ['No se pudo leer el archivo SQL.'], SqlInsertParser::probe(''), 0, [], 0, 0, 0, 0, $mode);
        }

        $probe = SqlInsertParser::probe($sql);
        $db = Database::getInstance();
        $this->ensureImportSchema();
        $errors = [];
        $serverMap = [];
        $legacyServerNames = [];

        $legacyServers = SqlInsertParser::extractTable($sql, 'servers');
        $legacyUsers = SqlInsertParser::extractTable($sql, 'users');
        $parseStats = ['servers' => count($legacyServers), 'users' => count($legacyUsers)];
        $serversImported = 0;
        $paymentByEmail = ServicioServerMapper::paymentServicioByEmail($sql);

        foreach ($legacyServers as $legacy) {
            $legacyId = (int) ($legacy['id'] ?? 0);
            $legacyName = (string) ($legacy['server_name'] ?? 'Plex');
            if ($legacyId > 0) {
                $legacyServerNames[$legacyId] = $legacyName;
            }

            // En modo overlay no creamos/actualizamos servidores: solo metadata sobre usuarios sync.
            if ($mode === self::MODE_OVERLAY) {
                continue;
            }

            try {
                $parsed = $this->parseServerEndpoint((string) ($legacy['public_ip'] ?? ''));
                if ($parsed === null) {
                    $errors[] = 'Servidor ' . $legacyName . ': URL pública inválida (' . ($legacy['public_ip'] ?? 'vacía') . ')';
                    continue;
                }

                $token = (string) ($legacy['token'] ?? '');
                $machineId = (string) ($legacy['machine_id'] ?? '');
                $existing = $db->fetchOne(
                    'SELECT id FROM servers WHERE tenant_id = ? AND deleted_at IS NULL AND (machine_id = ? OR (token != "" AND token = ?)) LIMIT 1',
                    [$tenantId, $machineId, $token]
                );

                if ($existing) {
                    $serverId = (int) $existing['id'];
                    $serverMap[$legacyId] = $serverId;
                    $db->update('servers', [
                        'name' => $legacyName,
                        'url' => $parsed['host'],
                        'port' => $parsed['port'],
                        'ssl' => $parsed['ssl'] ? 1 : 0,
                        'token' => $token,
                        'machine_id' => $machineId,
                    ], 'id = ?', [$serverId]);
                    continue;
                }

                $server = new Server([
                    'tenant_id' => $tenantId,
                    'uuid' => Uuid::uuid4()->toString(),
                    'name' => $legacyName,
                    'description' => 'Importado desde plex_manager (ID ' . ($legacy['id'] ?? '?') . ')',
                    'type' => 'plex',
                    'url' => $parsed['host'],
                    'port' => $parsed['port'],
                    'ssl' => $parsed['ssl'] ? 1 : 0,
                    'token' => (string) ($legacy['token'] ?? ''),
                    'machine_id' => (string) ($legacy['machine_id'] ?? ''),
                    'status' => 'offline',
                ]);
                $server->save();

                $serverMap[$legacyId] = (int) $server->id;
                $serversImported++;
            } catch (\Throwable $e) {
                $errors[] = 'Servidor: ' . $e->getMessage();
            }
        }

        $librariesImported = $mode === self::MODE_FULL ? $this->importLibraries($sql, $serverMap) : 0;

        $planId = $mode === self::MODE_FULL ? $this->ensureLegacyPlan($tenantId) : 0;
        $usersImported = 0;
        $customersImported = 0;
        $subscriptionsImported = 0;
        $skipped = 0;
        $skippedServicio = 0;
        $matched = 0;
        $updated = 0;

        /** @var array<int, array{server_id: ?int, server_name: ?string}> */
        $servicioServerCache = [];

        foreach ($legacyUsers as $legacy) {
            try {
                $email = strtolower(trim((string) ($legacy['email'] ?? '')));
                if ($email === '') {
                    $skipped++;
                    continue;
                }

                $servicio = ServicioServerMapper::resolveRowServicio($legacy, $paymentByEmail, $legacyServerNames);
                if (!ServicioServerMapper::isAllowed($servicio)) {
                    $skippedServicio++;
                    continue;
                }

                if (!isset($servicioServerCache[$servicio])) {
                    $servicioServerCache[$servicio] = $this->servicioMapper->resolveTenantServer($tenantId, $servicio);
                }
                $mapped = $servicioServerCache[$servicio];
                $mappedServerId = $mapped['server_id'] ?? null;

                $legacyServerId = (int) ($legacy['server_id'] ?? 0);
                $fallbackServerId = $serverMap[$legacyServerId] ?? null;
                $serverId = $mappedServerId ?? $fallbackServerId;

                if ($serverId === null) {
                    $errors[] = sprintf(
                        'Usuario %s: no hay servidor MultiPanel para servicio %d (%s). Revisa el nombre (needles: %s) o IMPORT_SERVICIO_%d_SERVERS.',
                        $email,
                        $servicio,
                        ServicioServerMapper::label($servicio),
                        implode(', ', ServicioServerMapper::nameNeedlesByServicio()[$servicio] ?? []),
                        $servicio
                    );
                    $skipped++;
                    continue;
                }

                $username = trim((string) ($legacy['plex_username'] ?? ''));
                if ($username === '') {
                    $username = strstr($email, '@', true) ?: $email;
                }

                $externalId = (string) ($legacy['plex_user_id'] ?? '');
                $status = $this->mapStatus((string) ($legacy['status'] ?? 'invited'));
                $expiresAt = $this->dateToDatetime($legacy['end_date'] ?? null);
                $startsAt = $this->dateToDatetime($legacy['start_date'] ?? null, '00:00:00');
                $telegramChatId = $this->resolveTelegramChatId($legacy);

                $existing = $this->findMediaUserForImport($tenantId, $email, $username, (int) $serverId);

                if ($mode === self::MODE_OVERLAY) {
                    if ($existing === null) {
                        $skipped++;
                        continue;
                    }
                    $matched++;
                    $payload = array_filter([
                        'expires_at' => $expiresAt,
                        'telegram_chat_id' => $telegramChatId,
                        'notes' => $legacy['private_notes'] ?? null,
                        'email' => $email !== '' ? $email : null,
                    ], static fn ($v) => $v !== null && $v !== '');
                    if ($payload !== []) {
                        $db->update('media_users', $payload, 'id = ?', [$existing['id']]);
                        $updated++;
                    }
                    $this->touchCustomerMetadata($tenantId, (int) $existing['id'], $email, $username, $legacy, $status);
                    continue;
                }

                if ($existing) {
                    $db->update('media_users', array_filter([
                        'server_id' => $serverId,
                        'username' => $username,
                        'external_id' => $externalId ?: null,
                        'expires_at' => $expiresAt,
                        'status' => $status,
                        'telegram_chat_id' => $telegramChatId,
                        'notes' => $legacy['private_notes'] ?? null,
                    ], fn ($v) => $v !== null), 'id = ?', [$existing['id']]);
                    $mediaUserId = (int) $existing['id'];
                    $matched++;
                    $updated++;
                    $skipped++;
                } else {
                    $mediaUserId = (int) $db->insert('media_users', [
                        'tenant_id' => $tenantId,
                        'uuid' => Uuid::uuid4()->toString(),
                        'server_id' => $serverId,
                        'external_id' => $externalId ?: null,
                        'email' => $email,
                        'username' => $username,
                        'display_name' => $username,
                        'status' => $status,
                        'expires_at' => $expiresAt,
                        'telegram_chat_id' => $telegramChatId,
                        'notes' => $legacy['private_notes'] ?? null,
                        'metadata' => json_encode([
                            'legacy_id' => $legacy['id'] ?? null,
                            'email_type' => $legacy['email_type'] ?? null,
                            'imported_from' => 'plex_manager',
                            'servicio' => $servicio,
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                    $usersImported++;
                }

                $customerId = $this->upsertCustomer($tenantId, $mediaUserId, $email, $username, $legacy, $status);
                if ($customerId < 0) {
                    $customersImported++;
                    $customerId = abs($customerId);
                }

                if ($startsAt !== null && $planId > 0) {
                    $sub = $db->fetchOne(
                        'SELECT id FROM subscriptions WHERE customer_id = ? AND media_user_id = ? LIMIT 1',
                        [$customerId, $mediaUserId]
                    );

                    $subStatus = $status === 'active' ? 'active' : ($status === 'suspended' ? 'expired' : 'cancelled');
                    $subData = [
                        'tenant_id' => $tenantId,
                        'customer_id' => $customerId,
                        'plan_id' => $planId,
                        'media_user_id' => $mediaUserId,
                        'status' => $subStatus,
                        'gateway' => 'manual',
                        'amount' => 0,
                        'starts_at' => $startsAt,
                        'ends_at' => $expiresAt,
                        'metadata' => json_encode([
                            'imported_from' => 'plex_manager',
                            'servicio' => $servicio,
                        ], JSON_UNESCAPED_UNICODE),
                    ];

                    if ($sub) {
                        $db->update('subscriptions', $subData, 'id = ?', [$sub['id']]);
                    } else {
                        $db->insert('subscriptions', $subData);
                        $subscriptionsImported++;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = 'Usuario ' . ($legacy['email'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        $syncResults = [];
        if ($mode === self::MODE_FULL) {
            foreach (array_unique(array_values($serverMap)) as $serverId) {
                $this->sync->refreshDbCounts((int) $serverId);
                $server = Server::find((int) $serverId);
                if ($server === null) {
                    continue;
                }

                $ok = $this->sync->sync($server);
                $syncResults[] = [
                    'name' => (string) $server->name,
                    'ok' => $ok,
                    'error' => $server->last_error,
                ];
            }
        }

        $telegramBackfilled = $this->backfillTelegramChatIds($tenantId);

        Logger::info('plex_manager SQL import', compact(
            'serversImported',
            'usersImported',
            'customersImported',
            'librariesImported',
            'telegramBackfilled',
            'skippedServicio',
            'matched',
            'updated',
            'mode'
        ));
        $this->audit->log('import.plex_manager', 'import', null, null, [
            'servers' => $serversImported,
            'users' => $usersImported,
            'customers' => $customersImported,
            'libraries' => $librariesImported,
            'skipped_servicio' => $skippedServicio,
            'matched' => $matched,
            'updated' => $updated,
            'mode' => $mode,
            'sync' => $syncResults,
        ]);

        return $this->result(
            $serversImported,
            $usersImported,
            $customersImported,
            $subscriptionsImported,
            $skipped,
            $parseStats,
            $errors,
            $probe,
            $librariesImported,
            $syncResults,
            $telegramBackfilled,
            $skippedServicio,
            $matched,
            $updated,
            $mode
        );
    }

    /**
     * Importa solo fechas/Telegram/email sobre usuarios ya sincronizados (servicio 1 y 5).
     *
     * @return array<string, mixed>
     */
    public function applyMetadataFromSqlFile(string $filePath, int $tenantId): array
    {
        return $this->importFromSqlFile($filePath, $tenantId, self::MODE_OVERLAY);
    }

    /** @return array{id: int}|null */
    private function findMediaUserForImport(int $tenantId, string $email, string $username, int $serverId): ?array
    {
        $db = Database::getInstance();

        $byEmail = $db->fetchOne(
            'SELECT id FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL AND server_id = ? AND LOWER(email) = LOWER(?)
             ORDER BY id DESC LIMIT 1',
            [$tenantId, $serverId, $email]
        );
        if ($byEmail) {
            return $byEmail;
        }

        $byUsername = $db->fetchOne(
            'SELECT id FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL AND server_id = ?
               AND LOWER(username) = LOWER(?)
             ORDER BY id DESC LIMIT 1',
            [$tenantId, $serverId, $username]
        );
        if ($byUsername) {
            return $byUsername;
        }

        // Fallback: mismo email/username en otro server_id del tenant (mapping servicio imperfecto).
        $byEmailAny = $db->fetchOne(
            'SELECT id FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL AND LOWER(email) = LOWER(?)
             ORDER BY (server_id = ?) DESC, id DESC LIMIT 1',
            [$tenantId, $email, $serverId]
        );
        if ($byEmailAny) {
            return $byEmailAny;
        }

        if ($username === '') {
            return null;
        }

        return $db->fetchOne(
            'SELECT id FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL AND LOWER(username) = LOWER(?)
             ORDER BY (server_id = ?) DESC, id DESC LIMIT 1',
            [$tenantId, $username, $serverId]
        ) ?: null;
    }

    /** @param array<string, mixed> $legacy */
    private function touchCustomerMetadata(int $tenantId, int $mediaUserId, string $email, string $username, array $legacy, string $status): void
    {
        $this->upsertCustomer($tenantId, $mediaUserId, $email, $username, $legacy, $status);
    }

    /**
     * @param array<string, mixed> $legacy
     * @return int customer id; negative if newly created (abs = id)
     */
    private function upsertCustomer(int $tenantId, int $mediaUserId, string $email, string $username, array $legacy, string $status): int
    {
        $db = Database::getInstance();
        $customer = $db->fetchOne(
            'SELECT id FROM customers WHERE tenant_id = ? AND email = ? LIMIT 1',
            [$tenantId, $email]
        );

        $resolvedTelegram = $this->resolveTelegramChatId($legacy);
        $metadata = [
            'telegram_id' => $legacy['telegram_id'] ?? null,
            'telegram_chat_id' => $resolvedTelegram ?? ($legacy['telegram_chat_id'] ?? null),
            'legacy_plex_manager_id' => $legacy['id'] ?? null,
            'plex_user_id' => $legacy['plex_user_id'] ?? null,
            'email_type' => $legacy['email_type'] ?? null,
            'servicio' => $legacy['servicio'] ?? $legacy['service'] ?? null,
        ];

        if ($customer) {
            $db->update('customers', [
                'media_user_id' => $mediaUserId,
                'status' => $status === 'active' ? 'active' : 'inactive',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'notes' => $legacy['private_notes'] ?? null,
            ], 'id = ?', [$customer['id']]);

            return (int) $customer['id'];
        }

        $id = (int) $db->insert('customers', [
            'tenant_id' => $tenantId,
            'uuid' => Uuid::uuid4()->toString(),
            'media_user_id' => $mediaUserId,
            'email' => $email,
            'first_name' => $username,
            'status' => $status === 'active' ? 'active' : 'inactive',
            'notes' => $legacy['private_notes'] ?? null,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);

        return -$id;
    }

    /** @param array<int, int> $serverMap */
    private function importLibraries(string $sql, array $serverMap): int
    {
        $imported = 0;
        $db = Database::getInstance();

        foreach (SqlInsertParser::extractTable($sql, 'libraries') as $legacy) {
            $serverId = $serverMap[(int) ($legacy['server_id'] ?? 0)] ?? null;
            $externalId = (string) ($legacy['library_key'] ?? '');
            $name = (string) ($legacy['library_name'] ?? 'Biblioteca');

            if ($serverId === null || $externalId === '') {
                continue;
            }

            $existing = $db->fetchOne(
                'SELECT id FROM libraries WHERE server_id = ? AND external_id = ? LIMIT 1',
                [$serverId, $externalId]
            );

            if ($existing) {
                $db->update('libraries', ['name' => $name], 'id = ?', [$existing['id']]);
                continue;
            }

            $db->insert('libraries', [
                'server_id' => $serverId,
                'external_id' => $externalId,
                'name' => $name,
                'type' => 'unknown',
            ]);
            $imported++;
        }

        return $imported;
    }

    /** @return array{host: string, port: int, ssl: bool}|null */
    private function parseServerEndpoint(string $publicIp): ?array
    {
        $publicIp = trim($publicIp);
        if ($publicIp === '') {
            return null;
        }

        if (!str_contains($publicIp, '://')) {
            $publicIp = 'http://' . $publicIp;
        }

        $parts = parse_url($publicIp);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 32400));

        return [
            'host' => $parts['host'],
            'port' => $port,
            'ssl' => $scheme === 'https',
        ];
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'pending' => 'pending',
            'inactive' => 'suspended',
            default => 'invited',
        };
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

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
            'd-m-Y H:i:s',
            'd-m-Y',
            'Y/m/d H:i:s',
            'Y/m/d',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s.u\Z',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s',
        ];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $dateStr);
            if (!$dt instanceof \DateTimeImmutable) {
                continue;
            }
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                continue;
            }

            $dateOnly = in_array($format, ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'], true);
            return $dateOnly
                ? $dt->format('Y-m-d') . ' ' . $time
                : $dt->format('Y-m-d H:i:s');
        }

        try {
            $dt = new \DateTimeImmutable($dateStr);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) === 1
                || preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}$/', $dateStr) === 1) {
                return $dt->format('Y-m-d') . ' ' . $time;
            }

            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $legacy */
    private function resolveTelegramChatId(array $legacy): ?string
    {
        foreach (['telegram_chat_id', 'telegram_id', 'idcliente', 'client_id'] as $field) {
            if (!array_key_exists($field, $legacy)) {
                continue;
            }
            $raw = $legacy[$field];
            if ($raw === null || $raw === '') {
                continue;
            }
            // Avoid scientific notation from float casts of long IDs.
            if (is_float($raw)) {
                continue;
            }
            $value = trim((string) $raw);
            if ($this->isValidTelegramChatId($value)) {
                return $value;
            }
        }

        return null;
    }

    private function isValidTelegramChatId(string $value): bool
    {
        return $value !== '' && $value !== '0' && preg_match('/^-?\d{5,20}$/', $value) === 1;
    }

    /** Ensure columns/tables needed for plex_manager import exist on older databases. */
    private function ensureImportSchema(): void
    {
        try {
            (new \Core\Updater())->runMigrations();
        } catch (\Throwable $e) {
            Logger::warning('Import auto-migration skipped', ['error' => $e->getMessage()]);
        }

        $db = Database::getInstance();
        try {
            $db->pdo()->exec(
                'ALTER TABLE `media_users` ADD COLUMN `telegram_chat_id` VARCHAR(50) NULL AFTER `email`'
            );
            \App\Repositories\MediaUserRepository::clearColumnCache();
        } catch (\Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
            \App\Repositories\MediaUserRepository::clearColumnCache();
        }
    }

    /** Backfill media_users.telegram_chat_id from customers.metadata (imports previos). */
    public function backfillTelegramChatIds(int $tenantId): int
    {
        $db = Database::getInstance();
        $updated = 0;

        try {
            $rows = $db->fetchAll(
                'SELECT mu.id, c.metadata
                 FROM media_users mu
                 INNER JOIN customers c ON c.media_user_id = mu.id AND c.tenant_id = ?
                 WHERE mu.tenant_id = ? AND mu.deleted_at IS NULL
                   AND (mu.telegram_chat_id IS NULL OR mu.telegram_chat_id = "")',
                [$tenantId, $tenantId]
            );
        } catch (\Throwable) {
            return 0;
        }

        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            if (!is_array($metadata)) {
                continue;
            }

            $chatId = $this->resolveTelegramChatId($metadata);
            if ($chatId === null) {
                continue;
            }

            $db->update('media_users', ['telegram_chat_id' => $chatId], 'id = ?', [(int) $row['id']]);
            $updated++;
        }

        return $updated;
    }

    private function ensureLegacyPlan(int $tenantId): int
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id FROM subscription_plans WHERE tenant_id = ? AND slug = ? LIMIT 1',
            [$tenantId, 'legacy-import']
        );

        if ($existing) {
            return (int) $existing['id'];
        }

        return (int) $db->insert('subscription_plans', [
            'tenant_id' => $tenantId,
            'name' => 'Importado plex_manager',
            'slug' => 'legacy-import',
            'description' => 'Plan placeholder para suscripciones migradas',
            'price' => 0,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'is_active' => 0,
        ]);
    }

    /**
     * @param array{servers: int, users: int} $parsed
     * @param array<int, string> $errors
     * @param array<string, mixed> $probe
     * @param array<int, array{name: string, ok: bool, error: ?string}> $sync
     * @return array<string, mixed>
     */
    private function result(
        int $servers,
        int $users,
        int $customers,
        int $subscriptions,
        int $skipped,
        array $parsed,
        array $errors,
        array $probe = [],
        int $libraries = 0,
        array $sync = [],
        int $telegramBackfilled = 0,
        int $skippedServicio = 0,
        int $matched = 0,
        int $updated = 0,
        string $mode = self::MODE_FULL,
    ): array {
        return [
            'servers' => $servers,
            'users' => $users,
            'customers' => $customers,
            'subscriptions' => $subscriptions,
            'libraries' => $libraries,
            'skipped' => $skipped,
            'skipped_servicio' => $skippedServicio,
            'matched' => $matched,
            'updated' => $updated,
            'mode' => $mode,
            'parsed' => $parsed,
            'probe' => $probe,
            'sync' => $sync,
            'errors' => $errors,
            'telegram_backfilled' => $telegramBackfilled,
        ];
    }
}
