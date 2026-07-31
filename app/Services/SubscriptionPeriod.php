<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Maps subscription period keys to expiration dates.
 */
final class SubscriptionPeriod
{
    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            '1w' => '1 semana',
            '2w' => '2 semanas',
            '1m' => '1 mes',
            '3m' => '3 meses',
            '6m' => '6 meses',
            '1y' => '1 año',
            'forever' => 'Indefinido',
        ];
    }

    public static function toExpiresAt(string $period): ?string
    {
        $now = new \DateTimeImmutable('now');

        return match ($period) {
            '1w' => $now->modify('+1 week')->format('Y-m-d 23:59:59'),
            '2w' => $now->modify('+2 weeks')->format('Y-m-d 23:59:59'),
            '1m' => $now->modify('+1 month')->format('Y-m-d 23:59:59'),
            '3m' => $now->modify('+3 months')->format('Y-m-d 23:59:59'),
            '6m' => $now->modify('+6 months')->format('Y-m-d 23:59:59'),
            '1y' => $now->modify('+1 year')->format('Y-m-d 23:59:59'),
            'forever' => null,
            default => $now->modify('+1 month')->format('Y-m-d 23:59:59'),
        };
    }
}
