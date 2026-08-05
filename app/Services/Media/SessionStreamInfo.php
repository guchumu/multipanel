<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Builds Plex-like Quality / Stream / Container / Video / Audio / Subtitle
 * labels for live session cards (En directo).
 */
final class SessionStreamInfo
{
    /**
     * @param array<string, mixed>|null $transcode TranscodeSession
     * @param array<string, mixed>|null $media     First Media entry
     * @param array<string, mixed>|null $sessionMeta Session node (bandwidth…)
     * @param array<string, mixed> $videoStream
     * @param array<string, mixed> $audioStream
     * @param array<string, mixed> $subtitleStream
     * @return array{
     *     quality: string,
     *     stream: string,
     *     container: string,
     *     video: string,
     *     audio: string,
     *     subtitle: string,
     *     throttled: bool
     * }
     */
    public static function fromPlex(
        string $playMethod,
        ?array $transcode,
        ?array $media,
        ?array $sessionMeta,
        array $videoStream = [],
        array $audioStream = [],
        array $subtitleStream = [],
    ): array {
        $transcode = $transcode ?? [];
        $media = $media ?? [];
        $sessionMeta = $sessionMeta ?? [];

        $throttled = self::truthy($transcode['throttled'] ?? false);
        $videoDecision = self::normalizeDecision(
            (string) ($transcode['videoDecision'] ?? $videoStream['decision'] ?? '')
        );
        $audioDecision = self::normalizeDecision(
            (string) ($transcode['audioDecision'] ?? $audioStream['decision'] ?? '')
        );
        $subtitleDecision = self::normalizeDecision(
            (string) ($transcode['subtitleDecision'] ?? $subtitleStream['decision'] ?? '')
        );
        if ($subtitleDecision === '' && $subtitleStream === []) {
            $subtitleDecision = 'none';
        }

        $sourceContainer = strtoupper((string) ($media['container'] ?? ''));
        $targetContainer = strtoupper((string) ($transcode['container'] ?? ''));
        if ($targetContainer === '' && $playMethod !== 'transcode') {
            $targetContainer = $sourceContainer;
        }

        $sourceRes = self::resolutionLabel(
            (string) ($media['videoResolution'] ?? ''),
            (int) ($videoStream['height'] ?? $media['height'] ?? $transcode['sourceVideoHeight'] ?? 0),
            (int) ($videoStream['width'] ?? $media['width'] ?? $transcode['sourceVideoWidth'] ?? 0),
        );
        $destRes = self::resolutionLabel(
            '',
            (int) ($transcode['height'] ?? 0),
            (int) ($transcode['width'] ?? 0),
        );
        if ($destRes === '' || $videoDecision === 'copy' || $videoDecision === 'directplay') {
            $destRes = $sourceRes;
        }

        $qualityBandwidthKbps = self::toKbps($sessionMeta['bandwidth'] ?? null);
        if ($qualityBandwidthKbps === null) {
            $qualityBandwidthKbps = self::toKbps($transcode['maxOffsetAvailable'] ?? null);
        }
        $actualBitrateKbps = self::toKbps($transcode['videoBitrate'] ?? null)
            ?? self::toKbps($sessionMeta['bitrate'] ?? null)
            ?? self::toKbps($media['bitrate'] ?? null)
            ?? self::toKbps($videoStream['bitrate'] ?? null);

        $displayRes = ($videoDecision === 'transcode' && $destRes !== '') ? $destRes : $sourceRes;
        $quality = self::formatQualityLine($qualityBandwidthKbps, $displayRes, $actualBitrateKbps);

        $stream = self::playMethodLabel($playMethod);
        if ($throttled) {
            $stream .= ' (Throttled)';
        }

        $container = self::formatContainerLine($playMethod, $sourceContainer, $targetContainer, $videoDecision, $audioDecision);

        $sourceVideoCodec = self::codecLabel(
            (string) ($transcode['sourceVideoCodec'] ?? $videoStream['codec'] ?? $media['videoCodec'] ?? '')
        );
        $destVideoCodec = self::codecLabel(
            (string) ($transcode['videoCodec'] ?? $sourceVideoCodec)
        );
        $sourceHw = self::hwSuffix($transcode, 'decode') || self::hwFromStream($videoStream);
        $destHw = self::hwSuffix($transcode, 'encode') || ($videoDecision === 'transcode' && self::hwSuffix($transcode, 'decode'));
        if ($videoDecision !== 'transcode') {
            $destHw = $sourceHw;
            $destVideoCodec = $sourceVideoCodec;
        }

        $video = self::formatDecisionLine(
            $videoDecision !== '' ? $videoDecision : ($playMethod === 'direct_play' ? 'directplay' : 'copy'),
            self::formatVideoDetail($sourceVideoCodec, $sourceRes, $sourceHw, $destVideoCodec, $destRes, $destHw, $videoDecision),
        );

        $audioLang = self::languageLabel(
            (string) ($audioStream['language'] ?? $audioStream['languageTag'] ?? $audioStream['languageCode'] ?? ''),
            (string) ($audioStream['displayTitle'] ?? ''),
        );
        $sourceAudioCodec = self::codecLabel(
            (string) ($transcode['sourceAudioCodec'] ?? $audioStream['codec'] ?? $media['audioCodec'] ?? '')
        );
        $destAudioCodec = self::codecLabel(
            (string) ($transcode['audioCodec'] ?? $sourceAudioCodec)
        );
        $sourceChannels = self::channelsLabel(
            (int) ($transcode['sourceAudioChannels'] ?? $audioStream['channels'] ?? $media['audioChannels'] ?? 0)
        );
        $destChannelsRaw = (int) ($transcode['audioChannels'] ?? $audioStream['channels'] ?? $media['audioChannels'] ?? 0);
        $destChannels = self::channelsLabel($destChannelsRaw);
        if ($destChannels === '') {
            $destChannels = $sourceChannels;
        }
        if ($audioDecision !== 'transcode') {
            $destAudioCodec = $sourceAudioCodec;
            $destChannels = $sourceChannels;
        }

        $audio = self::formatDecisionLine(
            $audioDecision !== '' ? $audioDecision : ($playMethod === 'direct_play' ? 'directplay' : 'copy'),
            self::formatAudioDetail($audioLang, $sourceAudioCodec, $sourceChannels, $destAudioCodec, $destChannels, $audioDecision),
        );

        $subtitle = self::formatSubtitleLine($subtitleDecision, $subtitleStream);

        return [
            'quality' => $quality !== '' ? $quality : '—',
            'stream' => $stream,
            'container' => $container !== '' ? $container : '—',
            'video' => $video,
            'audio' => $audio,
            'subtitle' => $subtitle,
            'throttled' => $throttled,
        ];
    }

