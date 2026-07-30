<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Ramsey\Uuid\Uuid;

/**
 * Cryptocurrency manual payment gateway.
 */
final class CryptoGateway implements PaymentGatewayInterface
{
    public function createCheckoutSession(float $amount, string $currency, array $metadata = []): array
    {
        $wallet = env('CRYPTO_WALLET_ADDRESS', config('payments.crypto.wallet', ''));
        $network = env('CRYPTO_NETWORK', config('payments.crypto.network', 'BTC'));

        if ($wallet === '') {
            return ['error' => 'Crypto no configurado. Define CRYPTO_WALLET_ADDRESS en .env'];
        }

        $reference = strtoupper(substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 12));

        return [
            'type' => 'manual',
            'reference' => $reference,
            'instructions' => [
                'wallet' => $wallet,
                'network' => $network,
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
                'concept' => $metadata['plan_name'] ?? 'MultiPanel',
            ],
            'metadata' => array_merge($metadata, ['reference' => $reference, 'gateway' => 'crypto']),
        ];
    }

    public function handleWebhook(string $payload, array $headers = []): ?array
    {
        $data = json_decode($payload, true);
        if (!is_array($data) || empty($data['tx_hash'])) {
            return null;
        }

        return [
            'event' => 'payment.completed',
            'gateway_id' => $data['tx_hash'],
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => strtoupper($data['currency'] ?? 'EUR'),
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    public function getName(): string
    {
        return 'crypto';
    }
}
