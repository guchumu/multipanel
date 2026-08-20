<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Media\SessionClientIp;
use App\Services\MediaUserEndpointService;
use Tests\TestCase;

final class MediaUserEndpointServiceTest extends TestCase
{
    public function testPrivateLanIsHome(): void
    {
        $this->assertTrue(SessionClientIp::isPrivate('192.168.1.20'));
        $this->assertFalse(SessionClientIp::isPrivate('8.8.8.8'));
        $this->assertSame('LAN', SessionClientIp::classifyLocation('lan', '1.2.3.4'));
        $this->assertSame('WAN', SessionClientIp::classifyLocation('WAN', '8.8.8.8'));
        $this->assertSame('LAN', SessionClientIp::classifyLocation(null, '10.0.0.5'));
    }

    public function testDeviceKeyIsStable(): void
    {
        $a = MediaUserEndpointService::deviceKey('1.2.3.4', 'abc', 'TV', 'Plex', 'tvOS');
        $b = MediaUserEndpointService::deviceKey('1.2.3.4', 'abc', 'TV', 'Plex', 'tvOS');
        $c = MediaUserEndpointService::deviceKey('1.2.3.4', 'xyz', 'TV', 'Plex', 'tvOS');

        $this->assertSame(40, strlen($a));
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function testLanLocationInfersHome(): void
    {
        $this->assertSame('home', MediaUserEndpointService::inferKindFromLocation('LAN'));
        $this->assertSame('unknown', MediaUserEndpointService::inferKindFromLocation('WAN'));
        $this->assertSame('home', MediaUserEndpointService::normalizeKind('hogar'));
        $this->assertSame('away', MediaUserEndpointService::normalizeKind('fuera'));
    }
}
