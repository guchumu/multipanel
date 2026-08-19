<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tienda del portal: se compra tiempo (meses) según los presets de Facturación.
 *
 * Cada cuenta individual incluye 2 visionados en el mismo hogar.
 * Cuenta extra y visionado extra: precios de Ajustes → Facturación.
 */
final class PortalShopService
{
    public const INCLUDED_STREAMS = 2;

    public const MAX_ACCOUNTS = 6;

    public const MAX_STREAMS_PER_ACCOUNT = 6;

    public const DEFAULT_EXTRA_ACCOUNT = 50.0;

    public const DEFAULT_EXTRA_STREAM_MONTH = 4.0;

    public function __construct(
        private BillingSettingsService $billing = new BillingSettingsService(),
    ) {
    }

    /**
     * @return list<array{months: int, days: int, label: string, price: float}>
     */
    public function monthOptions(int $tenantId): array
    {
        $presets = $this->billing->getRenewalPresets($tenantId);
        $mapped = [];
        foreach ($presets as $preset) {
            $days = (int) ($preset['days'] ?? 0);
            $price = (float) ($preset['price'] ?? 0);
            if ($days <= 0 || $price <= 0) {
                continue;
            }
            $months = $this->daysToMonths($days);
            $mapped[$months] = [
                'months' => $months,
                'days' => $days,
                'label' => (string) ($preset['label'] ?? $this->monthLabel($months)),
                'price' => round($price, 2),
            ];
        }

        ksort($mapped);

        return array_values($mapped);
    }

    public function extraAccountPrice(int $tenantId): float
    {
        return $this->billing->getExtraAccountPrice($tenantId);
    }

    public function extraStreamMonthlyPrice(int $tenantId): float
    {
        return $this->billing->getExtraStreamMonthlyPrice($tenantId);
    }

    /**
     * pack + (cuentas-1)×cuenta extra + visionados extra × €/mes × meses del preset.
     *
     * @return array{extra_users: int, extra_users_price: float, extra_streams_price: float, total: float}
     */
    public static function priceExtras(
        float $packPrice,
        int $periodMonths,
        int $users,
        int $extraStreams,
        float $extraAccountUnit,
        float $extraStreamMonth,
    ): array {
        $extraUsers = max(0, $users - 1);
        $months = max(1, $periodMonths);
        $extraUsersPrice = round($extraUsers * $extraAccountUnit, 2);
        $extraStreamsPrice = round(max(0, $extraStreams) * $extraStreamMonth * $months, 2);

        return [
            'extra_users' => $extraUsers,
            'extra_users_price' => $extraUsersPrice,
            'extra_streams_price' => $extraStreamsPrice,
            'total' => round($packPrice + $extraUsersPrice + $extraStreamsPrice, 2),
        ];
    }

