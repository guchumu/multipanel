<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Services\BillingService;
use Core\Database;
use Core\Logger;

/**
 * Payment orchestration service.
 */
final class PaymentService
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function __construct(
        private BillingService $billing = new BillingService(),
    ) {
        $this->gateways = [
            'stripe' => new StripeGateway(),
            'paypal' => new PayPalGateway(),
            'bizum' => new BizumGateway(),
            'crypto' => new CryptoGateway(),
        ];
    }

    public function checkout(string $gateway, float $amount, string $currency, array $metadata = []): array
    {
        $gw = $this->gateways[$gateway] ?? null;
        if (!$gw) {
            return ['error' => 'Gateway no soportado'];
        }

        return $gw->createCheckoutSession($amount, $currency, $metadata);
    }

    public function processWebhook(string $gateway, string $payload, array $headers = []): bool
    {
        $gw = $this->gateways[$gateway] ?? null;
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
