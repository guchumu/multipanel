<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tienda del portal: se compra tiempo (meses), no planes.
 *
 * Cada usuario incluye 2 pantallas en la misma casa.
 * Usuarios extra y pantallas extra: 40 % de descuento.
 */
final class PortalShopService
{
    public const INCLUDED_STREAMS = 2;
    public const EXTRA_DISCOUNT = 0.40;
    public const MAX_USERS = 6;
    public const MAX_EXTRA_STREAMS = 4;

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
                'label' => $this->monthLabel($months),
                'price' => round($price, 2),
            ];
        }

        if ($mapped === []) {
            $mapped = [
                1 => ['months' => 1, 'days' => 30, 'label' => '1 mes', 'price' => 15.0],
                3 => ['months' => 3, 'days' => 90, 'label' => '3 meses', 'price' => 40.0],
                6 => ['months' => 6, 'days' => 180, 'label' => '6 meses', 'price' => 70.0],
                12 => ['months' => 12, 'days' => 365, 'label' => '1 año', 'price' => 130.0],
            ];
        }

        ksort($mapped);

        return array_values($mapped);
    }

    /**
     * @param list<string> $emails
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
     *   pack_price: float,
     *   extra_users_price: float,
     *   extra_streams_price: float,
     *   extra_user_unit: float,
     *   extra_stream_unit: float,
     *   discount: float,
     *   total: float
     * }
     */
    public function quote(int $tenantId, int $months, int $users, int $extraStreams, array $emails = []): array
    {
        $option = $this->optionForMonths($tenantId, $months);
        if ($option === null) {
            return $this->emptyQuote($months, 'Elige 1, 3, 6 o 12 meses.');
        }

        $users = max(1, min(self::MAX_USERS, $users));
        $extraStreams = max(0, min(self::MAX_EXTRA_STREAMS, $extraStreams));
        $emails = $this->normalizeEmails($emails, $users);

        $pack = (float) $option['price'];
        $payFactor = 1 - self::EXTRA_DISCOUNT;
        $extraUserUnit = round($pack * $payFactor, 2);
        $extraStreamUnit = round(($pack / 2) * $payFactor, 2);
        $extraUsers = $users - 1;
        $extraUsersPrice = round($extraUsers * $extraUserUnit, 2);
        $extraStreamsPrice = round($extraStreams * $extraStreamUnit, 2);
        $included = $users * self::INCLUDED_STREAMS;
        $total = round($pack + $extraUsersPrice + $extraStreamsPrice, 2);

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
            'pack_price' => $pack,
            'extra_users_price' => $extraUsersPrice,
            'extra_streams_price' => $extraStreamsPrice,
            'extra_user_unit' => $extraUserUnit,
            'extra_stream_unit' => $extraStreamUnit,
            'discount' => self::EXTRA_DISCOUNT,
            'total' => $total,
        ];
    }

    /**
     * @param list<string> $emails
     * @return list<string>
     */
    public function normalizeEmails(array $emails, int $users): array
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

        return array_slice($clean, 0, max(1, $users));
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
            'pack_price' => 0.0,
            'extra_users_price' => 0.0,
            'extra_streams_price' => 0.0,
            'extra_user_unit' => 0.0,
            'extra_stream_unit' => 0.0,
            'discount' => self::EXTRA_DISCOUNT,
            'total' => 0.0,
        ];
    }
}
