<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\SecurityService;
use App\Services\AuditService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Security settings: IP blacklist, policies.
 */
class SecurityController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private SecurityService $security = new SecurityService(),
        private PermissionService $permissions = new PermissionService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'settings.manage');

        $tenantId = (int) ($user->tenant_id ?? 1);

        return $this->view('security.index', [
            'title' => __('security'),
            'blacklist' => $this->security->listBlacklist($tenantId),
            'policies' => config('abac.policies', []),
        ]);
    }

    public function block(Request $request): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'settings.manage');

        $data = $this->validate($request, ['ip_address' => 'required|min:7']);
        $tenantId = (int) ($user->tenant_id ?? 1);

        $this->security->blockIp($tenantId, $data['ip_address'], $request->input('reason'));
        AuditService::log('security.ip_blocked', 'ip_blacklist', null, null, ['ip' => $data['ip_address']]);

        Session::getInstance()->flash('success', 'IP bloqueada.');
        return $this->redirect('/security');
    }

    public function unblock(Request $request, int $id): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'settings.manage');

        $this->security->unblockIp($id);
        AuditService::log('security.ip_unblocked', 'ip_blacklist', $id);

        Session::getInstance()->flash('success', 'IP desbloqueada.');
        return $this->redirect('/security');
    }
}
