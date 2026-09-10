<?php

declare(strict_types=1);

use App\Services\SubscriptionPeriod;
use PHPUnit\Framework\TestCase;

final class SubscriptionPeriodTest extends TestCase
{
    private function appTz(): DateTimeZone
    {
        $name = 'Europe/Madrid';
        if (function_exists('config')) {
            $configured = (string) config('app.timezone', 'Europe/Madrid');
            if ($configured !== '') {
                $name = $configured;
            }
        }

        return new DateTimeZone($name);
    }

    public function testDaysToExpiresAt(): void
    {
        $expires = SubscriptionPeriod::daysToExpiresAt(30);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 23:59:59$/', $expires);

        $expected = (new DateTimeImmutable('today', $this->appTz()))->modify('+30 days')->format('Y-m-d 23:59:59');
        $this->assertSame($expected, $expires);
    }

    public function testParseLegacyTwoDigitYear(): void
    {
        $this->assertSame('2027-01-09', SubscriptionPeriod::parseDate('0027-01-09'));
        $this->assertSame('2027-01-09', SubscriptionPeriod::parseDate('27-01-09 23:59:59'));
    }

    public function testFormatForInputFixesBadYear(): void
    {
        $this->assertSame('2027-01-09', SubscriptionPeriod::formatForInput('0027-01-09 23:59:59'));
    }

    public function testAddDaysToExpiresFromFutureDate(): void
    {
        $result = SubscriptionPeriod::addDaysToExpires('2090-11-28 23:59:59', 90);
        $this->assertSame('2091-02-26 23:59:59', $result);
        $this->assertSame('2091-02-26', SubscriptionPeriod::previewAddDays('2090-11-28', 90));
    }

    public function testAddDaysToExpiresFromPastUsesToday(): void
    {
        $expected = (new DateTimeImmutable('today', $this->appTz()))->modify('+30 days')->format('Y-m-d 23:59:59');
        $this->assertSame($expected, SubscriptionPeriod::addDaysToExpires('2020-01-01', 30));
        $this->assertSame($expected, SubscriptionPeriod::addDaysToExpires(null, 30));
    }

    /**
     * @dataProvider quickRenewDaysProvider
     */
    public function testQuickRenewDaysExact(int $days): void
    {
        $base = '2090-06-01';
        $expected = (new DateTimeImmutable($base))->modify('+' . $days . ' days')->format('Y-m-d 23:59:59');
        $this->assertSame($expected, SubscriptionPeriod::addDaysToExpires($base, $days));
    }

    public static function quickRenewDaysProvider(): array
    {
        return array_map(static fn (int $d) => [$d], SubscriptionPeriod::QUICK_RENEW_DAYS);
    }
}