    /**
     * @param list<string> $emails
     * @param list<int|string> $streams
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   months: int,
     *   days: int,
     *   label: string,
     *   users: int,
     *   extra_users: int,
     *   extra_streams: int,
     *   included_streams: int,
     *   total_streams: int,
     *   emails: list<string>,
     *   streams: list<int>,
     *   pack_price: float,
     *   extra_users_price: float,
     *   extra_streams_price: float,
     *   extra_user_unit: float,
     *   extra_stream_unit: float,
     *   extra_stream_month: float,
     *   total: float
     * }
     */
    public function quote(int $tenantId, int $months, array $emails = [], array $streams = []): array
    {
        $option = $this->optionForMonths($tenantId, $months);
        if ($option === null) {
            return $this->emptyQuote($months, 'Elige una duración de las que hay en el panel.');
        }

        $emailsIn = $emails;
        $pairs = [];
        $seen = [];
        foreach ($emailsIn as $i => $raw) {
            $email = mb_strtolower(trim((string) $raw));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $n = (int) ($streams[$i] ?? self::INCLUDED_STREAMS);
            $pairs[] = [
                $email,
                max(self::INCLUDED_STREAMS, min(self::MAX_STREAMS_PER_ACCOUNT, $n)),
            ];
            if (count($pairs) >= self::MAX_ACCOUNTS) {
                break;
            }
        }
        if ($pairs === []) {
            return $this->emptyQuote($months, 'Escribe el email de cada cuenta individual.');
        }

        $emails = array_column($pairs, 0);
        $streamList = array_map('intval', array_column($pairs, 1));

        $users = count($emails);
        $extraUsers = max(0, $users - 1);
        $extraStreams = 0;
        foreach ($streamList as $n) {
            $extraStreams += max(0, $n - self::INCLUDED_STREAMS);
        }

        $pack = (float) $option['price'];
        $extraUserUnit = $this->extraAccountPrice($tenantId);
        $streamMonth = $this->extraStreamMonthlyPrice($tenantId);
        $periodMonths = max(1, (int) $option['months']);
        $priced = self::priceExtras($pack, $periodMonths, $users, $extraStreams, $extraUserUnit, $streamMonth);
        $extraUsersPrice = $priced['extra_users_price'];
        $extraStreamsPrice = $priced['extra_streams_price'];
        $included = $users * self::INCLUDED_STREAMS;
        $total = $priced['total'];

        return [
            'ok' => true,
            'months' => (int) $option['months'],
            'days' => (int) $option['days'],
            'label' => (string) $option['label'],
            'users' => $users,
            'extra_users' => $extraUsers,
            'extra_streams' => $extraStreams,
            'included_streams' => $included,
            'total_streams' => $included + $extraStreams,
            'emails' => $emails,
            'streams' => $streamList,
            'pack_price' => $pack,
            'extra_users_price' => $extraUsersPrice,
            'extra_streams_price' => $extraStreamsPrice,
            'extra_user_unit' => $extraUserUnit,
            'extra_stream_unit' => round($streamMonth * $periodMonths, 2),
            'extra_stream_month' => $streamMonth,
            'total' => $total,
        ];
    }

    /**
     * @param list<string> $emails
     * @return list<string>
     */
    public function normalizeEmails(array $emails, int $max): array
    {
        $clean = [];
        foreach ($emails as $email) {
            $email = mb_strtolower(trim((string) $email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!in_array($email, $clean, true)) {
                $clean[] = $email;
            }
        }

        return array_slice($clean, 0, max(1, $max));
    }

    /**
     * @return array{months: int, days: int, label: string, price: float}|null
     */
    public function optionForMonths(int $tenantId, int $months): ?array
    {
        foreach ($this->monthOptions($tenantId) as $option) {
            if ((int) $option['months'] === $months) {
                return $option;
            }
        }

        return null;
    }

    private function daysToMonths(int $days): int
    {
        return match (true) {
            $days >= 330 => 12,
            $days >= 160 => 6,
            $days >= 75 => 3,
            default => 1,
        };
    }

    private function monthLabel(int $months): string
    {
        return match ($months) {
            1 => '1 mes',
            12 => '1 año',
            default => $months . ' meses',
        };
    }

    /** @return array<string, mixed> */
    private function emptyQuote(int $months, string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'months' => $months,
            'days' => 0,
            'label' => '',
            'users' => 1,
            'extra_users' => 0,
            'extra_streams' => 0,
            'included_streams' => self::INCLUDED_STREAMS,
            'total_streams' => self::INCLUDED_STREAMS,
            'emails' => [],
            'streams' => [self::INCLUDED_STREAMS],
            'pack_price' => 0.0,
            'extra_users_price' => 0.0,
            'extra_streams_price' => 0.0,
            'extra_user_unit' => 0.0,
            'extra_stream_unit' => 0.0,
            'extra_stream_month' => self::DEFAULT_EXTRA_STREAM_MONTH,
            'total' => 0.0,
        ];
    }
}
