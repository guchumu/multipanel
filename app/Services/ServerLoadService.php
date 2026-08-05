<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aggregates live load per media server from active playback sessions.
 *
 * Plex/Jellyfin do not expose host CPU/RAM/bandwidth via API; this service
 * reports session counts and transcode load derived from /status/sessions.
 */
final class ServerLoadService
{
    public function __construct(
        private StreamingActivityService $activity = new StreamingActivityService(),
    ) {
    }

    /**
     * @return array<int, array{
     *     sessions: int,
     *     transcode: int,
     *     direct_play: int,
     *     direct_stream: int,
     *     bandwidth_note: string
     * }>
     */
    public function getTenantLoad(int $tenantId): array
    {
        return $this->getActivityOverview($tenantId)['load'];
    }

    /**
     * Resumen de actividad en vivo (streams, transcodes y desglose por servidor)
     * reutilizando el snapshot cacheado de StreamingActivityService.
     *
     * @return array{
     *     total_streams: int,
     *     total_transcodes: int,
     *     total_direct_play: int,
     *     total_direct_stream: int,
     *     by_server: array<int, array{
     *         server_id: int,
     *         server_name: string,
     *         server_type: string,
     *         status: string,
     *         sessions: int,
     *         transcode: int,
     *         direct_play: int,
     *         direct_stream: int
     *     }>,
     *     load: array<int, array{
     *         sessions: int,
     *         transcode: int,
     *         direct_play: int,
     *         direct_stream: int,
     *         bandwidth_note: string
     *     }>,
     *     sessions: array<int, array<string, mixed>>
     * }
     */
    public function getActivityOverview(int $tenantId): array
    {
        $snapshot = $this->activity->getSnapshot($tenantId);
        $load = [];
        $byServer = [];

        $statsById = [];
        foreach ($snapshot['server_stats'] as $stat) {
            $statsById[(int) $stat['id']] = $stat;
        }

        foreach ($snapshot['grouped'] as $group) {
            $transcode = 0;
            $directPlay = 0;
            $directStream = 0;

            foreach ($group['sessions'] as $session) {
                match ((string) ($session['play_method'] ?? '')) {
                    'transcode' => $transcode++,
                    'direct_stream' => $directStream++,
                    default => $directPlay++,
                };
            }

            $sid = (int) $group['server_id'];
            $load[$sid] = [
                'sessions' => count($group['sessions']),
                'transcode' => $transcode,
                'direct_play' => $directPlay,
                'direct_stream' => $directStream,
                'bandwidth_note' => 'CPU/RAM/ancho de banda del host no disponibles vía API Plex',
            ];
            $byServer[] = [
                'server_id' => $sid,
                'server_name' => (string) $group['server_name'],
                'server_type' => (string) $group['server_type'],
                'status' => (string) ($statsById[$sid]['status'] ?? 'unknown'),
                'sessions' => count($group['sessions']),
                'transcode' => $transcode,
                'direct_play' => $directPlay,
                'direct_stream' => $directStream,
            ];
        }

        foreach ($snapshot['server_stats'] as $stat) {
            $id = (int) $stat['id'];
            if (!isset($load[$id])) {
                $load[$id] = [
                    'sessions' => 0,
                    'transcode' => 0,
                    'direct_play' => 0,
                    'direct_stream' => 0,
                    'bandwidth_note' => 'CPU/RAM/ancho de banda del host no disponibles vía API Plex',
                ];
                $byServer[] = [
                    'server_id' => $id,
                    'server_name' => (string) $stat['name'],
                    'server_type' => (string) $stat['type'],
                    'status' => (string) $stat['status'],
                    'sessions' => 0,
                    'transcode' => 0,
                    'direct_play' => 0,
                    'direct_stream' => 0,
                ];
            }
        }

        usort($byServer, static fn (array $a, array $b): int => strcmp($a['server_name'], $b['server_name']));

        $totalTranscodes = 0;
        $totalDirectPlay = 0;
        $totalDirectStream = 0;
        foreach ($load as $row) {
            $totalTranscodes += (int) $row['transcode'];
            $totalDirectPlay += (int) $row['direct_play'];
            $totalDirectStream += (int) $row['direct_stream'];
        }

        return [
            'total_streams' => (int) $snapshot['total_count'],
            'total_transcodes' => $totalTranscodes,
            'total_direct_play' => $totalDirectPlay,
            'total_direct_stream' => $totalDirectStream,
            'by_server' => $byServer,
            'load' => $load,
            'sessions' => $snapshot['sessions'],
        ];
    }
}
