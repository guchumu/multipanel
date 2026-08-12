<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Stripe payment gateway integration (Checkout Sessions via REST API).
 */
final class StripeGateway implements PaymentGatewayInterface
{
    private Client $client;

    public function __construct(
        private string $secretKey = '',
        private string $webhookSecret = '',
        ?Client $client = null,
    ) {
        $this->secretKey = $secretKey !== ''
            ? trim($secretKey)
            : trim((string) config('payments.stripe.secret_key', env('STRIPE_SECRET_KEY', '')));
        $this->webhookSecret = $webhookSecret !== ''
            ? trim($webhookSecret)
            : trim((string) config('payments.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', '')));

        $this->client = $client ?? new Client([
            'base_uri' => 'https://api.stripe.com/v1/',
            'timeout' => 30,
            'auth' => [$this->secretKey, ''],
            'http_errors' => true,
        ]);
    }

    public function createCheckoutSession(float $amount, string $currency, array $metadata = []): array
    {
        $keyError = $this->validateSecretKey();
        if ($keyError !== null) {
            return ['error' => $keyError];
        }

        if ($amount <= 0) {
            return ['error' => 'El importe debe ser mayor que 0.'];
        }

        $unitAmount = (int) round($amount * 100);
        if ($unitAmount < 50) {
            // Stripe exige mínimo ~0,50 en la mayoría de monedas.
            return ['error' => 'El importe mínimo para Stripe es 0,50 en la moneda elegida.'];
        }

        $baseUrl = $this->resolvePublicBaseUrl();
        if ($baseUrl === null) {
            return ['error' => 'APP_URL no es una URL absoluta válida. Pon tu dominio real en .env (ej. https://tudominio.com) y reinicia PHP.'];
        }

        if (str_starts_with($this->secretKey, 'sk_live_') && str_starts_with($baseUrl, 'http://')) {
            return ['error' => 'Con claves live (sk_live_) Stripe exige HTTPS. Cambia APP_URL a https://tudominio.com en .env.'];
        }

        $successUrl = $baseUrl . '/portal/payment/success?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $baseUrl . '/portal/subscription';

        try {
            $response = $this->client->post('checkout/sessions', [
                'form_params' => [
                    'mode' => 'payment',
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'payment_method_types[0]' => 'card',
                    'line_items[0][price_data][currency]' => strtolower($currency),
                    'line_items[0][price_data][product_data][name]' => (string) ($metadata['plan_name'] ?? 'Suscripción MultiPanel'),
                    'line_items[0][price_data][unit_amount]' => $unitAmount,
                    'line_items[0][quantity]' => 1,
                    'metadata[subscription_id]' => (string) ($metadata['subscription_id'] ?? ''),
                    'metadata[customer_id]' => (string) ($metadata['customer_id'] ?? ''),
                    'metadata[media_user_id]' => (string) ($metadata['media_user_id'] ?? ''),
                    'metadata[tenant_id]' => (string) ($metadata['tenant_id'] ?? ''),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                Logger::error('Stripe checkout: respuesta no JSON');
                return ['error' => 'Stripe devolvió una respuesta inválida al crear el checkout.'];
            }

            $url = (string) ($data['url'] ?? '');
            $sessionId = (string) ($data['id'] ?? '');
            if ($url === '' || $sessionId === '') {
                Logger::error('Stripe checkout: sin url/session', ['keys' => array_keys($data)]);
                return ['error' => 'Stripe no devolvió la URL de pago. Revisa la clave secreta y los permisos de la cuenta.'];
            }

            return [
                'checkout_url' => $url,
                'session_id' => $sessionId,
            ];
        } catch (GuzzleException $e) {
            $message = $this->formatStripeError($e);
            Logger::error('Stripe checkout failed', ['error' => $message]);
            return ['error' => $message];
        } catch (\Throwable $e) {
            Logger::error('Stripe checkout failed', ['error' => $e->getMessage()]);
            return ['error' => 'Error al contactar con Stripe: ' . $e->getMessage()];
        }
    }

    public function handleWebhook(string $payload, array $headers = []): ?array
    {
        if (!$this->verifySignature($payload, $headers)) {
            Logger::error('Stripe webhook signature inválida o ausente');
            return null;
        }

        $event = json_decode($payload, true);
        if (!$event || !isset($event['type'])) {
            return null;
        }

        // Evento conocido pero no accionable aquí: devolvemos array vacío tipado
        // para que el controlador responda 200 (Stripe no reintente).
        if ($event['type'] !== 'checkout.session.completed') {
            return [
                'event' => 'ignored',
                'type' => $event['type'],
            ];
        }

        $session = $event['data']['object'] ?? [];
        return [
            'event' => 'payment.completed',
            'gateway_id' => $session['id'] ?? '',
            'amount' => ($session['amount_total'] ?? 0) / 100,
            'currency' => strtoupper($session['currency'] ?? 'EUR'),
            'metadata' => $session['metadata'] ?? [],
        ];
    }

    public function getName(): string
    {
        return 'stripe';
    }

    /**
     * Comprueba que la secret key es aceptada por Stripe (GET /v1/balance).
     * No crea cobros ni sesiones de checkout.
     *
     * @return array{ok: bool, message: string, mode?: string}
     */
    public function testConnection(): array
    {
        $keyError = $this->validateSecretKey();
        if ($keyError !== null) {
            return ['ok' => false, 'message' => $keyError];
        }

        $mode = str_starts_with($this->secretKey, 'sk_live_') || str_starts_with($this->secretKey, 'rk_live_')
            ? 'live'
            : 'test';

        try {
            $response = $this->client->get('balance');
            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return ['ok' => false, 'message' => 'Stripe respondió de forma inesperada al comprobar la clave.'];
            }

            return [
                'ok' => true,
                'mode' => $mode,
                'message' => 'Conexión OK con Stripe (modo ' . $mode . '). La clave secreta es válida.',
            ];
        } catch (GuzzleException $e) {
            return ['ok' => false, 'message' => $this->formatStripeError($e)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Error al contactar con Stripe: ' . $e->getMessage()];
        }
    }

    /**
     * Valida el formato de la secret key antes de llamar a la API.
     */
    private function validateSecretKey(): ?string
    {
        if ($this->secretKey === '') {
            return 'Stripe no está configurado: falta la clave secreta (sk_...). Añádela en Ajustes > Facturación.';
        }

        if (str_starts_with($this->secretKey, 'pk_')) {
            return 'Has pegado la clave pública (pk_...) en el campo de clave secreta. Usa la Secret key (sk_test_... o sk_live_...).';
        }

        if (str_starts_with($this->secretKey, 'whsec_')) {
            return 'Has pegado el webhook secret (whsec_...) donde va la clave secreta. La Secret key empieza por sk_.';
        }

        if (!preg_match('/^(sk|rk)_(test|live)_/', $this->secretKey)) {
            return 'La clave secreta de Stripe no tiene un formato válido (debe empezar por sk_test_, sk_live_ o rk_...).';
        }

        return null;
    }

    /**
     * Base pública para success/cancel. Si APP_URL es localhost/vacío pero la
     * petición llega por un dominio real, usamos el host actual (caso típico
     * en hosting donde .env se dejó en http://localhost).
     */
    private function resolvePublicBaseUrl(): ?string
    {
        $configured = rtrim((string) config('app.url', env('APP_URL', '')), '/');
        $configuredLooksLocal = $configured === ''
            || str_contains($configured, 'localhost')
            || str_contains($configured, '127.0.0.1');

        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $host = trim(explode(',', $host)[0]);

        if ($host !== '' && $configuredLooksLocal) {
            $proto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
            $https = strtolower($proto) === 'https'
                || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

            return ($https ? 'https' : 'http') . '://' . $host;
        }

        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return $configured;
        }

        return null;
    }

    /**
     * Extrae el mensaje real de Stripe del cuerpo JSON (seguro para mostrar al admin).
     */
    private function formatStripeError(\Throwable $e): string
    {
        $stripeMessage = null;
        $stripeType = null;
        $stripeCode = null;

        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = (string) $e->getResponse()->getBody();
            $json = json_decode($body, true);
            if (is_array($json) && isset($json['error']) && is_array($json['error'])) {
                $stripeMessage = isset($json['error']['message']) ? (string) $json['error']['message'] : null;
                $stripeType = isset($json['error']['type']) ? (string) $json['error']['type'] : null;
                $stripeCode = isset($json['error']['code']) ? (string) $json['error']['code'] : null;
            }
        }

        $raw = $stripeMessage ?: $e->getMessage();
        $hint = $this->hintForStripeMessage($raw, $stripeType, $stripeCode);

        return $hint !== null ? $hint : ('Stripe: ' . $raw);
    }

    private function hintForStripeMessage(string $raw, ?string $type, ?string $code): ?string
    {
        $lower = strtolower($raw);

        if (str_contains($lower, 'invalid api key') || str_contains($lower, 'invalid api_key')) {
            return 'Clave secreta de Stripe inválida. Comprueba que sea sk_test_... o sk_live_... (no la pk_) y que no tenga espacios de más.';
        }

        if (str_contains($lower, 'expired api key')) {
            return 'La clave secreta de Stripe ha caducado o fue revocada. Genera una nueva en el Dashboard de Stripe.';
        }

        if (str_contains($lower, 'you did not provide an api key') || ($type === 'invalid_request_error' && str_contains($lower, 'api key'))) {
            return 'No se envió ninguna clave secreta a Stripe. Guárdala en Ajustes > Facturación.';
        }

        if (str_contains($lower, 'invalid url') || str_contains($lower, 'not a valid url') || $code === 'url_invalid') {
            return 'URL de retorno inválida para Stripe. Revisa APP_URL en .env (debe ser https://tudominio.com sin barra final).';
        }

        if (str_contains($lower, 'test mode') && str_contains($lower, 'live')) {
            return 'Mezcla de modo test/live: usa sk_test_ con pk_test_ o sk_live_ con pk_live_, no las combines.';
        }

        if (str_contains($lower, 'amount must be at least') || $code === 'amount_too_small') {
            return 'Importe demasiado bajo para Stripe (mínimo habitual: 0,50).';
        }

        return null;
    }

    /**
     * Verifica la firma "Stripe-Signature" según el algoritmo oficial de Stripe:
     * HMAC-SHA256 sobre "{timestamp}.{payload}" usando el webhook secret.
     * Si no hay secret configurado se deja pasar (con aviso) para no bloquear entornos
     * de prueba, pero SIEMPRE se recomienda configurarlo en producción.
     *
     * @param array<string, string> $headers
     */
    private function verifySignature(string $payload, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Logger::warning('STRIPE_WEBHOOK_SECRET no configurado: se acepta el webhook sin verificar la firma');
            return true;
        }

        $sigHeader = '';
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'stripe-signature') {
                $sigHeader = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                break;
            }
        }

        if ($sigHeader === '') {
            return false;
        }

        $timestamp = '';
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if ($timestamp === '' || $signatures === []) {
            return false;
        }

        // Tolerancia de 5 minutos para evitar ataques de repetición.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
