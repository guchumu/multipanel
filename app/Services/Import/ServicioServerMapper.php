<?php

declare(strict_types=1);

namespace App\Services\Import;

use Core\Database;

/**
 * Maps legacy import `servicio` codes to tenant media servers by name.
 */
final class ServicioServerMapper
{
    /** @return list<int> */
    public static function allowedCodes(): array
    {
        $allowed = config('import_servicio.allowed', [1, 5]);
        if (!is_array($allowed)) {
            return [1, 5];
        }

        return array_values(array_map('intval', $allowed));
    }

    public static function isAllowed(?int $servicio): bool
    {
        if ($servicio === null) {
            return false;
        }

        return in_array($servicio, self::allowedCodes(), true);
    }

    /** @return array<int, list<string>> */
    public static function nameNeedlesByServicio(): array
    {
        $map = config('import_servicio.map', []);
        if (!is_array($map)) {
            return [1 => ['server10', 'server 10'], 5 => ['nucbox', 'nuc box']];
        }

        $out = [];
        foreach ($map as $code => $needles) {
            $list = is_array($needles) ? $needles : [$needles];
            $out[(int) $code] = array_values(array_filter(array_map(
                static fn ($n) => mb_strtolower(trim((string) $n)),
                $list
            ), static fn (string $n) => $n !== ''));
        }

        return $out;
    }

    public static function label(int $servicio): string
    {
        $labels = config('import_servicio.labels', []);
        if (is_array($labels) && isset($labels[$servicio])) {
            return (string) $labels[$servicio];
        }

        return 'servicio ' . $servicio;
    }

    /**
     * Infer servicio code from a legacy/server display name (reverse of map).
     */
    public static function servicioFromServerName(?string $serverName): ?int
    {
        $name = mb_strtolower(trim((string) $serverName));
        if ($name === '') {
            return null;
        }

        foreach (self::nameNeedlesByServicio() as $code => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($name, $needle)) {
                    return (int) $code;
                }
            }
        }

        return null;
    }

    /**
     * Resolve tenant server id for a servicio code by name needles.
     *
     * @return array{server_id: ?int, server_name: ?string, matched_needle: ?string}
     */
    public function resolveTenantServer(int $tenantId, int $servicio): array
    {
        $needles = self::nameNeedlesByServicio()[$servicio] ?? [];
        if ($needles === []) {
            return ['server_id' => null, 'server_name' => null, 'matched_needle' => null];
        }

        $servers = Database::getInstance()->fetchAll(
            'SELECT id, name FROM servers WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, id ASC',
            [$tenantId]
        );

        foreach ($servers as $server) {
            $name = mb_strtolower(trim((string) ($server['name'] ?? '')));
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($name, $needle)) {
                    return [
                        'server_id' => (int) $server['id'],
                        'server_name' => (string) $server['name'],
                        'matched_needle' => $needle,
                    ];
                }
            }
        }

        return ['server_id' => null, 'server_name' => null, 'matched_needle' => null];
    }

    /**
     * Build email → latest payments_history.service code from a SQL dump.
     *
     * @return array<string, int> lowercase email => servicio int
     */
    public static function paymentServicioByEmail(string $sql): array
    {
        $rows = SqlInsertParser::extractTable($sql, 'payments_history');
        $map = [];

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $raw = $row['service'] ?? $row['servicio'] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }

            if (!is_numeric($raw)) {
                continue;
            }

            // Última fila del dump gana (orden de insert ≈ cronológico).
            $map[$email] = (int) $raw;
        }

        return $map;
    }

    /**
     * Resolve servicio for a legacy users row.
     *
     * @param array<string, mixed> $legacy
     * @param array<string, int>   $paymentByEmail
     * @param array<int, string>   $legacyServerNames id => name
     */
    public static function resolveRowServicio(array $legacy, array $paymentByEmail, array $legacyServerNames): ?int
    {
        foreach (['servicio', 'service'] as $key) {
            if (!array_key_exists($key, $legacy)) {
                continue;
            }
            $raw = $legacy[$key];
            if ($raw === null || $raw === '') {
                continue;
            }
            if (is_numeric($raw)) {
                return (int) $raw;
            }
        }

        $email = strtolower(trim((string) ($legacy['email'] ?? '')));
        if ($email !== '' && isset($paymentByEmail[$email])) {
            return $paymentByEmail[$email];
        }

        $legacyServerId = (int) ($legacy['server_id'] ?? 0);
        $serverName = $legacyServerNames[$legacyServerId] ?? null;

        return self::servicioFromServerName($serverName);
    }
}
