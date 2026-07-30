<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Payment gateway contract.
 */
interface PaymentGatewayInterface
{
    public function createCheckoutSession(float $amount, string $currency, array $metadata = []): array;

    public function handleWebhook(string $payload, array $headers = []): ?array;

    public function getName(): string;
}
