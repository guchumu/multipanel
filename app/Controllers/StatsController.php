<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ExportService;
use App\Services\ServerSyncService;
use App\Services\StatsService;
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
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        (new ServerSyncService())->refreshStaleServers($tenantId, 3);

        return $this->view('stats.index', [
            'title' => 'Estadísticas',
            'stats' => $this->stats->getDashboardStats($tenantId),
            'daily' => $this->stats->getDailyStreaming($tenantId),
            'countries' => $this->stats->getTopCountries($tenantId),
            'devices' => $this->stats->getTopDevices($tenantId),
            'topContent' => $this->stats->getTopContent($tenantId),
            'hourly' => $this->stats->getHourlyDistribution($tenantId),
        ]);
    }

    public function api(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->json([
            'stats' => $this->stats->getDashboardStats($tenantId),
            'daily' => $this->stats->getDailyStreaming($tenantId, 7),
            'timestamp' => now()->format('c'),
        ]);
    }

    public function export(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $daily = $this->stats->getDailyStreaming($tenantId, 90);

        $export = new ExportService();
        $path = $export->toCsv($daily, ['date', 'sessions', 'hours'], 'streaming_stats_' . date('Y-m-d') . '.csv');
        $export->downloadResponse($path);
    }
}
