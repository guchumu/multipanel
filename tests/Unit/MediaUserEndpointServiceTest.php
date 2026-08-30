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

    public function testClassifyPlaybackLanIsHomeAndWanIsAwayUnlessMarked(): void
    {
        $svc = new MediaUserEndpointService();
        $this->assertSame('home', $svc->classifyPlayback([
            'location' => 'lan',
            'client_ip' => '10.0.0.8',
        ]));
        $this->assertSame('away', $svc->classifyPlayback([
            'location' => 'wan',
            'public_ip' => '8.8.8.8',
            'client_ip' => '8.8.8.8',
        ]));
        $this->assertSame('home', $svc->classifyPlayback([
            'location' => 'wan',
            'public_ip' => '203.0.113.10',
            'client_ip' => '203.0.113.10',
        ], ['203.0.113.10']));
    }

    public function testFireStickAndTvAreHomeEvenOnWan(): void
    {
        $svc = new MediaUserEndpointService();
        $this->assertSame('tv', MediaUserEndpointService::classifyDeviceClass([
            'product' => 'Plex for Amazon Fire TV',
            'platform' => 'Fire TV',
            'player' => 'Living Room',
        ]));
        $this->assertSame('home', $svc->classifyPlayback([
            'location' => 'wan',
            'public_ip' => '8.8.8.8',
            'product' => 'Plex for Amazon Fire TV',
            'platform' => 'Fire TV',
        ]));
        $this->assertSame('home', $svc->classifyPlayback([
            'product' => 'Plex for Apple TV',
            'platform' => 'tvOS',
            'location' => 'wan',
        ]));
    }

    public function testMobileIsAwayUnlessOnKnownHomeIp(): void
    {
        $svc = new MediaUserEndpointService();
        $this->assertSame('mobile', MediaUserEndpointService::classifyDeviceClass([
            'product' => 'Plex for iOS',
            'platform' => 'iOS',
            'player' => 'iPhone',
        ]));
        $this->assertSame('away', $svc->classifyPlayback([
            'location' => 'lan',
            'client_ip' => '192.168.1.20',
            'product' => 'Plex for iOS',
            'platform' => 'iOS',
            'player' => 'iPhone de Ana',
        ]));
        $this->assertSame('away', $svc->classifyPlayback([
            'product' => 'Plex for Android',
            'platform' => 'Android',
            'player' => 'Pixel 8',
            'location' => 'wan',
            'public_ip' => '8.8.8.8',
        ]));
        $this->assertSame('home', $svc->classifyPlayback([
            'location' => 'wan',
            'public_ip' => '203.0.113.10',
            'client_ip' => '203.0.113.10',
            'product' => 'Plex for iOS',
            'platform' => 'iOS',
            'player' => 'iPhone de Ana',
        ], ['203.0.113.10']));
        $this->assertSame('tv', MediaUserEndpointService::classifyDeviceClass([
            'product' => 'Plex for Android',
            'platform' => 'Android TV',
        ]));
        $this->assertSame('mobile', MediaUserEndpointService::classifyDeviceClass([
            'product' => 'Plex for Android',
            'platform' => 'Android',
            'player' => 'Samsung Galaxy S23',
        ]));
    }

    public function testTvIpMakesSameBatchMobileHome(): void
    {
        $svc = new MediaUserEndpointService();
        $tv = [
            'media_user_id' => 7,
            'product' => 'Plex for Amazon Fire TV',
            'platform' => 'Fire TV',
            'public_ip' => '203.0.113.44',
            'client_ip' => '203.0.113.44',
            'location' => 'wan',
        ];
        $phone = [
            'media_user_id' => 7,
            'product' => 'Plex for iOS',
            'platform' => 'iOS',
            'player' => 'iPhone',
            'public_ip' => '203.0.113.44',
            'client_ip' => '203.0.113.44',
            'location' => 'wan',
        ];
        $homeIps = $svc->mergeSessionHomeIps([$phone, $tv], []);

        $this->assertSame(['203.0.113.44'], $homeIps[7]);
        $this->assertSame('home', $svc->classifyPlayback($phone, $homeIps[7]));
        $meta = $svc->classifyPlaybackMeta($phone, $homeIps[7]);
        $this->assertSame('home_ip', $meta['source']);
        $this->assertSame('mobile', $meta['device_class']);

        $phoneOnLan = [
            'media_user_id' => 7,
            'product' => 'Plex for iOS',
            'platform' => 'iOS',
            'player' => 'iPhone',
            'client_ip' => '192.168.1.40',
            'location' => 'lan',
        ];
        $this->assertSame('home', $svc->classifyPlayback($phoneOnLan, $homeIps[7]));
    }

    public function testHomeIpOverridesMobileAwayDeviceClass(): void
    {
        $svc = new MediaUserEndpointService();
        $phone = [
            'media_user_id' => 9,
            'product' => 'Plex for iOS',
            'platform' => 'iOS',
            'player' => 'iPhone',
            'public_ip' => '203.0.113.55',
            'client_ip' => '203.0.113.55',
            'location' => 'wan',
        ];
        $homeIps = ['203.0.113.55'];
        $meta = $svc->classifyPlaybackMeta($phone, $homeIps, 9, []);
        $this->assertSame('home', $meta['kind']);
        $this->assertSame('home_ip', $meta['source']);
        $this->assertSame('mobile', $meta['device_class']);
    }
}
