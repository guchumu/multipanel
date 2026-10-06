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

    public function test_short_video_transcode_why_shows_tautulli_transition(): void
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
                'sourceAudioCodec' => 'eac3',
                'audioCodec' => 'aac',
                'sourceAudioChannels' => '6',
                'audioChannels' => '6',
                'width' => '1280',
                'height' => '720',
                'container' => 'mp4',
                'videoBitrate' => '3800',
                'transcodeHwDecoding' => '1',
                'transcodeHwEncoding' => '1',
            ],
            [
                'container' => 'mkv',
                'videoResolution' => '1080',
                'videoCodec' => 'h264',
                'audioCodec' => 'eac3',
                'audioChannels' => '6',
                'bitrate' => '12000',
                'width' => '1920',
                'height' => '1080',
            ],
            ['bandwidth' => '4000'],
            [
                // Stream dims = salida (caso real Plex); el origen debe salir del Media.
                'streamType' => '1',
                'codec' => 'h264',
                'width' => '1280',
                'height' => '720',
                'decision' => 'transcode',
            ],
            [
                'streamType' => '2',
                'codec' => 'eac3',
                'channels' => '6',
                'language' => 'español',
                'decision' => 'transcode',
            ],
            [],
        );

        $this->assertSame('Transcode (H264 (HW) 1080p → H264 (HW) 720p)', $info['video']);
        $this->assertSame('1080p', $info['source']['resolution']);
        $this->assertSame('720p', $info['output']['resolution']);
        $this->assertSame(
            'H264 (HW) 1080p → H264 (HW) 720p · 4 Mbps 720p (3.8 Mbps)',
            SessionStreamInfo::shortVideoTranscodeWhy($info)
        );
        $detail = SessionStreamInfo::ntfyTranscodeChangeLines($info);
        $this->assertContains('Original → pide el cliente:', $detail);
        $this->assertTrue(
            (bool) array_filter($detail, static fn (string $l): bool => str_starts_with($l, 'Archivo:')),
            'Debe incluir línea Archivo con codec/resolución originales'
        );
        $this->assertContains('Vídeo: H264 (HW) 1080p → H264 (HW) 720p', $detail);
        $this->assertTrue(
            (bool) array_filter($detail, static fn (string $l): bool => str_starts_with($l, 'Audio:')),
            'Debe incluir línea de audio'
        );
        $this->assertContains('Calidad: 4 Mbps 720p (3.8 Mbps)', $detail);
    }

    public function test_plex_transcode_archivo_uses_source_not_output_resolution(): void
    {
        // Caso real: Samsung TV pide 480p; el fichero es 1080p.
        // Algunas versiones de Plex ponen en TranscodeSession el origen y en
        // Stream la salida; Media.height a veces viene ya con el alto de salida.
        $info = SessionStreamInfo::fromPlex(
            'transcode',
            [
                'videoDecision' => 'transcode',
                'audioDecision' => 'transcode',
                'subtitleDecision' => 'none',
                'sourceVideoCodec' => 'h264',
                'videoCodec' => 'h264',
                'sourceAudioCodec' => 'ac3',
                'audioCodec' => 'aac',
                'sourceAudioChannels' => '6',
                'audioChannels' => '6',
                // Tracearr: width/height del TranscodeSession = origen
                'width' => '1920',
                'height' => '1080',
                'container' => 'mp4',
                'videoBitrate' => '1900',
                'transcodeHwDecoding' => '1',
                'transcodeHwEncoding' => '1',
            ],
            [
                'container' => 'mp4',
                'videoCodec' => 'h264',
                'audioCodec' => 'ac3',
                'audioChannels' => '6',
                // Media “contaminado” con la salida (bug típico al leer sesiones)
                'width' => '854',
                'height' => '480',
            ],
            ['bandwidth' => '2000'],
            [
                'streamType' => '1',
                'codec' => 'h264',
                'width' => '854',
                'height' => '480',
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

        $this->assertSame('1080p', $info['source']['resolution']);
        $this->assertSame('480p', $info['output']['resolution']);
        $this->assertSame('Transcode (H264 (HW) 1080p → H264 (HW) 480p)', $info['video']);

        $detail = SessionStreamInfo::ntfyTranscodeChangeLines($info);
        $archivo = null;
        foreach ($detail as $line) {
            if (str_starts_with($line, 'Archivo:')) {
                $archivo = $line;
                break;
            }
        }
        $this->assertNotNull($archivo);
        $this->assertStringContainsString('1080p', (string) $archivo);
        $this->assertStringNotContainsString('480p', (string) $archivo);
        $this->assertContains('Vídeo: H264 (HW) 1080p → H264 (HW) 480p', $detail);
    }

    public function test_plex_transcode_prefers_media_video_resolution_label(): void
    {
        $info = SessionStreamInfo::fromPlex(
            'transcode',
            [
                'videoDecision' => 'transcode',
                'audioDecision' => 'copy',
                'width' => '854',
                'height' => '480',
                'videoCodec' => 'h264',
                'sourceVideoCodec' => 'h264',
            ],
            [
                'container' => 'mkv',
                'videoResolution' => '1080',
                'width' => '1920',
                'height' => '1080',
                'videoCodec' => 'h264',
            ],
            ['bandwidth' => '2000'],
            [
                'streamType' => '1',
                'codec' => 'h264',
                'width' => '854',
                'height' => '480',
                'decision' => 'transcode',
            ],
            [],
            [],
        );

        $this->assertSame('1080p', $info['source']['resolution']);
        $this->assertSame('480p', $info['output']['resolution']);
    }

    public function test_plex_transcode_library_source_fixes_fully_polluted_session(): void
    {
        // Caso Madre/Androide: sesión con Media/Stream/TS todos a 480p (salida),
        // pero la ficha de biblioteca dice 1080p MKV H264 AC3 5.1.
        $polluted = [
            'container' => 'mkv',
            'videoCodec' => 'h264',
            'audioCodec' => 'ac3',
            'audioChannels' => '6',
            'width' => '760',
            'height' => '428',
        ];
        $library = [
            'container' => 'mkv',
            'videoResolution' => '1080',
            'width' => '1920',
            'height' => '1080',
            'videoCodec' => 'h264',
            'audioCodec' => 'ac3',
            'audioChannels' => '6',
        ];
        $media = SessionStreamInfo::applyLibrarySourceToMedia($polluted, $library);

        $info = SessionStreamInfo::fromPlex(
            'transcode',
            [
                'videoDecision' => 'transcode',
                'audioDecision' => 'copy',
                'subtitleDecision' => 'none',
                'sourceVideoCodec' => 'h264',
                'videoCodec' => 'h264',
                'sourceAudioCodec' => 'ac3',
                'audioCodec' => 'ac3',
                'sourceAudioChannels' => '6',
                'audioChannels' => '6',
                'width' => '760',
                'height' => '428',
                'container' => 'mkv',
                'videoBitrate' => '1800',
                'throttled' => '1',
                'transcodeHwDecoding' => '1',
                'transcodeHwEncoding' => '1',
            ],
            $media,
            ['bandwidth' => '1900'],
            [
                'streamType' => '1',
                'codec' => 'h264',
                'width' => '760',
                'height' => '428',
                'decision' => 'transcode',
            ],
            [
                'streamType' => '2',
                'codec' => 'ac3',
                'channels' => '6',
                'language' => 'español',
                'decision' => 'copy',
            ],
            [],
        );

        $this->assertSame('1080p', $info['source']['resolution']);
        $this->assertSame('MKV', $info['source']['format']);
        $this->assertSame('H264', $info['source']['video_codec']);
        $this->assertSame('AC3', $info['source']['audio_codec']);
        $this->assertSame('5.1', $info['source']['audio_channels']);
        $this->assertSame('428p', $info['output']['resolution']);
        $this->assertSame('Transcode (H264 (HW) 1080p → H264 (HW) 428p)', $info['video']);

        $detail = SessionStreamInfo::ntfyTranscodeChangeLines($info);
        $this->assertContains('Archivo: MKV · 1080p · H264 · AC3 · 5.1', $detail);
        $this->assertContains('Vídeo: H264 (HW) 1080p → H264 (HW) 428p', $detail);
        $archivo = null;
        foreach ($detail as $line) {
            if (str_starts_with($line, 'Archivo:')) {
                $archivo = $line;
                break;
            }
        }
        $this->assertNotNull($archivo);
        $this->assertStringNotContainsString('428p', (string) $archivo);
        $this->assertStringNotContainsString('480p', (string) $archivo);
    }
}
