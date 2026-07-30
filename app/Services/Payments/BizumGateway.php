<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Ramsey\Uuid\Uuid;

/**
 * Bizum manual payment gateway (Spanish mobile payments).
 */
final class BizumGateway implements PaymentGatewayInterface
{
    public function createCheckoutSession(float $amount, string $currency, array $metadata = []): array
    {
        $reference = strtoupper(substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 8));
        $phone = env('BIZUM_PHONE', config('payments.bizum.phone', ''));

        if ($phone === '') {
            return ['error' => 'Bizum no configurado. Define BIZUM_PHONE en .env'];
        }

        return [
            'type' => 'manual',
            'reference' => $reference,
            'instructions' => [
                'phone' => $phone,
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
                'concept' => $metadata['plan_name'] ?? 'MultiPanel',
            ],
            'metadata' => array_merge($metadata, ['reference' => $reference, 'gateway' => 'bizum']),
        ];
    }

    public function handleWebhook(string $payload, array $headers = []): ?array
    {
        $data = json_decode($payload, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'confirmed') {
            return null;
        }

        return [
            'event' => 'payment.completed',
            'gateway_id' => $data['reference'] ?? '',
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => strtoupper($data['currency'] ?? 'EUR'),
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    public function getName(): string
    {
        return 'bizum';
    }
}
