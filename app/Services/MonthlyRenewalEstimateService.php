<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use DateInterval;
use DateTimeImmutable;

/**
 * Estimación mensual de caducidades / renovaciones previstas por servidor.
 *
 * Caducidades = media_users con expires_at en ese mes.
 * Renovaciones estimadas = mismo cohort (se asume que renuevan).
 * Importe estimado = suscripción activa, último pago, o preset mensual por defecto.
 */
final class MonthlyRenewalEstimateService
{
    private const MONTH_NAMES_ES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function __construct(
        private BillingSettingsService $billingSettings = new BillingSettingsService(),
    ) {
    }

    /**
     * @return array{
     *   months: list<array{
     *     key: string,
     *     label: string,
     *     is_current: bool,
     *     caducidades: int,
     *     renovaciones: int,
     *     importe: float,
     *     by_server: list<array{server_id: int|null, server_name: string, caducidades: int, renovaciones: int, importe: float}>
     *   }>,
     *   servers: list<array{id: int|null, name: string}>,
     *   totals: array{caducidades: int, renovaciones: int, importe: float},
     *   default_price: float,
     *   months_ahead: int
     * }
     */
    public function estimate(int $tenantId, int $monthsAhead = 12, ?int $serverId = null): array
    {
        $monthsAhead = max(0, min(24, $monthsAhead));
        $defaultPrice = $this->defaultMonthlyPrice($tenantId);
        $skeleton = $this->buildMonthSkeleton($monthsAhead);

        $from = $skeleton[0]['key'] . '-01 00:00:00';
        $last = $skeleton[array_key_last($skeleton)];
        $toExclusive = (new DateTimeImmutable($last['key'] . '-01'))
            ->add(new DateInterval('P1M'))
            ->format('Y-m-d H:i:s');

        $rows = $this->fetchGroupedRows($tenantId, $from, $toExclusive, $defaultPrice, $serverId);
        $serverIndex = [];

        foreach ($rows as $row) {
            $key = (string) $row['month_key'];
            if (!isset($skeleton[$key])) {
                continue;
            }

            $sid = $row['server_id'] !== null ? (int) $row['server_id'] : null;
            $sname = trim((string) ($row['server_name'] ?? '')) !== ''
                ? (string) $row['server_name']
                : 'Sin servidor';
            $count = (int) $row['caducidades'];
            $importe = round((float) $row['importe'], 2);

            $skeleton[$key]['caducidades'] += $count;
            $skeleton[$key]['renovaciones'] += $count;
            $skeleton[$key]['importe'] = round($skeleton[$key]['importe'] + $importe, 2);
            $skeleton[$key]['by_server'][] = [
                'server_id' => $sid,
                'server_name' => $sname,
                'caducidades' => $count,
                'renovaciones' => $count,
                'importe' => $importe,
            ];

            $serverKey = $sid === null ? 'null' : (string) $sid;
            if (!isset($serverIndex[$serverKey])) {
                $serverIndex[$serverKey] = ['id' => $sid, 'name' => $sname];
            }
        }

        $months = array_values(array_map(static function (array $m): array {
            usort($m['by_server'], static fn (array $a, array $b): int => strcasecmp($a['server_name'], $b['server_name']));

            return $m;
        }, $skeleton));

        usort($serverIndex, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        $totals = [
            'caducidades' => array_sum(array_column($months, 'caducidades')),
            'renovaciones' => array_sum(array_column($months, 'renovaciones')),
            'importe' => round(array_sum(array_column($months, 'importe')), 2),
        ];

        return [
            'months' => $months,
            'servers' => array_values($serverIndex),
            'totals' => $totals,
            'default_price' => $defaultPrice,
            'months_ahead' => $monthsAhead,
        ];
    }

    /**
     * Resumen compacto para el dashboard: caducidades este mes y el siguiente.
     *
     * @return array{
     *   this_month: array{key: string, label: string, caducidades: int},
     *   next_month: array{key: string, label: string, caducidades: int}
     * }
     */
    public function upcomingTwoMonths(int $tenantId): array
    {
        $estimate = $this->estimate($tenantId, 1, null);
        $months = $estimate['months'];
        $thisMonth = $months[0] ?? [
            'key' => (new DateTimeImmutable('first day of this month'))->format('Y-m'),
            'label' => '',
            'caducidades' => 0,
        ];
        $nextMonth = $months[1] ?? [
            'key' => (new DateTimeImmutable('first day of next month'))->format('Y-m'),
            'label' => '',
            'caducidades' => 0,
        ];

        return [
            'this_month' => [
                'key' => (string) $thisMonth['key'],
                'label' => (string) $thisMonth['label'],
                'caducidades' => (int) $thisMonth['caducidades'],
            ],
            'next_month' => [
                'key' => (string) $nextMonth['key'],
                'label' => (string) $nextMonth['label'],
                'caducidades' => (int) $nextMonth['caducidades'],
            ],
        ];
    }

    public function defaultMonthlyPrice(int $tenantId): float
    {
        $presets = $this->billingSettings->getRenewalPresets($tenantId);
        $best = null;
        foreach ($presets as $preset) {
            $days = (int) ($preset['days'] ?? 0);
            $price = (float) ($preset['price'] ?? 0);
            if ($days <= 0 || $price <= 0) {
                continue;
            }
            $distance = abs($days - 30);
            if ($best === null || $distance < $best['distance']) {
                $best = ['distance' => $distance, 'price' => $price];
            }
        }

        return $best['price'] ?? 15.0;
    }

    /**
     * @return array<string, array{
     *   key: string,
     *   label: string,
     *   is_current: bool,
     *   caducidades: int,
     *   renovaciones: int,
     *   importe: float,
     *   by_server: list<array{server_id: int|null, server_name: string, caducidades: int, renovaciones: int, importe: float}>
     * }>
     */
    private function buildMonthSkeleton(int $monthsAhead): array
    {
        $now = new DateTimeImmutable('first day of this month 00:00:00');
        $currentKey = $now->format('Y-m');
        $out = [];

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $month = $now->add(new DateInterval('P' . $i . 'M'));
            $key = $month->format('Y-m');
            $num = (int) $month->format('n');
            $out[$key] = [
                'key' => $key,
                'label' => (self::MONTH_NAMES_ES[$num] ?? $month->format('F')) . ' ' . $month->format('Y'),
                'is_current' => $key === $currentKey,
                'caducidades' => 0,
                'renovaciones' => 0,
                'importe' => 0.0,
                'by_server' => [],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{month_key: string, server_id: int|null, server_name: string|null, caducidades: int|string, importe: float|string}>
     */
    private function fetchGroupedRows(
        int $tenantId,
        string $from,
        string $toExclusive,
        float $defaultPrice,
        ?int $serverId,
    ): array {
        $db = Database::getInstance();

        $paymentJoin = '';
        if ($this->tableExists('payments_history')) {
            $paymentJoin = 'LEFT JOIN (
                SELECT ph.media_user_id, ph.amount
                FROM payments_history ph
                INNER JOIN (
                    SELECT media_user_id, MAX(id) AS max_id
                    FROM payments_history
                    WHERE media_user_id IS NOT NULL
                    GROUP BY media_user_id
                ) latest ON latest.max_id = ph.id
            ) pay ON pay.media_user_id = mu.id';
        }

        $amountExpr = $paymentJoin !== ''
            ? 'COALESCE(sub.amount, pay.amount, ?)'
            : 'COALESCE(sub.amount, ?)';

        // Orden de placeholders: defaultPrice (SUM), tenant subquery, tenant WHERE, rango fechas.
        $sql = "SELECT DATE_FORMAT(mu.expires_at, '%Y-%m') AS month_key,
                       mu.server_id,
                       s.name AS server_name,
                       COUNT(*) AS caducidades,
                       COALESCE(SUM({$amountExpr}), 0) AS importe
                FROM media_users mu
                LEFT JOIN servers s ON s.id = mu.server_id AND s.deleted_at IS NULL
                LEFT JOIN (
                    SELECT media_user_id, MAX(amount) AS amount
                    FROM subscriptions
                    WHERE tenant_id = ?
                      AND media_user_id IS NOT NULL
                      AND status IN ('active', 'trialing', 'past_due')
                    GROUP BY media_user_id
                ) sub ON sub.media_user_id = mu.id
                {$paymentJoin}
                WHERE mu.tenant_id = ?
                  AND mu.deleted_at IS NULL
                  AND mu.expires_at IS NOT NULL
                  AND mu.status IN ('active', 'invited', 'suspended', 'expired')
                  AND mu.expires_at >= ?
                  AND mu.expires_at < ?";

        $params = [$defaultPrice, $tenantId, $tenantId, $from, $toExclusive];

        if ($serverId !== null) {
            $sql .= ' AND mu.server_id = ?';
            $params[] = $serverId;
        }

        $sql .= ' GROUP BY DATE_FORMAT(mu.expires_at, \'%Y-%m\'), mu.server_id, s.name ORDER BY month_key ASC, s.name ASC';

        try {
            return $db->fetchAll($sql, $params);
        } catch (\Throwable) {
            // Si falta subscriptions u otra tabla opcional, caemos a consulta mínima.
            return $this->fetchGroupedRowsFallback($tenantId, $from, $toExclusive, $defaultPrice, $serverId);
        }
    }

    /**
     * @return list<array{month_key: string, server_id: int|null, server_name: string|null, caducidades: int|string, importe: float|string}>
     */
    private function fetchGroupedRowsFallback(
        int $tenantId,
        string $from,
        string $toExclusive,
        float $defaultPrice,
        ?int $serverId,
    ): array {
        $params = [$defaultPrice, $tenantId, $from, $toExclusive];
        $sql = "SELECT DATE_FORMAT(mu.expires_at, '%Y-%m') AS month_key,
                       mu.server_id,
                       s.name AS server_name,
                       COUNT(*) AS caducidades,
                       COALESCE(SUM(?), 0) AS importe
                FROM media_users mu
                LEFT JOIN servers s ON s.id = mu.server_id AND s.deleted_at IS NULL
                WHERE mu.tenant_id = ?
                  AND mu.deleted_at IS NULL
                  AND mu.expires_at IS NOT NULL
                  AND mu.status IN ('active', 'invited', 'suspended', 'expired')
                  AND mu.expires_at >= ?
                  AND mu.expires_at < ?";

        if ($serverId !== null) {
            $sql .= ' AND mu.server_id = ?';
            $params[] = $serverId;
        }

        $sql .= ' GROUP BY DATE_FORMAT(mu.expires_at, \'%Y-%m\'), mu.server_id, s.name ORDER BY month_key ASC, s.name ASC';

        return Database::getInstance()->fetchAll($sql, $params);
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT 1 AS ok FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
                [$table]
            );
            $cache[$table] = $row !== null;
        } catch (\Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}
