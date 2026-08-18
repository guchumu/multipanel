<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * PayPal payment gateway integration.
 */
final class PayPalGateway implements PaymentGatewayInterface
{
    private Client $client;
    private string $baseUrl;

    public function __construct(
        private string $clientId = '',
        private string $clientSecret = '',
        private bool $sandbox = true,
    ) {
        $this->clientId = $clientId ?: config('payments.paypal.client_id', env('PAYPAL_CLIENT_ID', ''));
        $this->clientSecret = $clientSecret ?: config('payments.paypal.client_secret', env('PAYPAL_CLIENT_SECRET', ''));
        $this->sandbox = config('payments.paypal.sandbox', true);
        $this->baseUrl = $this->sandbox
            ? 'https://api-m.sandbox.paypal.com/'
            : 'https://api-m.paypal.com/';
        $this->client = new Client(['base_uri' => $this->baseUrl, 'timeout' => 30]);
    }

    private function getAccessToken(): ?string
    {
        try {
            $response = $this->client->post('v1/oauth2/token', [
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['access_token'] ?? null;
        } catch (GuzzleException $e) {
            Logger::error('PayPal auth failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function createCheckoutSession(float $amount, string $currency, array $metadata = []): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['error' => 'PayPal authentication failed'];
        }

        try {
            $response = $this->client->post('v2/checkout/orders', [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'amount' => [
                            'currency_code' => strtoupper($currency),
                            'value' => number_format($amount, 2, '.', ''),
                        ],
                        'description' => $metadata['plan_name'] ?? 'Suscripción MultiPanel',
                    ]],
                    'application_context' => [
                        'return_url' => config('app.url') . '/portal/payment/success',
                        'cancel_url' => config('app.url') . '/portal/payment/cancel',
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $approveLink = '';
            foreach ($data['links'] ?? [] as $link) {
                if ($link['rel'] === 'approve') {
                    $approveLink = $link['href'];
                }
            }

            return [
                'checkout_url' => $approveLink,
                'order_id' => $data['id'] ?? '',
            ];
        } catch (GuzzleException $e) {
            Logger::error('PayPal checkout failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    public function handleWebhook(string $payload, array $headers = []): ?array
    {
        $event = json_decode($payload, true);
        if (!$event || ($event['event_type'] ?? '') !== 'CHECKOUT.ORDER.APPROVED') {
            return null;
        }

        $resource = $event['resource'] ?? [];
        return [
            'event' => 'payment.completed',
            'gateway_id' => $resource['id'] ?? '',
            'amount' => (float) ($resource['purchase_units'][0]['amount']['value'] ?? 0),
            'currency' => $resource['purchase_units'][0]['amount']['currency_code'] ?? 'EUR',
            'metadata' => [],
        ];
    }

    public function getName(): string
    {
        return 'paypal';
    }
}
