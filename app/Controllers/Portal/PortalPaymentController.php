<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Services\Payments\PaymentService;
use App\Services\PortalAuthService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;

/**
 * Portal payment controller.
 */
class PortalPaymentController extends Controller
{
    public function __construct(
        private PortalAuthService $auth = new PortalAuthService(),
        private PaymentService $payments = new PaymentService(),
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
            ]);
        }

        return $this->view('portal.payment.error', [
            'title' => 'Error de pago',
            'error' => $result['error'] ?? 'No se pudo iniciar el pago.',
            'portalUser' => $user,
        ]);
    }

    public function success(Request $request): Response
    {
        return $this->view('portal.payment.success', [
            'title' => 'Pago completado',
            'portalUser' => $this->auth->user(),
        ]);
    }

    public function webhook(Request $request, string $gateway): Response
    {
        $payload = file_get_contents('php://input') ?: '';
        $headers = getallheaders() ?: [];

        $this->payments->processWebhook($gateway, $payload, $headers);

        return $this->json(['received' => true]);
    }
}
