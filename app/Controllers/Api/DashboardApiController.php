<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * REST API dashboard and stats endpoints.
 */
class DashboardApiController extends Controller
{
    public function __construct(
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
        private ServerRepository $servers = new ServerRepository(),
        private AuthService $auth = new AuthService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = 1; // TODO: resolve from JWT payload

        return $this->json([
            'users' => [
                'active' => $this->mediaUsers->countByStatus($tenantId, 'active'),
                'suspended' => $this->mediaUsers->countByStatus($tenantId, 'suspended'),
                'pending' => $this->mediaUsers->countByStatus($tenantId, 'pending'),
                'invited' => $this->mediaUsers->countByStatus($tenantId, 'invited'),
                'total' => $this->mediaUsers->countTotal($tenantId),
            ],
            'servers' => [
                'online' => $this->servers->countByStatus($tenantId, 'online'),
                'offline' => $this->servers->countByStatus($tenantId, 'offline'),
                'total' => count($this->servers->allByTenant($tenantId)),
            ],
        ]);
    }

    public function health(Request $request): Response
    {
        return $this->json([
            'status' => 'ok',
            'version' => config('app.version', '1.0.0'),
            'timestamp' => now()->format('c'),
        ]);
    }
}
