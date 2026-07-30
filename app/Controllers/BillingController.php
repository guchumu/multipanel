<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\BillingService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Billing and subscriptions controller.
 */
class BillingController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private BillingService $billing = new BillingService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        $plans = Database::getInstance()->fetchAll(
            'SELECT * FROM subscription_plans WHERE tenant_id = ? ORDER BY sort_order, price',
            [$tenantId]
        );

        $subscriptions = Database::getInstance()->fetchAll(
            "SELECT s.*, c.email as customer_email, c.first_name, c.last_name, p.name as plan_name
             FROM subscriptions s
             JOIN customers c ON c.id = s.customer_id
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.tenant_id = ?
             ORDER BY s.created_at DESC LIMIT 50",
            [$tenantId]
        );

        $stats = [
            'active' => Database::getInstance()->fetchOne("SELECT COUNT(*) as c FROM subscriptions WHERE tenant_id = ? AND status = 'active'", [$tenantId])['c'] ?? 0,
            'past_due' => Database::getInstance()->fetchOne("SELECT COUNT(*) as c FROM subscriptions WHERE tenant_id = ? AND status = 'past_due'", [$tenantId])['c'] ?? 0,
            'revenue' => Database::getInstance()->fetchOne("SELECT COALESCE(SUM(total),0) as t FROM invoices WHERE tenant_id = ? AND status = 'paid'", [$tenantId])['t'] ?? 0,
        ];

        return $this->view('billing.index', [
            'title' => 'Facturación',
            'plans' => $plans,
            'subscriptions' => $subscriptions,
            'stats' => $stats,
        ]);
    }

    public function createPlan(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|max:100',
            'price' => 'required|numeric',
            'interval' => 'required|in:daily,weekly,monthly,quarterly,yearly,lifetime',
        ]);

        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $this->billing->createPlan($tenantId, $data);

        Session::getInstance()->flash('success', 'Plan creado.');
        return $this->redirect('/billing');
    }

    public function markPaid(Request $request, int $id): Response
    {
        $this->billing->markPaid($id);
        Session::getInstance()->flash('success', 'Pago registrado.');
        return $this->redirect('/billing');
    }
}
