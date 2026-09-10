<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Maps subscription period keys to expiration dates.
 */
final class SubscriptionPeriod
{
    /** Días de los botones rápidos (+7 / +15 / +30 / +90 / +365). */
    public const QUICK_RENEW_DAYS = [7, 15, 30, 90, 365];

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

    public static function addDaysToExpires(?string $currentExpires, int $days): string
    {
        $days = max(1, min(3650, $days));
        $tz = self::appTimezone();
        $today = new \DateTimeImmutable('today', $tz);
        $parsed = self::parseDate($currentExpires);

        $base = $today;
        if ($parsed !== null) {
            $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $parsed, $tz);
            if ($from instanceof \DateTimeImmutable && $from >= $today) {
                $base = $from;
            }
        }

        return $base->modify('+' . $days . ' days')->format('Y-m-d 23:59:59');
    }

    /**
     * Vista previa YYYY-MM-DD de addDaysToExpires (para confirmaciones en UI).
     */
    public static function previewAddDays(?string $currentExpires, int $days): string
    {
        return substr(self::addDaysToExpires($currentExpires, $days), 0, 10);
    }

    /** Fecha de expiración a N días desde hoy (fin del día). */
    public static function daysToExpiresAt(int $days): string
    {
        $days = max(1, min(3650, $days));

        return (new \DateTimeImmutable('today', self::appTimezone()))
            ->modify('+' . $days . ' days')
            ->format('Y-m-d 23:59:59');
    }

    private static function appTimezone(): \DateTimeZone
    {
        $name = 'Europe/Madrid';
        if (function_exists('config')) {
            try {
                $configured = (string) config('app.timezone', 'Europe/Madrid');
                if ($configured !== '') {
                    $name = $configured;
                }
            } catch (\Throwable) {
            }
        }
        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return new \DateTimeZone('Europe/Madrid');
        }
    }

    /** Normaliza entrada HTML date (Y-m-d) o valor DB a fin de día almacenable. */
    public static function normalizeStorage(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }
        $parsed = self::parseDate($input);
        if ($parsed === null) {
            return null;
        }

        return $parsed . ' 23:59:59';
    }

    /** Valor seguro para `<input type="date">` (corrige años 0027 → 2027, etc.). */
    public static function formatForInput(?string $expires): string
    {
        return self::parseDate($expires) ?? '';
    }

    /** Etiqueta legible YYYY-MM-DD para listados. */
    public static function formatForDisplay(?string $expires): string
    {
        $parsed = self::parseDate($expires);

        return $parsed ?? '—';
    }

    /**
     * Parsea fechas de expiración tolerando basura legacy (0027, 27-01-09, etc.).
     */
    public static function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim($value);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }

        if (preg_match('/^(\d{1,4})-(\d{1,2})-(\d{1,2})/', $raw, $m)) {
            $y = self::normalizeYear((int) $m[1]);
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if (self::isValidYmd($y, $mo, $d)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $raw, $m)) {
            $y = self::normalizeYear((int) $m[3]);
            $mo = (int) $m[2];
            $d = (int) $m[1];
            if (self::isValidYmd($y, $mo, $d)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        try {
            $dt = new \DateTimeImmutable(substr($raw, 0, 19));
            $y = self::normalizeYear((int) $dt->format('Y'));
            $mo = (int) $dt->format('n');
            $d = (int) $dt->format('j');
            if (self::isValidYmd($y, $mo, $d)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function normalizeYear(int $year): int
    {
        if ($year >= 100 && $year < 1900) {
            return 2000 + ($year % 100);
        }
        if ($year >= 0 && $year < 100) {
            return 2000 + $year;
        }

        return $year;
    }

    private static function isValidYmd(int $year, int $month, int $day): bool
    {
        if ($year < 2000 || $year > 2099 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return false;
        }

        return checkdate($month, $day, $year);
    }
}
