<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Services\BillingService;
use App\Services\BillingSettingsService;
use Core\Database;
use Core\Logger;

/**
 * Payment orchestration service.
 */
final class PaymentService
{
    public function __construct(
        private BillingService $billing = new BillingService(),
        private BillingSettingsService $billingSettings = new BillingSettingsService(),
    ) {
    }

    /**
     * Las pasarelas se crean bajo demanda (no en el constructor) porque Stripe
     * necesita las claves configuradas para el tenant que hace el cobro, y el
     * tenant solo se conoce en el momento de la llamada, no al instanciar el
     * servicio.
     */
    private function makeGateway(string $gateway, int $tenantId): ?PaymentGatewayInterface
    {
        return match ($gateway) {
            'stripe' => new StripeGateway(
                $this->billingSettings->getStripeSecretKey($tenantId),
                $this->billingSettings->getStripeWebhookSecretsForVerification($tenantId)
            ),
            'paypal' => new PayPalGateway(),
            'bizum' => new BizumGateway(),
            'crypto' => new CryptoGateway(),
            default => null,
        };
    }

    public function checkout(string $gateway, float $amount, string $currency, array $metadata = [], int $tenantId = 1): array
    {
        $gw = $this->makeGateway($gateway, $tenantId);
        if (!$gw) {
            return ['error' => 'Gateway no soportado'];
        }

        return $gw->createCheckoutSession($amount, $currency, $metadata);
    }

    /**
     * @return array{ok: bool, ignored?: bool, error?: string}
     */
    public function processWebhook(string $gateway, string $payload, array $headers = [], int $tenantId = 1): array
    {
        $gw = $this->makeGateway($gateway, $tenantId);
        if (!$gw) {
            return ['ok' => false, 'error' => 'Gateway no soportado'];
        }

        $result = $gw->handleWebhook($payload, $headers);
        if ($result === null) {
            return ['ok' => false, 'error' => 'Firma de webhook inválida o payload ilegible'];
        }

        // Eventos que no nos interesan: OK para Stripe (no reintentar).
        if (($result['event'] ?? '') === 'ignored') {
            return ['ok' => true, 'ignored' => true];
        }

        if (($result['event'] ?? '') !== 'payment.completed') {
            return ['ok' => true, 'ignored' => true];
        }

        $subscriptionId = (int) ($result['metadata']['subscription_id'] ?? 0);
        $metaTenantId = (int) ($result['metadata']['tenant_id'] ?? 0);
        $resolvedTenantId = $metaTenantId > 0 ? $metaTenantId : $tenantId;

        if ($subscriptionId > 0) {
            $sub = Database::getInstance()->fetchOne(
                'SELECT tenant_id FROM subscriptions WHERE id = ?',
                [$subscriptionId]
            );
            if ($sub) {
                $resolvedTenantId = (int) $sub['tenant_id'];
            }
        }

        Database::getInstance()->insert('payment_transactions', [
            'tenant_id' => $resolvedTenantId,
            'subscription_id' => $subscriptionId ?: null,
            'gateway' => $gateway,
            'gateway_id' => $result['gateway_id'],
            'amount' => $result['amount'],
            'currency' => $result['currency'],
            'status' => 'completed',
            'metadata' => json_encode($result['metadata']),
        ]);

        if ($subscriptionId) {
            $this->billing->markPaid($subscriptionId);
        }

        try {
            (new \App\Services\WebhookService())->dispatch('payment.completed', [
                'gateway' => $gateway,
                'amount' => $result['amount'],
                'currency' => $result['currency'],
                'gateway_id' => $result['gateway_id'],
            ]);
        } catch (\Throwable) {
        }

        Logger::info('Payment processed', ['gateway' => $gateway, 'amount' => $result['amount']]);
        return ['ok' => true];
    }
}
