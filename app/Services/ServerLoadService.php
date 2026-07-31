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
        $snapshot = $this->activity->getSnapshot($tenantId);
        $load = [];

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

            $load[(int) $group['server_id']] = [
                'sessions' => count($group['sessions']),
                'transcode' => $transcode,
                'direct_play' => $directPlay,
                'direct_stream' => $directStream,
                'bandwidth_note' => 'CPU/RAM/ancho de banda del host no disponibles vía API Plex',
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
            }
        }

        return $load;
    }
}
