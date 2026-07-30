<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ApiKeyService;
use App\Services\AuthService;
use App\Services\PermissionService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * API keys management controller.
 */
class ApiKeyController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private ApiKeyService $apiKeys = new ApiKeyService(),
        private PermissionService $permissions = new PermissionService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'api.manage');

        $tenantId = (int) ($user->tenant_id ?? 1);
        $newKey = Session::getInstance()->getFlash('new_api_key');

        return $this->view('api_keys.index', [
            'title' => 'API Keys',
            'keys' => $this->apiKeys->list($tenantId),
            'newKey' => $newKey,
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'api.manage');

        $data = $this->validate($request, ['name' => 'required|min:2']);
        $tenantId = (int) ($user->tenant_id ?? 1);

        $result = $this->apiKeys->create($tenantId, (int) $user->id, $data['name']);

        Session::getInstance()->flash('new_api_key', $result['key']);
        Session::getInstance()->flash('success', 'API key creada. Cópiala ahora — no se mostrará de nuevo.');
        return $this->redirect('/api-keys');
    }

    public function destroy(Request $request, int $id): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'api.manage');

        $this->apiKeys->revoke($id);
        Session::getInstance()->flash('success', 'API key revocada.');
        return $this->redirect('/api-keys');
    }
}
