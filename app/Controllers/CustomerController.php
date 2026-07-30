<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\CustomerService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * CRM customers controller.
 */
class CustomerController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private CustomerService $customers = new CustomerService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $search = $request->input('q');

        return $this->view('customers.index', [
            'title' => 'Clientes CRM',
            'customers' => $this->customers->list($tenantId, is_string($search) ? $search : null),
            'stats' => $this->customers->stats($tenantId),
            'search' => $search,
        ]);
    }

    public function create(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $mediaUsers = Database::getInstance()->fetchAll(
            'SELECT id, username, email FROM media_users WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY username LIMIT 100',
            [$tenantId]
        );

        return $this->view('customers.create', [
            'title' => 'Nuevo cliente',
            'mediaUsers' => $mediaUsers,
        ]);
    }

    public function store(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $data = $this->validate($request, [
            'email' => 'required|email',
            'first_name' => 'required|min:2',
        ]);

        $this->customers->create($tenantId, [
            'email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $request->input('last_name'),
            'phone' => $request->input('phone'),
            'company' => $request->input('company'),
            'media_user_id' => $request->input('media_user_id') ?: null,
            'status' => $request->input('status', 'prospect'),
        ]);

        Session::getInstance()->flash('success', 'Cliente creado.');
        return $this->redirect('/customers');
    }

    public function show(Request $request, string $uuid): Response
    {
        $customer = $this->customers->findByUuid($uuid);
        if (!$customer) {
            Session::getInstance()->flash('error', 'Cliente no encontrado.');
            return $this->redirect('/customers');
        }

        $subscriptions = Database::getInstance()->fetchAll(
            'SELECT s.*, p.name as plan_name FROM subscriptions s
             JOIN subscription_plans p ON p.id = s.plan_id WHERE s.customer_id = ? ORDER BY s.created_at DESC',
            [$customer['id']]
        );

        return $this->view('customers.show', [
            'title' => 'Cliente: ' . ($customer['first_name'] ?? $customer['email']),
            'customer' => $customer,
            'subscriptions' => $subscriptions,
        ]);
    }

    public function update(Request $request, string $uuid): Response
    {
        $this->customers->update($uuid, $request->all());
        Session::getInstance()->flash('success', 'Cliente actualizado.');
        return $this->redirect('/customers/' . $uuid);
    }
}
