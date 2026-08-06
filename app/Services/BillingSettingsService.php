<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Configuración de cobros: el "concepto" que ve el cliente en Stripe (siempre el
 * mismo, ej. "Digital services") y los presets de renovación rápida (duración +
 * precio) que se usan a nivel interno para sumar días y registrar lo cobrado.
 */
final class BillingSettingsService
{
    private const DEFAULT_CONCEPT = 'Digital services';

    public function getPaymentConcept(int $tenantId): string
    {
        $value = $this->get($tenantId, 'payment_concept');

        return $value !== null && trim($value) !== '' ? trim($value) : self::DEFAULT_CONCEPT;
    }

    public function savePaymentConcept(int $tenantId, string $concept): void
    {
        $this->set($tenantId, 'payment_concept', trim($concept) !== '' ? trim($concept) : self::DEFAULT_CONCEPT, 'string');
    }

    /** @return array<int, array{label: string, days: int, price: float}> */
    public function getRenewalPresets(int $tenantId): array
    {
        $raw = $this->get($tenantId, 'renewal_presets');
        if ($raw === null || trim($raw) === '') {
            return $this->defaultPresets();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return $this->defaultPresets();
        }

        return array_values(array_map(static fn (array $p): array => [
            'label' => (string) ($p['label'] ?? ''),
            'days' => (int) ($p['days'] ?? 0),
            'price' => (float) ($p['price'] ?? 0),
        ], $decoded));
    }

    /** @param array<int, array{label: string, days: int, price: float}> $presets */
    public function saveRenewalPresets(int $tenantId, array $presets): void
    {
        $clean = array_values(array_filter(array_map(static function (array $p): ?array {
            $label = trim((string) ($p['label'] ?? ''));
            $days = (int) ($p['days'] ?? 0);
            $price = (float) ($p['price'] ?? 0);
            if ($label === '' || $days <= 0 || $price <= 0) {
                return null;
            }

            return ['label' => $label, 'days' => $days, 'price' => $price];
        }, $presets)));

        $this->set($tenantId, 'renewal_presets', json_encode($clean, JSON_UNESCAPED_UNICODE), 'json');
    }

    /**
     * Claves de Stripe configurables desde Facturación. Si el tenant no ha
     * guardado las suyas, se cae a las variables de entorno / config/payments.php
     * (útil para no romper instalaciones que ya las tenían solo en .env).
     * Los secretos se cifran en reposo con SecretCrypt cuando es posible.
     */
    public function getStripeSecretKey(int $tenantId): string
    {
        $value = $this->getDecrypted($tenantId, 'stripe_secret_key');

        return $value !== null && trim($value) !== ''
            ? trim($value)
            : (string) config('payments.stripe.secret_key', env('STRIPE_SECRET_KEY', ''));
    }

    public function getStripePublishableKey(int $tenantId): string
    {
        $value = $this->get($tenantId, 'stripe_publishable_key');

        return $value !== null && trim($value) !== ''
            ? trim($value)
            : (string) config('payments.stripe.publishable_key', env('STRIPE_PUBLISHABLE_KEY', ''));
    }

    public function getStripeWebhookSecret(int $tenantId): string
    {
        $value = $this->getDecrypted($tenantId, 'stripe_webhook_secret');

        return $value !== null && trim($value) !== ''
            ? trim($value)
            : (string) config('payments.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', ''));
    }

    public function hasTenantStripeSecretKey(int $tenantId): bool
    {
        $value = $this->getDecrypted($tenantId, 'stripe_secret_key');

        return $value !== null && trim($value) !== '';
    }

    /**
     * Guarda las claves de Stripe. Un campo vacío deja intacta la clave ya
     * guardada (para no obligar a repegar el secret key cada vez que se
     * cambia el concepto de pago u otro ajuste del mismo formulario).
     *
     * @return list<string> Errores de validación (vacío = OK)
     */
    public function saveStripeKeys(int $tenantId, ?string $secretKey, ?string $publishableKey, ?string $webhookSecret): array
    {
        $errors = [];
        $crypt = new SecretCrypt();

        if ($secretKey !== null && trim($secretKey) !== '') {
            $secretKey = trim($secretKey);
            if (str_starts_with($secretKey, 'pk_')) {
                $errors[] = 'La clave secreta no puede ser la publishable (pk_...). Usa sk_test_... o sk_live_....';
            } elseif (str_starts_with($secretKey, 'whsec_')) {
                $errors[] = 'Has pegado el webhook secret (whsec_...) en la clave secreta. La secreta empieza por sk_.';
            } elseif (!preg_match('/^(sk|rk)_(test|live)_/', $secretKey)) {
                $errors[] = 'La clave secreta de Stripe no parece válida (debe empezar por sk_test_, sk_live_ o rk_...).';
            } else {
                $this->set($tenantId, 'stripe_secret_key', $crypt->encrypt($secretKey), 'encrypted');
            }
        }

        if ($publishableKey !== null && trim($publishableKey) !== '') {
            $publishableKey = trim($publishableKey);
            if (str_starts_with($publishableKey, 'sk_') || str_starts_with($publishableKey, 'rk_')) {
                $errors[] = 'La clave pública debe empezar por pk_..., no por sk_.';
            } elseif (!str_starts_with($publishableKey, 'pk_')) {
                $errors[] = 'La clave pública de Stripe no parece válida (debe empezar por pk_test_ o pk_live_).';
            } else {
                $this->set($tenantId, 'stripe_publishable_key', $publishableKey, 'string');
            }
        }

        if ($webhookSecret !== null && trim($webhookSecret) !== '') {
            $webhookSecret = trim($webhookSecret);
            if (!str_starts_with($webhookSecret, 'whsec_')) {
                $errors[] = 'El webhook signing secret debe empezar por whsec_....';
            } else {
                $this->set($tenantId, 'stripe_webhook_secret', $crypt->encrypt($webhookSecret), 'encrypted');
            }
        }

        return $errors;
    }

    /** @return array<int, array{label: string, days: int, price: float}> */
    private function defaultPresets(): array
    {
        return [
            ['label' => '1 mes', 'days' => 30, 'price' => 15.0],
            ['label' => '3 meses', 'days' => 90, 'price' => 40.0],
            ['label' => '6 meses', 'days' => 180, 'price' => 70.0],
            ['label' => '1 año', 'days' => 365, 'price' => 130.0],
        ];
    }

    private function get(int $tenantId, string $key): ?string
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT value FROM settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND `group` = ? AND `key` = ? ORDER BY tenant_id DESC LIMIT 1',
            [$tenantId, 'billing', $key]
        );

        return $row ? (string) $row['value'] : null;
    }

    /**
     * Lee un setting y lo descifra si está guardado con SecretCrypt.
     * Los valores legacy en texto plano siguen funcionando.
     */
    private function getDecrypted(int $tenantId, string $key): ?string
    {
        $value = $this->get($tenantId, $key);
        if ($value === null) {
            return null;
        }

        return (new SecretCrypt())->decrypt($value);
    }

    private function set(int $tenantId, string $key, string $value, string $type): void
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, 'billing', $key]
        );

        if ($existing) {
            $db->update('settings', ['value' => $value, 'type' => $type], 'id = ?', [$existing['id']]);
        } else {
            $db->insert('settings', [
                'tenant_id' => $tenantId,
                'group' => 'billing',
                'key' => $key,
                'value' => $value,
                'type' => $type,
            ]);
        }
    }
}