    /**
     * @param array<string, mixed>|null $transcoding TranscodingInfo
     * @param array<string, mixed> $playState
     * @param array<int, array<string, mixed>> $mediaStreams
     * @return array{
     *     quality: string,
     *     stream: string,
     *     container: string,
     *     video: string,
     *     audio: string,
     *     subtitle: string,
     *     throttled: bool
     * }
     */
    public static function fromJellyfin(
        string $playMethod,
        ?array $transcoding,
        array $playState,
        array $mediaStreams = [],
        ?array $nowPlayingItem = null,
    ): array {
        $transcoding = is_array($transcoding) ? $transcoding : null;
        $nowPlayingItem = $nowPlayingItem ?? [];

        $videoStream = self::pickJellyfinStream($mediaStreams, 'Video', $playState['VideoStreamIndex'] ?? null);
        $audioStream = self::pickJellyfinStream($mediaStreams, 'Audio', $playState['AudioStreamIndex'] ?? null);
        $subtitleStream = self::pickJellyfinStream($mediaStreams, 'Subtitle', $playState['SubtitleStreamIndex'] ?? null);

        $isVideoDirect = $transcoding === null || !empty($transcoding['IsVideoDirect']);
        $isAudioDirect = $transcoding === null || !empty($transcoding['IsAudioDirect']);

        $videoDecision = match (true) {
            $playMethod === 'direct_play' => 'directplay',
            $isVideoDirect && $playMethod === 'direct_stream' => 'copy',
            $isVideoDirect => 'copy',
            default => 'transcode',
        };
        $audioDecision = match (true) {
            $playMethod === 'direct_play' => 'directplay',
            $isAudioDirect && $playMethod === 'direct_stream' => 'copy',
            $isAudioDirect => 'copy',
            default => 'transcode',
        };

        $sourceRes = self::resolutionLabel(
            '',
            (int) ($videoStream['Height'] ?? $nowPlayingItem['Height'] ?? 0),
            (int) ($videoStream['Width'] ?? $nowPlayingItem['Width'] ?? 0),
        );
        $destRes = self::resolutionLabel(
            '',
            (int) ($transcoding['Height'] ?? 0),
            (int) ($transcoding['Width'] ?? 0),
        );
        if ($destRes === '' || $videoDecision !== 'transcode') {
            $destRes = $sourceRes;
        }

        $bitrateKbps = self::toKbps($transcoding['Bitrate'] ?? null)
            ?? self::toKbps($videoStream['BitRate'] ?? null)
            ?? self::toKbps($nowPlayingItem['Bitrate'] ?? null);

        $quality = self::formatQualityLine($bitrateKbps, $destRes !== '' ? $destRes : $sourceRes, null);

        $stream = self::playMethodLabel($playMethod);

        $sourceContainer = strtoupper((string) ($nowPlayingItem['Container'] ?? ''));
        $targetContainer = strtoupper((string) ($transcoding['Container'] ?? $sourceContainer));
        $container = self::formatContainerLine($playMethod, $sourceContainer, $targetContainer, $videoDecision, $audioDecision);

        $sourceVideoCodec = self::codecLabel((string) ($videoStream['Codec'] ?? $nowPlayingItem['VideoCodec'] ?? ''));
        $destVideoCodec = self::codecLabel((string) ($transcoding['VideoCodec'] ?? $sourceVideoCodec));
        if ($videoDecision !== 'transcode') {
            $destVideoCodec = $sourceVideoCodec;
        }
        $hw = !empty($transcoding['HardwareAccelerationType']);
        $video = self::formatDecisionLine(
            $videoDecision,
            self::formatVideoDetail($sourceVideoCodec, $sourceRes, $hw && $videoDecision === 'transcode', $destVideoCodec, $destRes, $hw && $videoDecision === 'transcode', $videoDecision),
        );

        $audioLang = self::languageLabel(
            (string) ($audioStream['Language'] ?? ''),
            (string) ($audioStream['DisplayTitle'] ?? ''),
        );
        $sourceAudioCodec = self::codecLabel((string) ($audioStream['Codec'] ?? $nowPlayingItem['AudioCodec'] ?? ''));
        $destAudioCodec = self::codecLabel((string) ($transcoding['AudioCodec'] ?? $sourceAudioCodec));
        $sourceChannels = self::channelsLabel((int) ($audioStream['Channels'] ?? 0));
        $destChannels = self::channelsLabel((int) ($transcoding['AudioChannels'] ?? $audioStream['Channels'] ?? 0));
        if ($destChannels === '') {
            $destChannels = $sourceChannels;
        }
        if ($audioDecision !== 'transcode') {
            $destAudioCodec = $sourceAudioCodec;
            $destChannels = $sourceChannels;
        }
        $audio = self::formatDecisionLine(
            $audioDecision,
            self::formatAudioDetail($audioLang, $sourceAudioCodec, $sourceChannels, $destAudioCodec, $destChannels, $audioDecision),
        );

        $subIndex = $playState['SubtitleStreamIndex'] ?? null;
        $subtitleDecision = ($subIndex === null || (int) $subIndex < 0 || $subtitleStream === [])
            ? 'none'
            : (!empty($playState['PlayMethod']) && strtolower((string) $playState['PlayMethod']) === 'transcode'
                && empty($transcoding['IsVideoDirect']) ? 'burn' : 'copy');
        $subtitle = self::formatSubtitleLine($subtitleDecision, [
            'language' => (string) ($subtitleStream['Language'] ?? ''),
            'displayTitle' => (string) ($subtitleStream['DisplayTitle'] ?? ''),
        ]);

        return [
            'quality' => $quality !== '' ? $quality : '—',
            'stream' => $stream,
            'container' => $container !== '' ? $container : '—',
            'video' => $video,
            'audio' => $audio,
            'subtitle' => $subtitle,
            'throttled' => false,
        ];
    }

