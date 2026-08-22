<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeoIpService;
use App\Services\StatsService;
use Tests\TestCase;

final class StatsPeriodTest extends TestCase
{
    public function testResolvePeriodDefaultIsThirtyDays(): void
    {
        $period = (new StatsService())->resolvePeriod('30d');
        $from = new \DateTimeImmutable($period['from_date']);
        $to = new \DateTimeImmutable($period['to_date']);
        $this->assertSame('30d', $period['preset']);
        $this->assertSame(29, (int) $from->diff($to)->days);
    }

    public function testResolvePeriodSevenDays(): void
    {
        $period = (new StatsService())->resolvePeriod('7d');
        $from = new \DateTimeImmutable($period['from_date']);
        $to = new \DateTimeImmutable($period['to_date']);
        $this->assertSame('7d', $period['preset']);
        $this->assertSame(6, (int) $from->diff($to)->days);
    }

    public function testCustomRangeSwapsIfInverted(): void
    {
        $period = (new StatsService())->resolvePeriod('custom', '2026-08-20', '2026-08-10');
        $this->assertSame('2026-08-10', $period['from_date']);
        $this->assertSame('2026-08-20', $period['to_date']);
    }

    public function testNormalizeMediaType(): void
    {
        $svc = new StatsService();
        $this->assertSame('movie', $svc->normalizeMediaType('Movie'));
        $this->assertSame('series', $svc->normalizeMediaType('series'));
        $this->assertSame('', $svc->normalizeMediaType('track'));
    }

    public function testPrivateIpHasNoCountry(): void
    {
        $geo = new GeoIpService();
        $this->assertNull($geo->countryCode('192.168.1.10'));
        $this->assertNull($geo->countryCode('10.0.0.4'));
        $this->assertNull($geo->countryCode('127.0.0.1'));
    }

    public function testCountryNameSpanish(): void
    {
        $this->assertSame('España', GeoIpService::countryName('es'));
        $this->assertSame('Estados Unidos', GeoIpService::countryName('US'));
    }
}
