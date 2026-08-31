<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Media\PlexConnectionResolver;
use Tests\TestCase;

final class PlexConnectionResolverTest extends TestCase
{
    public function testDetectsPrivateIpv4AsLocal(): void
    {
        $this->assertTrue(PlexConnectionResolver::isLocalHost('192.168.1.100'));
        $this->assertTrue(PlexConnectionResolver::isLocalHost('10.0.0.5'));
        $this->assertTrue(PlexConnectionResolver::isLocalHost('172.16.0.1'));
        $this->assertFalse(PlexConnectionResolver::isLocalHost('79.116.40.195'));
        $this->assertFalse(PlexConnectionResolver::isLocalHost('210.10.1.1'));
    }

    public function testDetectsPlexDirectLocalHostname(): void
    {
        $this->assertTrue(PlexConnectionResolver::isLocalHost(
            '192-168-1-100.680fc273a3314c4e8e28f3919866206e.plex.direct'
        ));
        $this->assertFalse(PlexConnectionResolver::isLocalHost(
            '79-116-40-195.680fc273a3314c4e8e28f3919866206e.plex.direct'
        ));
    }

    public function testAsHttpProbeKeepsPortWhenSwitchingToHttp(): void
    {
        $http = PlexConnectionResolver::asHttpProbe([
            'url' => '88-80-190-127.680fc273a3314c4e8e28f3919866206e.plex.direct',
            'port' => 8443,
            'ssl' => true,
        ]);

        $this->assertSame(8443, $http['port']);
        $this->assertFalse($http['ssl']);
    }

    public function testAsHttpProbeKeepsHttpPort(): void
    {
        $http = PlexConnectionResolver::asHttpProbe([
            'url' => 'plex.example.com',
            'port' => 32400,
            'ssl' => false,
        ]);

        $this->assertSame(32400, $http['port']);
        $this->assertFalse($http['ssl']);
    }
}