    /**
     * @param array<int|string, mixed> $mediaList
     * @return array{0: ?array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    public static function extractPlexMediaStreams(array $mediaList): array
    {
        $media = null;
        if ($mediaList !== []) {
            $first = array_is_list($mediaList) ? ($mediaList[0] ?? null) : $mediaList;
            $media = is_array($first) ? $first : null;
        }

        $video = [];
        $audio = [];
        $subtitle = [];
        if ($media === null) {
            return [null, $video, $audio, $subtitle];
        }

        $parts = $media['Part'] ?? [];
        if (!is_array($parts)) {
            $parts = [];
        }
        if ($parts !== [] && !array_is_list($parts)) {
            $parts = [$parts];
        }

        $streams = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $partStreams = $part['Stream'] ?? [];
            if (!is_array($partStreams)) {
                continue;
            }
            if ($partStreams !== [] && !array_is_list($partStreams)) {
                $partStreams = [$partStreams];
            }
            foreach ($partStreams as $stream) {
                if (is_array($stream)) {
                    $streams[] = $stream;
                }
            }
        }

        foreach ($streams as $stream) {
            $type = (int) ($stream['streamType'] ?? 0);
            $selected = self::truthy($stream['selected'] ?? false) || self::truthy($stream['decision'] ?? false);
            if ($type === 1 && ($video === [] || $selected)) {
                $video = $stream;
            } elseif ($type === 2 && ($audio === [] || $selected || self::truthy($stream['selected'] ?? false))) {
                if ($audio === [] || self::truthy($stream['selected'] ?? false)) {
                    $audio = $stream;
                }
            } elseif ($type === 3) {
                if ($subtitle === [] || self::truthy($stream['selected'] ?? false)) {
                    $subtitle = $stream;
                }
            }
        }

        // Prefer streams with an explicit decision (active in playback).
        foreach ($streams as $stream) {
            $type = (int) ($stream['streamType'] ?? 0);
            $decision = trim((string) ($stream['decision'] ?? ''));
            if ($decision === '') {
                continue;
            }
            if ($type === 1) {
                $video = $stream;
            } elseif ($type === 2) {
                $audio = $stream;
            } elseif ($type === 3) {
                $subtitle = $stream;
            }
        }

        return [$media, $video, $audio, $subtitle];
    }

    /** @return array<string, mixed> */
    public static function simpleXmlAttributes(\SimpleXMLElement $node): array
    {
        $out = [];
        foreach ($node->attributes() ?? [] as $key => $value) {
            $out[(string) $key] = (string) $value;
        }

        return $out;
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    public static function extractPlexMediaStreamsFromXml(\SimpleXMLElement $session): array
    {
        $mediaNode = $session->Media[0] ?? $session->Media ?? null;
        if (!$mediaNode instanceof \SimpleXMLElement) {
            return [null, [], [], []];
        }

        $media = self::simpleXmlAttributes($mediaNode);
        $streams = [];
        foreach ($mediaNode->Part ?? [] as $part) {
            foreach ($part->Stream ?? [] as $stream) {
                $streams[] = self::simpleXmlAttributes($stream);
            }
        }

        $wrapped = ['Part' => [['Stream' => $streams]]];
        $media['Part'] = $wrapped['Part'];

        return self::extractPlexMediaStreams([$media]);
    }

    private static function playMethodLabel(string $playMethod): string
    {
        return match ($playMethod) {
            'direct_play' => 'Direct Play',
            'direct_stream' => 'Direct Stream',
            'transcode' => 'Transcode',
            default => $playMethod !== '' ? ucfirst(str_replace('_', ' ', $playMethod)) : 'Direct Play',
        };
    }

    private static function normalizeDecision(string $decision): string
    {
        $decision = strtolower(trim($decision));

        return match ($decision) {
            'direct play', 'directplay' => 'directplay',
            'direct stream', 'directstream', 'copy' => 'copy',
            'transcode' => 'transcode',
            'burn', 'burned', 'burn-in' => 'burn',
            'none', '0' => 'none',
            default => $decision,
        };
    }

    private static function decisionWord(string $decision): string
    {
        return match (self::normalizeDecision($decision)) {
            'directplay' => 'Direct Play',
            'copy' => 'Direct Stream',
            'transcode' => 'Transcode',
            'burn' => 'Burn',
            'none' => 'None',
            default => $decision !== '' ? ucfirst($decision) : 'Direct Play',
        };
    }

    private static function formatDecisionLine(string $decision, string $detail): string
    {
        $word = self::decisionWord($decision);
        if ($detail === '') {
            return $word;
        }

        return $word . ' (' . $detail . ')';
    }

    private static function formatQualityLine(?float $bandwidthKbps, string $resolution, ?float $actualKbps): string
    {
        $parts = [];
        if ($bandwidthKbps !== null && $bandwidthKbps > 0) {
            $parts[] = self::formatMbps($bandwidthKbps);
        }
        if ($resolution !== '') {
            $parts[] = $resolution;
        }
        $line = implode(' ', $parts);
        if ($actualKbps !== null && $actualKbps > 0) {
            $actual = self::formatMbps($actualKbps);
            if ($line === '') {
                return $actual;
            }
            // Avoid redundant "(4 Mbps)" when equal to the leading value.
            if ($bandwidthKbps === null || abs($bandwidthKbps - $actualKbps) > 50) {
                $line .= ' (' . $actual . ')';
            }
        }

        return $line;
    }

    private static function formatContainerLine(
        string $playMethod,
        string $sourceContainer,
        string $targetContainer,
        string $videoDecision,
        string $audioDecision,
    ): string {
        $sourceContainer = strtoupper(trim($sourceContainer));
        $targetContainer = strtoupper(trim($targetContainer));

        $converting = $playMethod === 'transcode'
            || ($sourceContainer !== '' && $targetContainer !== '' && $sourceContainer !== $targetContainer);

        if ($converting && $sourceContainer !== '' && $targetContainer !== '' && $sourceContainer !== $targetContainer) {
            return 'Converting (' . $sourceContainer . ' → ' . $targetContainer . ')';
        }

        if ($converting && $targetContainer !== '') {
            return 'Converting (' . $targetContainer . ')';
        }

        if ($sourceContainer !== '') {
            return $sourceContainer;
        }

        return $targetContainer;
    }

    private static function formatVideoDetail(
        string $sourceCodec,
        string $sourceRes,
        bool $sourceHw,
        string $destCodec,
        string $destRes,
        bool $destHw,
        string $decision,
    ): string {
        $src = trim($sourceCodec . ($sourceHw ? ' (HW)' : '') . ($sourceRes !== '' ? ' ' . $sourceRes : ''));
        if ($decision !== 'transcode') {
            return $src;
        }
        $dst = trim($destCodec . ($destHw ? ' (HW)' : '') . ($destRes !== '' ? ' ' . $destRes : ''));
        if ($src === '' && $dst === '') {
            return '';
        }
        if ($src === '') {
            return $dst;
        }
        if ($dst === '' || $src === $dst) {
            return $src;
        }

        return $src . ' → ' . $dst;
    }

    private static function formatAudioDetail(
        string $lang,
        string $sourceCodec,
        string $sourceChannels,
        string $destCodec,
        string $destChannels,
        string $decision,
    ): string {
        $srcCore = trim($sourceCodec . ($sourceChannels !== '' ? ' ' . $sourceChannels : ''));
        $src = $lang !== '' && $srcCore !== '' ? $lang . ' - ' . $srcCore : ($lang !== '' ? $lang : $srcCore);

        if ($decision !== 'transcode') {
            return $src;
        }

        $dst = trim($destCodec . ($destChannels !== '' ? ' ' . $destChannels : ''));
        if ($src === '' && $dst === '') {
            return '';
        }
        if ($src === '') {
            return $dst;
        }
        if ($dst === '') {
            return $src;
        }

        return $src . ' → ' . $dst;
    }

    /** @param array<string, mixed> $subtitleStream */
    private static function formatSubtitleLine(string $decision, array $subtitleStream): string
    {
        $decision = self::normalizeDecision($decision);
        if ($decision === 'none' || ($subtitleStream === [] && ($decision === '' || $decision === 'none'))) {
            return 'None';
        }

        $lang = self::languageLabel(
            (string) ($subtitleStream['language'] ?? $subtitleStream['languageTag'] ?? $subtitleStream['Language'] ?? ''),
            (string) ($subtitleStream['displayTitle'] ?? $subtitleStream['DisplayTitle'] ?? ''),
        );
        $word = self::decisionWord($decision === '' ? 'copy' : $decision);
        if ($lang === '') {
            return $word;
        }

        return $word . ' (' . $lang . ')';
    }

    private static function resolutionLabel(string $videoResolution, int $height, int $width): string
    {
        $videoResolution = strtolower(trim($videoResolution));
        if ($videoResolution !== '') {
            if (in_array($videoResolution, ['4k', '8k', 'sd'], true)) {
                return strtoupper($videoResolution);
            }
            if (is_numeric($videoResolution)) {
                return $videoResolution . 'p';
            }
            if (str_ends_with($videoResolution, 'k')) {
                return strtoupper($videoResolution);
            }
            if (str_ends_with($videoResolution, 'p')) {
                return $videoResolution;
            }

            return $videoResolution;
        }

        if ($height >= 2160 || $width >= 3840) {
            return '4k';
        }
        if ($height >= 1080) {
            return '1080p';
        }
        if ($height >= 720) {
            return '720p';
        }
        if ($height >= 480) {
            return '480p';
        }
        if ($height > 0) {
            return $height . 'p';
        }

        return '';
    }

    private static function codecLabel(string $codec): string
    {
        $codec = trim($codec);
        if ($codec === '') {
            return '';
        }

        $map = [
            'h264' => 'H264',
            'avc' => 'H264',
            'hevc' => 'HEVC',
            'h265' => 'HEVC',
            'mpeg2video' => 'MPEG2',
            'mpeg4' => 'MPEG4',
            'vp9' => 'VP9',
            'av1' => 'AV1',
            'aac' => 'AAC',
            'ac3' => 'AC3',
            'eac3' => 'EAC3',
            'truehd' => 'TRUEHD',
            'dca' => 'DTS',
            'dts' => 'DTS',
            'mp3' => 'MP3',
            'flac' => 'FLAC',
            'opus' => 'Opus',
            'pcm' => 'PCM',
        ];

        $key = strtolower($codec);

        return $map[$key] ?? strtoupper($codec);
    }

    private static function channelsLabel(int $channels): string
    {
        return match ($channels) {
            1 => 'Mono',
            2 => 'Stereo',
            6 => '5.1',
            8 => '7.1',
            0 => '',
            default => (string) $channels . 'ch',
        };
    }

    private static function languageLabel(string $language, string $displayTitle): string
    {
        $language = trim($language);
        if ($language !== '') {
            // Plex often sends full names ("español"); keep as-is with title case-ish.
            if (strlen($language) > 3) {
                return mb_convert_case($language, MB_CASE_TITLE, 'UTF-8');
            }
            $map = [
                'es' => 'Español',
                'spa' => 'Español',
                'en' => 'English',
                'eng' => 'English',
                'fr' => 'Français',
                'fre' => 'Français',
                'fra' => 'Français',
                'de' => 'Deutsch',
                'ger' => 'Deutsch',
                'deu' => 'Deutsch',
                'pt' => 'Português',
                'por' => 'Português',
                'it' => 'Italiano',
                'ita' => 'Italiano',
                'ja' => '日本語',
                'jpn' => '日本語',
                'zh' => '中文',
                'chi' => '中文',
                'und' => '',
            ];

            return $map[strtolower($language)] ?? strtoupper($language);
        }

        $displayTitle = trim($displayTitle);
        if ($displayTitle === '') {
            return '';
        }

        // "Español (AC3 5.1)" → "Español"
        if (preg_match('/^([^(]+)/', $displayTitle, $m)) {
            return trim($m[1]);
        }

        return $displayTitle;
    }

    private static function formatMbps(float $kbps): string
    {
        $mbps = $kbps / 1000;
        if ($mbps >= 10) {
            return round($mbps) . ' Mbps';
        }
        $formatted = rtrim(rtrim(number_format($mbps, 1, '.', ''), '0'), '.');

        return $formatted . ' Mbps';
    }

    /** Accepts kbps or bps-ish numbers and normalizes to kbps. */
    private static function toKbps(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        if ($n <= 0) {
            return null;
        }
        // Jellyfin Bitrate is often bits/sec (e.g. 4000000).
        if ($n >= 100000) {
            return $n / 1000;
        }

        return $n;
    }

    /** @param array<string, mixed> $transcode */
    private static function hwSuffix(array $transcode, string $side): bool
    {
        if ($side === 'decode') {
            return self::truthy($transcode['transcodeHwDecoding'] ?? false)
                || self::truthy($transcode['transcodeHwFullPipeline'] ?? false);
        }

        return self::truthy($transcode['transcodeHwEncoding'] ?? false)
            || self::truthy($transcode['transcodeHwFullPipeline'] ?? false);
    }

    /** @param array<string, mixed> $stream */
    private static function hwFromStream(array $stream): bool
    {
        return self::truthy($stream['hw'] ?? false)
            || stripos((string) ($stream['displayTitle'] ?? ''), 'hw') !== false;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<int, array<string, mixed>> $streams
     * @return array<string, mixed>
     */
    private static function pickJellyfinStream(array $streams, string $type, mixed $index): array
    {
        $index = is_numeric($index) ? (int) $index : null;
        if ($index !== null && $index >= 0) {
            foreach ($streams as $stream) {
                if ((int) ($stream['Index'] ?? -1) === $index) {
                    return $stream;
                }
            }
        }

        foreach ($streams as $stream) {
            if (strcasecmp((string) ($stream['Type'] ?? ''), $type) === 0) {
                return $stream;
            }
        }

        return [];
    }
}
