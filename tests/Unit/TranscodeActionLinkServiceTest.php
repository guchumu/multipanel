<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Media\SessionStreamInfo;
use App\Services\TranscodeActionLinkService;
use App\Services\VideoTranscodePauseService;
use Core\Cache;
use PHPUnit\Framework\TestCase;

final class TranscodeActionLinkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_URL=https://panel.example.test');
        $_ENV['APP_URL'] = 'https://panel.example.test';
        putenv('APP_KEY=test-secret-key-for-unit-tests');
        $_ENV['APP_KEY'] = 'test-secret-key-for-unit-tests';
        $_SERVER['HTTP_HOST'] = 'panel.example.test';
        $_SERVER['HTTPS'] = 'on';
    }

    public function testPauseShortUrlRoundTrip(): void
    {
        $svc = new TranscodeActionLinkService();
        $url = $svc->createPauseUrl([
            'tenant_id' => 1,
            'media_user_id' => 42,
            'username' => 'alice',
            'duration' => VideoTranscodePauseService::DURATION_3H,
        ]);
        $this->assertNotNull($url);
        $this->assertMatchesRegularExpression('#^https?://[^/]+/t/[A-Za-z0-9]{10}$#', (string) $url);

        $code = substr((string) $url, strrpos((string) $url, '/') + 1);
        $result = $svc->consume($code);
        $this->assertTrue($result['ok']);
        $this->assertSame(TranscodeActionLinkService::KIND_PAUSE, $result['kind']);
        $this->assertSame(VideoTranscodePauseService::DURATION_3H, $result['duration']);
        $this->assertSame('alice', $result['username']);

        // Los enlaces de pausa se pueden reutilizar el mismo día (reenviar al cliente).
        $again = $svc->consume($code);
        $this->assertTrue($again['ok']);
        $this->assertSame(VideoTranscodePauseService::DURATION_3H, $again['duration']);
    }

    public function testExplainVideoTranscodeReasonCodecAndRes(): void
    {
        $reason = SessionStreamInfo::explainVideoTranscodeReason([
            'quality' => '4 Mbps 720p',
            'throttled' => false,
            'container' => 'Converting (MKV → MPEGTS)',
            'subtitle' => 'None',
            'source' => [
                'video_codec' => 'HEVC',
                'resolution' => '4K',
                'audio_codec' => 'TRUEHD',
            ],
            'output' => [
                'video_codec' => 'H264',
                'resolution' => '720p',
                'audio_codec' => 'AAC',
                'subtitle' => 'None',
                'container' => 'Converting (MKV → MPEGTS)',
            ],
        ], [
            'player' => 'Plex for Android',
            'product' => 'Plex',
        ]);

        $this->assertStringContainsString('720p', $reason);
        $this->assertStringContainsString('HEVC', $reason);
        $this->assertStringContainsString('H264', $reason);
        $this->assertStringContainsString('cliente', $reason);
    }

    public function testExplainBurnSubtitles(): void
    {
        $reason = SessionStreamInfo::explainVideoTranscodeReason([
            'quality' => '8 Mbps 1080p',
            'subtitle' => 'Burn (Español)',
            'source' => [
                'video_codec' => 'H264',
                'resolution' => '1080p',
                'audio_codec' => 'AAC',
            ],
            'output' => [
                'video_codec' => 'H264',
                'resolution' => '1080p',
                'audio_codec' => 'AAC',
                'subtitle' => 'Burn (Español)',
            ],
        ]);

        $this->assertStringContainsString('subtítulos', $reason);
    }

    public function testSalvableQualityDownscaleIsKill(): void
    {
        $info = [
            'subtitle' => 'None',
            'source' => ['video_codec' => 'H264', 'resolution' => '1080p'],
            'output' => ['video_codec' => 'H264', 'resolution' => '720p', 'subtitle' => 'None'],
        ];
        $this->assertSame('kill', SessionStreamInfo::videoTranscodeAction($info));
        $this->assertTrue(SessionStreamInfo::isSalvableVideoTranscode($info));
        $this->assertStringContainsString('bajada', SessionStreamInfo::videoTranscodeActionLabel($info));
    }

    public function testCodecChangeIsAllowed(): void
    {
        $info = [
            'subtitle' => 'None',
            'source' => ['video_codec' => 'HEVC', 'resolution' => '4K'],
            'output' => ['video_codec' => 'H264', 'resolution' => '1080p', 'subtitle' => 'None'],
        ];
        $this->assertSame('allow', SessionStreamInfo::videoTranscodeAction($info));
        $this->assertFalse(SessionStreamInfo::isSalvableVideoTranscode($info));
        $this->assertStringContainsString('no acepta', SessionStreamInfo::videoTranscodeActionLabel($info));
    }

    public function testBurnSubtitlesAreAllowedEvenWithDownscale(): void
    {
        $info = [
            'subtitle' => 'Burn (Español)',
            'source' => ['video_codec' => 'H264', 'resolution' => '1080p'],
            'output' => [
                'video_codec' => 'H264',
                'resolution' => '720p',
                'subtitle' => 'Burn (Español)',
            ],
        ];
        $this->assertSame('allow', SessionStreamInfo::videoTranscodeAction($info));
        $this->assertTrue(SessionStreamInfo::hasBurnedSubtitles($info));
    }
}
