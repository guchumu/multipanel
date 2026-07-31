<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Services\Import\SqlInsertParser;
use Core\Database;
use Core\Logger;
use Ramsey\Uuid\Uuid;

/**
 * One-time migration from legacy plex_manager SQL dumps (phpMyAdmin).
 */
final class PlexManagerImportService
{
    public function __construct(
        private AuditService $audit = new AuditService(),
    ) {
    }

    /** @return array{servers: int, users: int, customers: int, subscriptions: int, skipped: int, parsed: array{servers: int, users: int}, errors: array<int, string>} */
    public function importFromSqlFile(string $filePath, int $tenantId): array
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $sql = file_get_contents($filePath);
        if ($sql === false || $sql === '') {
            return $this->result(0, 0, 0, 0, 0, ['servers' => 0, 'users' => 0], ['No se pudo leer el archivo SQL.'], SqlInsertParser::probe(''));
        }

        $probe = SqlInsertParser::probe($sql);
        $db = Database::getInstance();
        $errors = [];
        $serverMap = [];

        $legacyServers = SqlInsertParser::extractTable($sql, 'servers');
        $legacyUsers = SqlInsertParser::extractTable($sql, 'users');
        $parseStats = ['servers' => count($legacyServers), 'users' => count($legacyUsers)];
        $serversImported = 0;

        foreach ($legacyServers as $legacy) {
            try {
                $parsed = $this->parseServerEndpoint((string) ($legacy['public_ip'] ?? ''));
                if ($parsed === null) {
                    $errors[] = 'Servidor ' . ($legacy['server_name'] ?? '?') . ': URL pública inválida (' . ($legacy['public_ip'] ?? 'vacía') . ')';
                    continue;
                }

                $token = (string) ($legacy['token'] ?? '');
                $machineId = (string) ($legacy['machine_id'] ?? '');
                $existing = $db->fetchOne(
                    'SELECT id FROM servers WHERE tenant_id = ? AND deleted_at IS NULL AND (machine_id = ? OR (token != "" AND token = ?)) LIMIT 1',
                    [$tenantId, $machineId, $token]
                );

                if ($existing) {
                    $serverMap[(int) $legacy['id']] = (int) $existing['id'];
                    continue;
                }

                $server = new Server([
                    'tenant_id' => $tenantId,
                    'uuid' => Uuid::uuid4()->toString(),
                    'name' => (string) ($legacy['server_name'] ?? 'Plex'),
                    'description' => 'Importado desde plex_manager (ID ' . $legacy['id'] . ')',
                    'type' => 'plex',
                    'url' => $parsed['host'],
                    'port' => $parsed['port'],
                    'ssl' => $parsed['ssl'] ? 1 : 0,
                    'token' => (string) ($legacy['token'] ?? ''),
                    'machine_id' => (string) ($legacy['machine_id'] ?? ''),
                    'status' => 'offline',
                ]);
                $server->save();

                $serverMap[(int) $legacy['id']] = (int) $server->id;
                $serversImported++;
            } catch (\Throwable $e) {
                $errors[] = 'Servidor: ' . $e->getMessage();
            }
        }

        $planId = $this->ensureLegacyPlan($tenantId);
        $usersImported = 0;
        $customersImported = 0;
        $subscriptionsImported = 0;
        $skipped = 0;

