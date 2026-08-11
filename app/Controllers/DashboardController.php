<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\MediaUserBulkService;
use App\Services\MonthlyRenewalEstimateService;
use App\Services\ServerSyncService;
use Core\Cache;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

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
        private MediaUserBulkService $bulk = new MediaUserBulkService(),
        private MonthlyRenewalEstimateService $monthlyEstimate = new MonthlyRenewalEstimateService(),
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
        $preferred = $this->servers->preferredDefaultForForms($tenantId);
        $plex = $this->servers->findDefaultByTenant($tenantId, 'plex');
        $jelly = $this->servers->findDefaultByTenant($tenantId, 'jellyfin');

        try {
            $renewalOutlook = $this->monthlyEstimate->upcomingTwoMonths($tenantId);
        } catch (\Throwable) {
            $renewalOutlook = [
                'this_month' => ['key' => '', 'label' => 'Este mes', 'caducidades' => 0],
                'next_month' => ['key' => '', 'label' => 'Próximo mes', 'caducidades' => 0],
            ];
        }

        return $this->view('dashboard.index', [
            'title' => 'Dashboard',
            'stats' => $stats,
            'servers' => $serverList,
            'user' => $user,
            'preferredServerId' => $preferred?->id ? (int) $preferred->id : null,
            'defaultPlexServerId' => $plex?->id ? (int) $plex->id : null,
            'defaultJellyfinServerId' => $jelly?->id ? (int) $jelly->id : null,
            'renewalOutlook' => $renewalOutlook,
        ]);
    }

    public function quickInvite(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $email = trim((string) $request->input('email', ''));
        $days = max(1, (int) $request->input('days', 30));
        $serverId = (int) $request->input('server_id', 0);

        try {
            $result = $this->bulk->inviteEmailWithDays($tenantId, $serverId, $email, $days);
        } catch (\Throwable $e) {
            Session::getInstance()->flash('error', 'Error al invitar: ' . $e->getMessage());
            return $this->redirect('/dashboard');
        }

        $session = Session::getInstance();
        $session->flash(
            !empty($result['success']) ? 'success' : 'error',
            (string) ($result['message'] ?? 'Error al invitar.')
        );

        if (!empty($result['success']) && !empty($result['password']) && !empty($result['username'])) {
            $session->flash('jellyfin_credentials', [
                'username' => (string) $result['username'],
                'password' => (string) $result['password'],
                'text' => (string) ($result['credentials_text'] ?? ''),
            ]);
        }

        if (!empty($result['success']) && !empty($result['uuid'])) {
            return $this->redirect('/media-users/' . $result['uuid']);
        }

        return $this->redirect('/dashboard');
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
