<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Stripe payment gateway integration.
 */
final class StripeGateway implements PaymentGatewayInterface
{
    private Client $client;

    public function __construct(
        private string $secretKey = '',
        private string $webhookSecret = '',
    ) {
        $this->secretKey = $secretKey ?: config('payments.stripe.secret_key', env('STRIPE_SECRET_KEY', ''));
        $this->webhookSecret = $webhookSecret ?: config('payments.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', ''));
        $this->client = new Client([
            'base_uri' => 'https://api.stripe.com/v1/',
            'timeout' => 30,
            'auth' => [$this->secretKey, ''],
        ]);
    }

    public function createCheckoutSession(float $amount, string $currency, array $metadata = []): array
    {
        try {
            $response = $this->client->post('checkout/sessions', [
                'form_params' => [
                    'mode' => 'payment',
                    'success_url' => config('app.url') . '/portal/payment/success?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => config('app.url') . '/portal/subscription',
                    'line_items[0][price_data][currency]' => strtolower($currency),
                    'line_items[0][price_data][product_data][name]' => $metadata['plan_name'] ?? 'Suscripción MultiPanel',
                    'line_items[0][price_data][unit_amount]' => (int) ($amount * 100),
                    'line_items[0][quantity]' => 1,
                    'metadata[subscription_id]' => $metadata['subscription_id'] ?? '',
                    'metadata[customer_id]' => $metadata['customer_id'] ?? '',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return [
                'checkout_url' => $data['url'] ?? '',
                'session_id' => $data['id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            Logger::error('Stripe checkout failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    public function handleWebhook(string $payload, array $headers = []): ?array
    {
        $event = json_decode($payload, true);
        if (!$event || !isset($event['type'])) {
            return null;
        }

        if ($event['type'] === 'checkout.session.completed') {
            $session = $event['data']['object'] ?? [];
            return [
                'event' => 'payment.completed',
                'gateway_id' => $session['id'] ?? '',
                'amount' => ($session['amount_total'] ?? 0) / 100,
                'currency' => strtoupper($session['currency'] ?? 'EUR'),
                'metadata' => $session['metadata'] ?? [],
            ];
        }

        return null;
    }

    public function getName(): string
    {
        return 'stripe';
    }
}
