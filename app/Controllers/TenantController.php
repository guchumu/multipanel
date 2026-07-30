<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Multi-tenant management controller.
 */
class TenantController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenants = Database::getInstance()->fetchAll('SELECT * FROM tenants ORDER BY name');

        return $this->view('tenants.index', [
            'title' => 'Empresas / Tenants',
            'tenants' => $tenants,
            'currentTenantId' => Session::getInstance()->get('tenant_id', 1),
        ]);
    }

    public function switch(Request $request, int $id): Response
    {
        $tenant = Database::getInstance()->fetchOne('SELECT * FROM tenants WHERE id = ?', [$id]);
        if (!$tenant) {
            return $this->redirect('/tenants');
        }

        Session::getInstance()->set('tenant_id', $id);
        Session::getInstance()->flash('success', "Tenant cambiado a: {$tenant['name']}");
        return $this->redirect('/dashboard');
    }
}
