<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ExportService;
use App\Services\ServerSyncService;
use App\Services\StatsService;
use Core\Cache;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Statistics and analytics controller.
 */
class StatsController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private StatsService $stats = new StatsService(),
        private ServerSyncService $sync = new ServerSyncService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $this->maybeRecordSnapshot($tenantId);

        $period = $this->stats->resolvePeriod(
            (string) $request->input('preset', '30d'),
            $request->input('from'),
            $request->input('to')
        );
        $mediaType = $this->stats->normalizeMediaType($request->input('type'));

        $query = array_filter([
            'preset' => $period['preset'] !== '30d' ? $period['preset'] : null,
            'from' => $period['preset'] === 'custom' ? $period['from_date'] : null,
            'to' => $period['preset'] === 'custom' ? $period['to_date'] : null,
            'type' => $mediaType !== '' ? $mediaType : null,
        ], static fn ($v) => $v !== null && $v !== '');

        return $this->view('stats.index', [
            'title' => 'Estadísticas',
            'stats' => $this->stats->getDashboardStats($tenantId),
            'period' => $period,
            'mediaType' => $mediaType,
            'filterQuery' => $query,
            'daily' => $this->stats->getDailyStreaming($tenantId, $period, $mediaType),
            'countries' => $this->stats->getTopCountries($tenantId, $period, $mediaType),
            'devices' => $this->stats->getTopDevices($tenantId, $period, $mediaType),
            'topContent' => $this->stats->getTopContent($tenantId, $period, $mediaType),
            'topUsers' => $this->stats->getTopUsers($tenantId, $period, $mediaType),
            'hourly' => $this->stats->getHourlyDistribution($tenantId, $period, $mediaType),
            'typeBreakdown' => $this->stats->getTypeBreakdown($tenantId, $period),
        ]);
    }

    public function api(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $period = $this->stats->resolvePeriod('7d');

        return $this->json([
            'stats' => $this->stats->getDashboardStats($tenantId),
            'daily' => $this->stats->getDailyStreaming($tenantId, $period),
            'timestamp' => now()->format('c'),
        ]);
    }

    public function export(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $period = $this->stats->resolvePeriod(
            (string) $request->input('preset', '30d'),
            $request->input('from'),
            $request->input('to')
        );
        $mediaType = $this->stats->normalizeMediaType($request->input('type'));
        $daily = $this->stats->getDailyStreaming($tenantId, $period, $mediaType);

        $export = new ExportService();
        $path = $export->toCsv($daily, ['date', 'sessions', 'hours'], 'streaming_stats_' . date('Y-m-d') . '.csv');
        $export->downloadResponse($path);
    }

    /**
     * El histórico de "Streaming últimos 30 días" depende de que algo vaya
     * grabando snapshots (playback_sessions / server_stats) con regularidad.
     * Si no hay un cron configurado en el hosting (`cron/run.php sync`), solo
     * se veía el día de hoy porque nunca se registraba nada más. Como red de
     * seguridad, sincronizamos aquí también, con un límite de una vez cada
     * 5 minutos por tenant para no ralentizar la página ni saturar los
     * servidores Plex/Jellyfin en cada carga.
     */
    private function maybeRecordSnapshot(int $tenantId): void
    {
        $cacheKey = 'stats_snapshot_synced_' . $tenantId;
        if (Cache::get($cacheKey) !== null) {
            return;
        }

        Cache::set($cacheKey, true, 300);

        try {
            $this->sync->syncAll($tenantId);
        } catch (\Throwable) {
            // No bloqueamos la vista de estadísticas si un servidor falla al sincronizar.
        }
    }
}
