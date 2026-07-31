<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\ServerSyncService;
use App\Services\StreamingActivityService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Live streaming activity (now playing).
 */
class ActivityController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private StreamingActivityService $activity = new StreamingActivityService(),
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        (new ServerSyncService())->refreshStaleServers($tenantId, 10);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $snapshot = $this->activity->getSnapshot($tenantId, $serverId);

        return $this->view('activity.index', [
            'title' => 'En directo',
            'servers' => $this->servers->allByTenant($tenantId),
            'sessions' => $snapshot['sessions'],
            'grouped' => $snapshot['grouped'],
            'serverStats' => $snapshot['server_stats'],
            'totalCount' => $snapshot['total_count'],
            'currentServerId' => $serverId,
        ]);
    }

    public function api(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $snapshot = $this->activity->getSnapshot($tenantId, $serverId);

        return $this->json([
            'sessions' => $snapshot['sessions'],
            'grouped' => $snapshot['grouped'],
            'server_stats' => $snapshot['server_stats'],
            'count' => $snapshot['filtered_count'],
            'total_count' => $snapshot['total_count'],
        ]);
    }
}
