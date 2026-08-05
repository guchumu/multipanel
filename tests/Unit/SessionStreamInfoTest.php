<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Media\SessionStreamInfo;
use Tests\TestCase;

final class SessionStreamInfoTest extends TestCase
{
    public function test_plex_transcode_throttled_matches_dashboard_style(): void
    {
        $info = SessionStreamInfo::fromPlex(
            'transcode',
            [
                'videoDecision' => 'transcode',
                'audioDecision' => 'transcode',
                'subtitleDecision' => 'none',
                'throttled' => '1',
                'sourceVideoCodec' => 'h264',
                'videoCodec' => 'h264',
                'sourceAudioCodec' => 'ac3',
                'audioCodec' => 'aac',
                'sourceAudioChannels' => '6',
                'audioChannels' => '6',
                'width' => '1280',
                'height' => '720',
                'container' => 'mpegts',
                'videoBitrate' => '3800',
                'transcodeHwDecoding' => '1',
                'transcodeHwEncoding' => '1',
            ],
            [
                'container' => 'mkv',
                'videoResolution' => '1080',
                'videoCodec' => 'h264',
                'audioCodec' => 'ac3',
                'audioChannels' => '6',
                'bitrate' => '12000',
                'width' => '1920',
                'height' => '1080',
            ],
            ['bandwidth' => '4000'],
            [
                'streamType' => '1',
                'codec' => 'h264',
                'width' => '1920',
                'height' => '1080',
                'decision' => 'transcode',
            ],
            [
                'streamType' => '2',
                'codec' => 'ac3',
                'channels' => '6',
                'language' => 'español',
                'decision' => 'transcode',
            ],
            [],
        );

        $this->assertSame('4 Mbps 720p (3.8 Mbps)', $info['quality']);
        $this->assertSame('Transcode (Throttled)', $info['stream']);
        $this->assertSame('Converting (MKV → MPEGTS)', $info['container']);
        $this->assertSame('Transcode (H264 (HW) 1080p → H264 (HW) 720p)', $info['video']);
        $this->assertSame('Transcode (Español - AC3 5.1 → AAC 5.1)', $info['audio']);
        $this->assertSame('None', $info['subtitle']);
        $this->assertTrue($info['throttled']);
    }

    public function test_plex_direct_play_is_compact(): void
    {
        $info = SessionStreamInfo::fromPlex(
            'direct_play',
            null,
            [
                'container' => 'mkv',
                'videoResolution' => '1080',
                'videoCodec' => 'hevc',
                'audioCodec' => 'truehd',
                'audioChannels' => '8',
                'bitrate' => '45000',
            ],
            ['bandwidth' => '45000'],
            ['streamType' => '1', 'codec' => 'hevc', 'height' => '1080'],
            ['streamType' => '2', 'codec' => 'truehd', 'channels' => '8', 'language' => 'eng'],
            [],
        );

        $this->assertSame('45 Mbps 1080p', $info['quality']);
        $this->assertSame('Direct Play', $info['stream']);
        $this->assertSame('MKV', $info['container']);
        $this->assertStringContainsString('Direct Play', $info['video']);
        $this->assertStringContainsString('HEVC', $info['video']);
        $this->assertStringContainsString('English', $info['audio']);
        $this->assertSame('None', $info['subtitle']);
    }

    public function test_jellyfin_transcode_uses_playstate_and_streams(): void
    {
        $info = SessionStreamInfo::fromJellyfin(
            'transcode',
            [
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac',
                'Container' => 'ts',
                'IsVideoDirect' => false,
                'IsAudioDirect' => false,
                'Bitrate' => 4000000,
                'Width' => 1280,
                'Height' => 720,
                'AudioChannels' => 6,
                'HardwareAccelerationType' => 'nvenc',
            ],
            [
                'PlayMethod' => 'Transcode',
                'AudioStreamIndex' => 1,
                'SubtitleStreamIndex' => -1,
            ],
            [
                [
                    'Type' => 'Video',
                    'Index' => 0,
                    'Codec' => 'hevc',
                    'Width' => 1920,
                    'Height' => 1080,
                ],
                [
                    'Type' => 'Audio',
                    'Index' => 1,
                    'Codec' => 'ac3',
                    'Channels' => 6,
                    'Language' => 'spa',
                    'DisplayTitle' => 'Español',
                ],
            ],
            ['Container' => 'mkv'],
        );

        $this->assertSame('4 Mbps 720p', $info['quality']);
        $this->assertSame('Transcode', $info['stream']);
        $this->assertSame('Converting (MKV → TS)', $info['container']);
        $this->assertStringContainsString('Transcode', $info['video']);
        $this->assertStringContainsString('HEVC', $info['video']);
        $this->assertStringContainsString('H264', $info['video']);
        $this->assertStringContainsString('Español', $info['audio']);
        $this->assertSame('None', $info['subtitle']);
        $this->assertFalse($info['throttled']);
    }

    public function test_extract_plex_media_streams_prefers_decision(): void
    {
        [$media, $video, $audio, $subtitle] = SessionStreamInfo::extractPlexMediaStreams([
            [
                'container' => 'mkv',
                'Part' => [
                    [
                        'Stream' => [
                            ['streamType' => 1, 'codec' => 'h264', 'height' => 1080],
                            ['streamType' => 2, 'codec' => 'ac3', 'channels' => 6, 'language' => 'spa'],
                            ['streamType' => 2, 'codec' => 'aac', 'channels' => 2, 'language' => 'eng', 'decision' => 'transcode', 'selected' => true],
                            ['streamType' => 3, 'language' => 'spa', 'decision' => 'burn', 'selected' => true],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('mkv', $media['container'] ?? null);
        $this->assertSame('h264', $video['codec'] ?? null);
        $this->assertSame('aac', $audio['codec'] ?? null);
        $this->assertSame('burn', $subtitle['decision'] ?? null);
    }
}
