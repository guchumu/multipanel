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
                $this->billingSettings->getStripeWebhookSecret($tenantId)
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

    public function processWebhook(string $gateway, string $payload, array $headers = [], int $tenantId = 1): bool
    {
        $gw = $this->makeGateway($gateway, $tenantId);
        if (!$gw) {
            return false;
        }

        $result = $gw->handleWebhook($payload, $headers);
        if (!$result || ($result['event'] ?? '') !== 'payment.completed') {
            return false;
        }

        $subscriptionId = (int) ($result['metadata']['subscription_id'] ?? 0);

        Database::getInstance()->insert('payment_transactions', [
            'tenant_id' => 1,
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
        return true;
    }
}
