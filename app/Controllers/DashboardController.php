<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\ServerSyncService;
use Core\Cache;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Main dashboard controller.
 */
class DashboardController extends Controller
{
    public function __construct(
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
        private ServerRepository $servers = new ServerRepository(),
        private AuthService $auth = new AuthService(),
        private ServerSyncService $sync = new ServerSyncService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $tenantId = (int) ($user->tenant_id ?? 1);

        $this->mediaUsers->backfillMissingServerIds($tenantId);
        $this->maybeRecordSnapshot($tenantId);

        $stats = [
            'users_active' => $this->mediaUsers->countByStatus($tenantId, 'active'),
            'users_suspended' => $this->mediaUsers->countByStatus($tenantId, 'suspended'),
            'users_pending' => $this->mediaUsers->countByStatus($tenantId, 'pending'),
            'users_invited' => $this->mediaUsers->countByStatus($tenantId, 'invited'),
            'users_expired' => $this->mediaUsers->countByStatus($tenantId, 'expired'),
            'users_total' => $this->mediaUsers->countTotal($tenantId),
            'servers_online' => $this->servers->countByStatus($tenantId, 'online'),
            'servers_offline' => $this->servers->countByStatus($tenantId, 'offline'),
            'servers_total' => count($this->servers->allByTenant($tenantId)),
        ];

        $serverList = $this->servers->allByTenant($tenantId);

        return $this->view('dashboard.index', [
            'title' => 'Dashboard',
            'stats' => $stats,
            'servers' => $serverList,
            'user' => $user,
        ]);
    }

    /**
     * Red de seguridad para que el histórico de "Estadísticas" tenga datos
     * de más de un día aunque no haya un cron (`cron/run.php sync`) corriendo
     * en el hosting: cada vez que se visita el dashboard (o Estadísticas) se
     * sincroniza como mucho una vez cada 5 minutos por tenant.
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
        }
    }
}
