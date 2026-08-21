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
        $tenantId = (int) ($user->tenant_id ?? 1);
        $months = (int) $request->input('months', 0);
        $emailsRaw = $request->input('emails', []);
        $streamsRaw = $request->input('streams', []);
        $emails = is_array($emailsRaw) ? $emailsRaw : [];
        $streams = is_array($streamsRaw) ? $streamsRaw : [];

        $shop = new \App\Services\PortalShopService();
        $quote = $shop->quote($tenantId, $months, $emails, $streams);
        if (empty($quote['ok'])) {
            Session::getInstance()->flash('error', $quote['error'] ?? 'Revisa tu compra e inténtalo de nuevo.');
            return $this->redirect('/portal/subscription');
        }

        $placement = new \App\Services\ServerPlacementService();
        $types = $placement->shopTypes($tenantId);
        $requestedType = $placement->normalizeType((string) $request->input('server_type', ''));
        $buyerType = $placement->typeOfServerId((int) ($user->server_id ?? 0));
        if ($requestedType === 'plex' && (string) $request->input('server_type', '') === '' && $buyerType !== null) {
            $requestedType = $buyerType;
        }
        $keepId = ($buyerType === $requestedType) ? (int) ($user->server_id ?? 0) : 0;
        $seats = ((int) ($user->server_id ?? 0) > 0) ? 0 : 1;
        $repo = new \App\Repositories\MediaUserRepository();
        foreach ($quote['emails'] as $email) {
            if ($repo->findDuplicate($tenantId, '', (string) $email) === null) {
                $seats++;
            }
        }

        $serverId = null;
        if ($types !== []) {
            $placed = $placement->place($tenantId, $requestedType, $keepId, $seats);
            if (empty($placed['ok'])) {
                Session::getInstance()->flash('error', $placed['error'] ?? 'Elige Plex o Jellyfin.');
                return $this->redirect('/portal/subscription');
            }
            $serverId = (int) ($placed['server_id'] ?? 0);
        }

        $buyerEmail = mb_strtolower(trim((string) ($user->email ?? '')));
        $extraEmails = array_values(array_filter(
            $quote['emails'],
            static fn (string $e): bool => $e !== $buyerEmail
        ));

        $result = $this->billing->createRenewalCheckout(
            $user,
            (float) $quote['total'],
            'EUR',
            (int) $quote['days'],
            'stripe',
            [
                'shop' => 1,
                'months' => (int) $quote['months'],
                'users' => (int) $quote['users'],
                'extra_streams' => (int) $quote['extra_streams'],
                'extra_emails' => $extraEmails,
                'account_streams' => $quote['streams'],
                'server_id' => $serverId,
                'server_type' => $requestedType,
                'shop_accounts' => array_map(
                    static fn (string $email, int $i): array => [
                        'email' => $email,
                        'streams' => (int) ($quote['streams'][$i] ?? \App\Services\PortalShopService::INCLUDED_STREAMS),
                    ],
                    $quote['emails'],
                    array_keys($quote['emails'])
                ),
            ]
        );

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
