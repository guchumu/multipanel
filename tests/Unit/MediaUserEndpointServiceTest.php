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

    public function testFireStickAndTvFollowHomeIpNotDeviceType(): void
    {
        $svc = new MediaUserEndpointService();
        $this->assertSame('tv', MediaUserEndpointService::classifyDeviceClass([
            'product' => 'Plex for Amazon Fire TV',
            'platform' => 'Fire TV',
            'player' => 'Living Room',
        ]));
        // WAN sin IP de hogar marcada ⇒ fuera, aunque sea tele/Fire Stick.
        $this->assertSame('away', $svc->classifyPlayback([
            'location' => 'wan',
            'public_ip' => '8.8.8.8',
            'product' => 'Plex for Amazon Fire TV',
            'platform' => 'Fire TV',
        ]));
        $this->assertSame('away', $svc->classifyPlayback([
            'product' => 'Plex for Apple TV',
            'platform' => 'tvOS',
            'location' => 'wan',
            'public_ip' => '198.51.100.20',
        ]));
        // Misma IP de hogar marcada ⇒ hogar (tele en casa).
        $this->assertSame('home', $svc->classifyPlayback([
            'location' => 'wan',
            'public_ip' => '203.0.113.10',
            'client_ip' => '203.0.113.10',
            'product' => 'Plex for Amazon Fire TV',
            'platform' => 'Fire TV',
        ], ['203.0.113.10']));
        // LAN del servidor ⇒ hogar (misma red que el Plex/Jellyfin).
        $this->assertSame('home', $svc->classifyPlayback([
            'product' => 'Plex for Apple TV',
            'platform' => 'tvOS',
            'location' => 'lan',
            'client_ip' => '192.168.1.50',
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

    public function testFriendTvOnDifferentIpIsAwayWhileHomeTvIsHome(): void
    {
        $svc = new MediaUserEndpointService();
        $homeIp = '203.0.113.44';
        $homeTv = [
            'media_user_id' => 7,
            'product' => 'Plex for Samsung',
            'platform' => 'Tizen',
            'public_ip' => $homeIp,
            'client_ip' => $homeIp,
            'location' => 'wan',
        ];
        $friendFireStick = [
            'media_user_id' => 7,
            'product' => 'Plex for Amazon Fire TV',
            'platform' => 'Fire TV',
            'public_ip' => '198.51.100.99',
            'client_ip' => '198.51.100.99',
            'location' => 'wan',
        ];
        $phoneAtHome = [
            'media_user_id' => 7,
            'product' => 'Plex for iOS',
            'platform' => 'iOS',
            'player' => 'iPhone',
            'public_ip' => $homeIp,
            'client_ip' => $homeIp,
            'location' => 'wan',
        ];

        // Una tele en WAN ya no siembra IP de hogar por ser TV.
        $homeIps = $svc->mergeSessionHomeIps([$homeTv, $friendFireStick, $phoneAtHome], []);
        $this->assertArrayNotHasKey(7, $homeIps);

        $knownHome = [$homeIp];
        $this->assertSame('home', $svc->classifyPlayback($homeTv, $knownHome));
        $this->assertSame('away', $svc->classifyPlayback($friendFireStick, $knownHome));
        $this->assertSame('home', $svc->classifyPlayback($phoneAtHome, $knownHome));

        $friendMeta = $svc->classifyPlaybackMeta($friendFireStick, $knownHome);
        $this->assertSame('away', $friendMeta['kind']);
        $this->assertSame('wan', $friendMeta['source']);
        $this->assertSame('tv', $friendMeta['device_class']);
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

    public function testHomeIpRankingPrefersBusySharedIpOverRareFriendTv(): void
    {
        $endpoints = [
            [
                'id' => 1,
                'ip' => '203.0.113.10',
                'lan_ip' => '192.168.1.20',
                'location' => 'WAN',
                'product' => 'Plex for Samsung',
                'platform' => 'Tizen',
                'device_name' => 'Salon',
                'play_count' => 120,
                'last_seen_at' => '2026-09-06 10:00:00',
                'kind' => 'unknown',
                'kind_locked' => 0,
            ],
            [
                'id' => 2,
                'ip' => '203.0.113.10',
                'location' => 'WAN',
                'product' => 'Plex for iOS',
                'platform' => 'iOS',
                'device_name' => 'iPhone',
                'play_count' => 80,
                'last_seen_at' => '2026-09-06 11:00:00',
                'kind' => 'unknown',
                'kind_locked' => 0,
            ],
            [
                'id' => 3,
                'ip' => '198.51.100.99',
                'location' => 'WAN',
                'product' => 'Plex for Amazon Fire TV',
                'platform' => 'Fire TV',
                'device_name' => 'Amigos',
                'play_count' => 2,
                'last_seen_at' => '2026-09-01 09:00:00',
                'kind' => 'unknown',
                'kind_locked' => 0,
            ],
        ];

        $groups = MediaUserEndpointService::rankHomeIpGroups($endpoints);
        $this->assertSame('203.0.113.10', $groups[0]['ip']);
        $this->assertGreaterThan($groups[1]['score'], $groups[0]['score']);
        $this->assertStringContainsString('probable hogar', mb_strtolower((string) $groups[0]['label']));
        $this->assertStringContainsString('poco uso', mb_strtolower((string) $groups[1]['label']));

        $analysis = (new MediaUserEndpointService())->analyzeHomeIps(1, $endpoints);
        $this->assertNotNull($analysis['suggested']);
        $this->assertSame('203.0.113.10', $analysis['suggested']['ip']);
        $this->assertFalse($analysis['has_confirmed_home']);
    }
}
