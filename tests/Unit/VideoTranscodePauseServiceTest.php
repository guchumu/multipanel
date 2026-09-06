<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StreamingActivityService;
use App\Services\VideoTranscodePauseService;
use Core\Cache;
use PHPUnit\Framework\TestCase;

final class VideoTranscodePauseServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_KEY=test-secret-key-for-unit-tests');
        $_ENV['APP_KEY'] = 'test-secret-key-for-unit-tests';
    }

    public function testPauseByMediaUserIdSkipsAutoKillMatch(): void
    {
        $svc = new VideoTranscodePauseService();
        $result = $svc->pause([
            'tenant_id' => 1,
            'media_user_id' => 42,
            'username' => 'alice',
            'duration' => VideoTranscodePauseService::DURATION_1H,
        ]);
        $this->assertTrue($result['ok']);
        $this->assertTrue($svc->isPaused(1, [
            'media_user_id' => 42,
            'server_id' => 9,
            'user' => 'alice',
        ]));
        $this->assertFalse($svc->isPaused(1, [
            'media_user_id' => 99,
            'server_id' => 9,
            'user' => 'bob',
        ]));
    }

    public function testFallbackPauseByExternalUserId(): void
    {
        $svc = new VideoTranscodePauseService();
        $ok = $svc->pause([
            'tenant_id' => 2,
            'server_id' => 7,
            'user_id' => 'plex-99',
            'username' => 'guest',
            'duration' => VideoTranscodePauseService::DURATION_3H,
        ]);
        $this->assertTrue($ok['ok']);
        $this->assertTrue($svc->isPaused(2, [
            'server_id' => 7,
            'user_id' => 'plex-99',
            'user' => 'guest',
        ]));
    }

    public function testSignedTokenRoundTrip(): void
    {
        $svc = new VideoTranscodePauseService();
        $token = $svc->createToken([
            'tenant_id' => 1,
            'media_user_id' => 5,
            'server_id' => 3,
            'user_id' => 'u1',
            'username' => 'neo',
            'duration' => VideoTranscodePauseService::DURATION_EOD,
        ]);
        $this->assertNotNull($token);
        $consumed = $svc->consumeToken((string) $token);
        $this->assertTrue($consumed['ok']);
        $this->assertSame(VideoTranscodePauseService::DURATION_EOD, $consumed['duration']);
        $this->assertTrue($svc->isPaused(1, ['media_user_id' => 5, 'user' => 'neo']));
    }

    public function testThumbSignatureRoundTrip(): void
    {
        $session = [
            'server_uuid' => 'srv-uuid-1',
            'art_path' => '/library/metadata/1/thumb',
        ];
        $path = StreamingActivityService::signedPublicThumbPath($session, 600);
        $this->assertNotNull($path);
        $this->assertStringContainsString('/activity/public-thumb/', (string) $path);

        $parts = parse_url((string) $path);
        parse_str((string) ($parts['query'] ?? ''), $q);
        $this->assertTrue(StreamingActivityService::verifyThumbSignature(
            'srv-uuid-1',
            (string) ($q['p'] ?? ''),
            '',
            (int) ($q['exp'] ?? 0),
            (string) ($q['sig'] ?? '')
        ));
        $this->assertFalse(StreamingActivityService::verifyThumbSignature(
            'srv-uuid-1',
            (string) ($q['p'] ?? ''),
            '',
            (int) ($q['exp'] ?? 0),
            'deadbeef'
        ));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
