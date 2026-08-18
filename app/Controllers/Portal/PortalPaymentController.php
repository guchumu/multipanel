<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Services\BillingService;
use App\Services\Payments\PaymentService;
use App\Services\PortalAuthService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Portal payment controller.
 */
class PortalPaymentController extends Controller
{
    public function __construct(
        private PortalAuthService $auth = new PortalAuthService(),
        private PaymentService $payments = new PaymentService(),
        private BillingService $billing = new BillingService(),
    ) {
    }

    public function checkout(Request $request): Response
    {
        $user = $this->auth->user();
        $planId = (int) $request->input('plan_id');
        $gateway = $request->input('gateway', 'stripe');

        $plan = Database::getInstance()->fetchOne(
            'SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1',
            [$planId]
        );

        if (!$plan) {
            Session::getInstance()->flash('error', 'El plan seleccionado no está disponible.');
            return $this->redirect('/portal/subscription');
        }

        $result = $this->payments->checkout($gateway, (float) $plan['price'], $plan['currency'], [
            'plan_name' => $plan['name'],
            'media_user_id' => $user->id,
        ], (int) ($user->tenant_id ?? 1));

        if (!empty($result['checkout_url'])) {
            return $this->redirect($result['checkout_url']);
        }

        if (($result['type'] ?? '') === 'manual') {
            return $this->view('portal.payment.manual', [
                'title' => 'Instrucciones de pago',
                'gateway' => $gateway,
                'instructions' => $result['instructions'],
                'reference' => $result['reference'],
                'plan' => $plan,
                'portalUser' => $user,
                'navActive' => 'pay',
            ]);
        }

        return $this->view('portal.payment.error', [
            'title' => 'Error de pago',
            'error' => $result['error'] ?? 'No se pudo iniciar el pago.',
            'portalUser' => $user,
            'navActive' => 'pay',
        ]);
    }

    /** Renovación rápida con preset (Stripe) desde el portal. */
    public function renew(Request $request): Response
    {
        $user = $this->auth->user();
        $amount = (float) $request->input('amount', 0);
        $days = (int) $request->input('days', 0);
        $currency = strtoupper(trim((string) $request->input('currency', 'EUR'))) ?: 'EUR';

        $result = $this->billing->createRenewalCheckout($user, $amount, $currency, $days);

        if (!empty($result['success']) && !empty($result['checkout_url'])) {
            return $this->redirect((string) $result['checkout_url']);
        }

        Session::getInstance()->flash('error', $result['message'] ?? 'No se pudo iniciar el pago.');
        return $this->redirect('/portal/subscription');
    }

    public function success(Request $request): Response
    {
        return $this->view('portal.payment.success', [
            'title' => 'Pago completado',
            'portalUser' => $this->auth->user(),
            'navActive' => 'pay',
        ]);
    }

    public function cancel(Request $request): Response
    {
        return $this->view('portal.payment.cancel', [
            'title' => 'Pago cancelado',
            'portalUser' => $this->auth->user(),
            'navActive' => 'pay',
        ]);
    }

    public function webhook(Request $request, string $gateway): Response
    {
        $payload = file_get_contents('php://input') ?: '';
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

        // Fallback cuando getallheaders() no existe (algunos SAPIs CGI).
        if ($headers === [] && isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $headers['Stripe-Signature'] = (string) $_SERVER['HTTP_STRIPE_SIGNATURE'];
        }

        $result = $this->payments->processWebhook($gateway, $payload, $headers);

        if (empty($result['ok'])) {
            return $this->json([
                'received' => false,
                'error' => $result['error'] ?? 'webhook_failed',
            ], 400);
        }

        return $this->json(['received' => true, 'ignored' => !empty($result['ignored'])]);
    }
}
