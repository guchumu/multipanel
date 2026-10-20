<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LegacyRegistrationService;
use Tests\TestCase;

final class LegacyRegistrationServicioCodeTest extends TestCase
{
    /** @dataProvider codeProvider */
    public function test_parse_servicio_code(string $service, ?int $expected): void
    {
        $serviceObj = new LegacyRegistrationService();
        $method = new \ReflectionMethod(LegacyRegistrationService::class, 'parseServicioCode');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($serviceObj, $service));
    }

    /** @return array<string, array{0: string, 1: ?int}> */
    public static function codeProvider(): array
    {
        return [
            'server10' => ['1', 1],
            'nucbox' => ['5', 5],
            'plex text' => ['plex', null],
            'empty' => ['', null],
            'spaces' => [' 1 ', 1],
        ];
    }
}
