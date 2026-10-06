<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LegacyRegistrationService;
use Tests\TestCase;

final class LegacyRegistrationMonthsToDaysTest extends TestCase
{
    /** @dataProvider monthsProvider */
    public function test_months_to_days(float|string $months, int $expectedDays): void
    {
        $service = new LegacyRegistrationService();
        $method = new \ReflectionMethod(LegacyRegistrationService::class, 'monthsToDays');
        $method->setAccessible(true);

        $this->assertSame($expectedDays, $method->invoke($service, $months));
    }

    /** @return array<string, array{0: float|string, 1: int}> */
    public static function monthsProvider(): array
    {
        return [
            'semana 0.25' => [0.25, 7],
            'semana string' => ['0.25', 7],
            'semana coma EU' => ['0,25', 7],
            'semana 0.24' => [0.24, 7],
            '1 mes' => [1, 30],
            '3 meses' => [3, 90],
            '6 meses' => [6, 180],
            '12 meses año natural' => [12, 365],
            '12 string' => ['12', 365],
        ];
    }
}
