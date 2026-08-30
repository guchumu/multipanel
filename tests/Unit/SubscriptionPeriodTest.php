<?php

declare(strict_types=1);

use App\Services\SubscriptionPeriod;
use PHPUnit\Framework\TestCase;

final class SubscriptionPeriodTest extends TestCase
{
    public function testDaysToExpiresAt(): void
    {
        $expires = SubscriptionPeriod::daysToExpiresAt(30);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 23:59:59$/', $expires);

        $expected = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d 23:59:59');
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
}