        foreach ($legacyUsers as $legacy) {
            try {
                $email = strtolower(trim((string) ($legacy['email'] ?? '')));
                if ($email === '') {
                    $skipped++;
                    continue;
                }

                $legacyServerId = (int) ($legacy['server_id'] ?? 0);
                $serverId = $serverMap[$legacyServerId] ?? null;
                $username = trim((string) ($legacy['plex_username'] ?? ''));
                if ($username === '') {
                    $username = strstr($email, '@', true) ?: $email;
                }

                $externalId = (string) ($legacy['plex_user_id'] ?? '');
                $status = $this->mapStatus((string) ($legacy['status'] ?? 'invited'));
                $expiresAt = $this->dateToDatetime($legacy['end_date'] ?? null);
                $startsAt = $this->dateToDatetime($legacy['start_date'] ?? null, '00:00:00');

                $existing = $db->fetchOne(
                    'SELECT id FROM media_users WHERE tenant_id = ? AND email = ? AND (server_id = ? OR server_id IS NULL) AND deleted_at IS NULL LIMIT 1',
                    [$tenantId, $email, $serverId]
                );

                if ($existing) {
                    $db->update('media_users', array_filter([
                        'server_id' => $serverId,
                        'username' => $username,
                        'external_id' => $externalId ?: null,
                        'expires_at' => $expiresAt,
                        'status' => $status,
                        'notes' => $legacy['private_notes'] ?? null,
                    ], fn ($v) => $v !== null), 'id = ?', [$existing['id']]);
                    $mediaUserId = (int) $existing['id'];
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
                        'notes' => $legacy['private_notes'] ?? null,
                        'metadata' => json_encode([
                            'legacy_id' => $legacy['id'] ?? null,
                            'email_type' => $legacy['email_type'] ?? null,
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                    $usersImported++;
                }

                $customer = $db->fetchOne(
                    'SELECT id FROM customers WHERE tenant_id = ? AND email = ? LIMIT 1',
                    [$tenantId, $email]
                );

                $metadata = [
                    'telegram_id' => $legacy['telegram_id'] ?? null,
                    'telegram_chat_id' => $legacy['telegram_chat_id'] ?? null,
                    'legacy_plex_manager_id' => $legacy['id'] ?? null,
                    'plex_user_id' => $legacy['plex_user_id'] ?? null,
                    'email_type' => $legacy['email_type'] ?? null,
                ];

                if ($customer) {
                    $db->update('customers', [
                        'media_user_id' => $mediaUserId,
                        'status' => $status === 'active' ? 'active' : 'inactive',
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                        'notes' => $legacy['private_notes'] ?? null,
                    ], 'id = ?', [$customer['id']]);
                    $customerId = (int) $customer['id'];
                } else {
                    $customerId = (int) $db->insert('customers', [
                        'tenant_id' => $tenantId,
                        'uuid' => Uuid::uuid4()->toString(),
                        'media_user_id' => $mediaUserId,
                        'email' => $email,
                        'first_name' => $username,
                        'status' => $status === 'active' ? 'active' : 'inactive',
                        'notes' => $legacy['private_notes'] ?? null,
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    ]);
                    $customersImported++;
                }

                if ($startsAt !== null) {
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
                        'metadata' => json_encode(['imported_from' => 'plex_manager'], JSON_UNESCAPED_UNICODE),
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

        Logger::info('plex_manager SQL import', compact('serversImported', 'usersImported', 'customersImported'));
        $this->audit->log('import.plex_manager', 'import', null, null, [
            'servers' => $serversImported,
            'users' => $usersImported,
            'customers' => $customersImported,
        ]);

        return $this->result($serversImported, $usersImported, $customersImported, $subscriptionsImported, $skipped, $parseStats, $errors, $probe);
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

        $dateStr = (string) $date;
        if (strlen($dateStr) === 10) {
            return $dateStr . ' ' . $time;
        }

        return $dateStr;
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

    /** @param array{servers: int, users: int} $parsed @param array<int, string> $errors @param array<string, mixed> $probe */
    private function result(int $servers, int $users, int $customers, int $subscriptions, int $skipped, array $parsed, array $errors, array $probe = []): array
    {
        return [
            'servers' => $servers,
            'users' => $users,
            'customers' => $customers,
            'subscriptions' => $subscriptions,
            'skipped' => $skipped,
            'parsed' => $parsed,
            'probe' => $probe,
            'errors' => $errors,
        ];
    }
}
