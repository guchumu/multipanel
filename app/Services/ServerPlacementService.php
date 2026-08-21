<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use App\Repositories\ServerRepository;
use Core\Database;
use Core\Updater;

/**
 * A qué servidor va un alta/renovación: el cliente solo elige Plex o Jellyfin.
 *
 * - Si el usuario ya tiene servidor de ese tipo, se queda (renovar no mueve).
 * - Si es nuevo, va al predeterminado de ese tipo (o al siguiente con plaza).
 * - Cupo 0 / vacío = sin límite.
 */
final class ServerPlacementService
{
    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return $type === 'jellyfin' ? 'jellyfin' : 'plex';
    }

    public function typeOfServerId(int $serverId): ?string
    {
        if ($serverId <= 0) {
            return null;
        }
        $row = Database::getInstance()->fetchOne(
            'SELECT type FROM servers WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$serverId]
        );
        if ($row === null) {
            return null;
        }
        $type = strtolower(trim((string) ($row['type'] ?? '')));

        return in_array($type, ['plex', 'jellyfin'], true) ? $type : null;
    }

    /**
     * Tipos que el cliente puede elegir (sin nombres de servidor).
     *
     * @return list<array{type: string, label: string}>
     */
    public function shopTypes(int $tenantId): array
    {
        $seen = [];
        $out = [];
        foreach ($this->servers->allByTenant($tenantId) as $server) {
            $type = strtolower(trim((string) ($server->type ?? '')));
            if ($type !== 'plex' && $type !== 'jellyfin') {
                continue;
            }
            if (isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;
            $out[] = [
                'type' => $type,
                'label' => $type === 'jellyfin' ? 'Jellyfin' : 'Plex',
            ];
        }

        return $out;
    }

    public function countUsers(int $serverId): int
    {
        if ($serverId <= 0) {
            return 0;
        }
        $row = Database::getInstance()->fetchOne(
            "SELECT COUNT(*) AS n FROM media_users
             WHERE server_id = ? AND deleted_at IS NULL AND status NOT IN ('deleted')",
            [$serverId]
        );

        return (int) ($row['n'] ?? 0);
    }

    public function quotaOf(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }

        return max(0, (int) $raw);
    }

    public function hasRoom(int $used, int $quota, int $newSeats = 1): bool
    {
        if ($quota <= 0) {
            return true;
        }

        return ($used + max(0, $newSeats)) <= $quota;
    }

    /**
     * @param list<array{id:int,type:string,is_default:int,user_quota:int,used:int}> $servers
     * @return array{ok: bool, server_id: ?int, error?: string}
     */
    public static function pick(
        array $servers,
        string $type,
        int $keepServerId = 0,
        int $newSeats = 1,
    ): array {
        $type = $type === 'jellyfin' ? 'jellyfin' : 'plex';
        $ofType = array_values(array_filter(
            $servers,
            static fn (array $s): bool => strtolower((string) ($s['type'] ?? '')) === $type
        ));

        if ($keepServerId > 0) {
            foreach ($ofType as $s) {
                if ((int) $s['id'] === $keepServerId) {
                    return ['ok' => true, 'server_id' => $keepServerId];
                }
            }
        }

        if ($ofType === []) {
            return ['ok' => false, 'server_id' => null, 'error' => 'No hay servidor ' . ($type === 'jellyfin' ? 'Jellyfin' : 'Plex') . '.'];
        }

        $newSeats = max(0, $newSeats);
        $ordered = $ofType;
        usort($ordered, static function (array $a, array $b): int {
            $da = (int) ($a['is_default'] ?? 0);
            $db = (int) ($b['is_default'] ?? 0);
            if ($da !== $db) {
                return $db <=> $da;
            }

            return (int) $a['id'] <=> (int) $b['id'];
        });

        foreach ($ordered as $s) {
            $quota = max(0, (int) ($s['user_quota'] ?? 0));
            $used = max(0, (int) ($s['used'] ?? 0));
            if ($quota <= 0 || ($used + $newSeats) <= $quota) {
                return ['ok' => true, 'server_id' => (int) $s['id']];
            }
        }

        $label = $type === 'jellyfin' ? 'Jellyfin' : 'Plex';

        return ['ok' => false, 'server_id' => null, 'error' => "No hay plaza en {$label} (cupo lleno)."];
    }

    /**
     * @return array{ok: bool, server_id: ?int, error?: string}
     */
    public function place(int $tenantId, string $type, int $keepServerId = 0, int $newSeats = 1): array
    {
        $this->ensureQuotaColumn();
        $type = $this->normalizeType($type);
        $rows = [];
        foreach ($this->servers->allByTenant($tenantId) as $server) {
            $rows[] = [
                'id' => (int) $server->id,
                'type' => (string) $server->type,
                'is_default' => (int) ($server->is_default ?? 0),
                'user_quota' => $this->quotaOf($server->user_quota ?? 0),
                'used' => $this->countUsers((int) $server->id),
            ];
        }

        return self::pick($rows, $type, $keepServerId, $newSeats);
    }

    /**
     * Comprador: no se mueve si ya tiene servidor del tipo elegido.
     *
     * @return array{ok: bool, server_id: ?int, error?: string, type: string}
     */
    public function placeBuyer(int $tenantId, ?MediaUser $buyer, string $requestedType, int $newSeatsForBuyer = 0): array
    {
        $type = $this->normalizeType($requestedType);
        $keep = 0;
        $buyerServerId = (int) ($buyer?->server_id ?? 0);
        if ($buyerServerId > 0 && $this->typeOfServerId($buyerServerId) === $type) {
            $keep = $buyerServerId;
        }
        $seats = $keep > 0 ? 0 : max(0, $newSeatsForBuyer);
        $picked = $this->place($tenantId, $type, $keep, $seats);

        return $picked + ['type' => $type];
    }

    public function ensureQuotaColumn(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT 1 AS ok FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servers' AND COLUMN_NAME = 'user_quota' LIMIT 1"
            );
            if ($row === null) {
                (new Updater())->runMigrations();
            }
        } catch (\Throwable) {
        }
        $ensured = true;
    }

    public function setQuota(Server $server, mixed $raw): void
    {
        $this->ensureQuotaColumn();
        $n = ($raw === null || $raw === '') ? 0 : max(0, min(100000, (int) $raw));
        $server->user_quota = $n;
        $server->save();
    }
}
