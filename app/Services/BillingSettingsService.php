<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Configuración de cobros: el "concepto" que ve el cliente en Stripe (siempre el
 * mismo, ej. "Digital services") y los presets de renovación rápida (duración +
 * precio) que se usan a nivel interno para sumar días y registrar lo cobrado.
 *
 * Stripe admite claves Test y Live guardadas a la vez; el modo activo decide
 * qué secret/publishable se usa en checkout y cuál whsec se prueba primero.
 */
final class BillingSettingsService
{
    private const DEFAULT_CONCEPT = 'Digital services';

    public const STRIPE_MODE_TEST = 'test';
    public const STRIPE_MODE_LIVE = 'live';

    /** @var array<int, true> */
    private static array $migratedTenants = [];

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

    /** Preset de mayor duración (p. ej. 1 año) para avisos y reenganche. */
    /** @return array{label: string, days: int, price: float}|null */
    public function yearRenewalPreset(int $tenantId): ?array
    {
        $best = null;
        foreach ($this->getRenewalPresets($tenantId) as $preset) {
            $days = (int) ($preset['days'] ?? 0);
            if ($days <= 0 || (float) ($preset['price'] ?? 0) <= 0) {
                continue;
            }
            if ($best === null || $days > (int) $best['days']) {
                $best = $preset;
            }
        }

        return $best;
    }

    public function yearPrice(int $tenantId): float
    {
        $preset = $this->yearRenewalPreset($tenantId);

        return $preset !== null ? round((float) $preset['price'], 2) : 0.0;
    }

    public static function formatMoney(float $amount): string
    {
        $amount = round($amount, 2);

        return fmod($amount, 1.0) < 0.001 ? (string) (int) $amount : number_format($amount, 2, ',', '');
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
        $this->syncSubscriptionPlansFromPresets($tenantId, $clean);
    }

    /**
     * Alinea la tabla subscription_plans con los presets de Configuración → Facturación
     * (la página /billing y el portal de planes antiguos leían seeds 4.99/9.99/…).
     *
     * @param array<int, array{label: string, days: int, price: float}>|null $presets
     */
    public function syncSubscriptionPlansFromPresets(int $tenantId, ?array $presets = null): void
    {
        $presets ??= $this->getRenewalPresets($tenantId);
        $db = Database::getInstance();

        // Seeds de demo: Básico / Estándar / Premium / Anual Premium
        try {
            $db->query(
                "UPDATE subscription_plans
                 SET is_active = 0
                 WHERE tenant_id = ?
                   AND slug IN ('basic', 'standard', 'premium', 'premium-yearly')",
                [$tenantId]
            );
        } catch (\Throwable) {
        }

        $keepSlugs = [];
        $sort = 0;
        foreach ($presets as $preset) {
            $days = (int) ($preset['days'] ?? 0);
            $price = round((float) ($preset['price'] ?? 0), 2);
            $label = trim((string) ($preset['label'] ?? ''));
            if ($days <= 0 || $price <= 0 || $label === '') {
                continue;
            }

            $slug = 'renew-' . $days;
            $keepSlugs[] = $slug;
            $interval = $this->daysToPlanInterval($days);
            $features = json_encode(['days' => $days, 'source' => 'renewal_presets'], JSON_UNESCAPED_UNICODE);
            $description = $days . ' días · precio de Configuración → Facturación';

            $existing = $db->fetchOne(
                'SELECT id FROM subscription_plans WHERE tenant_id = ? AND slug = ? LIMIT 1',
                [$tenantId, $slug]
            );
            if ($existing) {
                $db->update('subscription_plans', [
                    'name' => $label,
                    'description' => $description,
                    'price' => $price,
                    'currency' => 'EUR',
                    'interval' => $interval,
                    'features' => $features,
                    'is_active' => 1,
                    'sort_order' => $sort,
                ], 'id = ?', [$existing['id']]);
            } else {
                $db->insert('subscription_plans', [
                    'tenant_id' => $tenantId,
                    'name' => $label,
                    'slug' => $slug,
                    'description' => $description,
                    'price' => $price,
                    'currency' => 'EUR',
                    'interval' => $interval,
                    'trial_days' => 0,
                    'max_streams' => 2,
                    'max_devices' => 5,
                    'features' => $features,
                    'is_active' => 1,
                    'sort_order' => $sort,
                ]);
            }
            $sort++;
        }

        // Desactiva otros renew-* que ya no estén en presets
        if ($keepSlugs !== []) {
            $placeholders = implode(',', array_fill(0, count($keepSlugs), '?'));
            try {
                $db->query(
                    "UPDATE subscription_plans
                     SET is_active = 0
                     WHERE tenant_id = ?
                       AND slug LIKE 'renew-%'
                       AND slug NOT IN ({$placeholders})",
                    array_merge([$tenantId], $keepSlugs)
                );
            } catch (\Throwable) {
            }
        }
    }

    private function daysToPlanInterval(int $days): string
    {
        return match (true) {
            $days >= 330 => 'yearly',
            $days >= 75 => 'quarterly',
            $days >= 14 => 'monthly',
            $days >= 7 => 'weekly',
            default => 'daily',
        };
    }

    public function getExtraAccountPrice(int $tenantId): float
    {
        $raw = $this->get($tenantId, 'shop_extra_account_price');
        if ($raw === null || trim($raw) === '') {
            return PortalShopService::DEFAULT_EXTRA_ACCOUNT;
        }
        $n = (float) $raw;

        return $n > 0 ? round($n, 2) : PortalShopService::DEFAULT_EXTRA_ACCOUNT;
    }

    public function getExtraStreamMonthlyPrice(int $tenantId): float
    {
        $raw = $this->get($tenantId, 'shop_extra_stream_month');
        if ($raw === null || trim($raw) === '') {
            return PortalShopService::DEFAULT_EXTRA_STREAM_MONTH;
        }
        $n = (float) $raw;

        return $n > 0 ? round($n, 2) : PortalShopService::DEFAULT_EXTRA_STREAM_MONTH;
    }

    public function saveShopExtraPrices(int $tenantId, float $extraAccount, float $extraStreamMonth): void
    {
        $account = max(0.01, round($extraAccount, 2));
        $stream = max(0.01, round($extraStreamMonth, 2));
        $this->set($tenantId, 'shop_extra_account_price', (string) $account, 'string');
        $this->set($tenantId, 'shop_extra_stream_month', (string) $stream, 'string');
    }

    /**
     * Modo Stripe activo: test | live.
     * Migra claves legacy (stripe_secret_key única) la primera vez que se lee.
     */
    public function getStripeMode(int $tenantId): string
    {
        $this->migrateLegacyStripeKeys($tenantId);

        $stored = $this->get($tenantId, 'stripe_mode');
        if ($stored === self::STRIPE_MODE_LIVE || $stored === self::STRIPE_MODE_TEST) {
            return $stored;
        }

        $liveSecret = $this->rawStripeSecretForMode($tenantId, self::STRIPE_MODE_LIVE);
        $testSecret = $this->rawStripeSecretForMode($tenantId, self::STRIPE_MODE_TEST);
        if ($liveSecret !== '' && $testSecret === '') {
            return self::STRIPE_MODE_LIVE;
        }

        return self::STRIPE_MODE_TEST;
    }

    public function saveStripeMode(int $tenantId, string $mode): void
    {
        $mode = $mode === self::STRIPE_MODE_LIVE ? self::STRIPE_MODE_LIVE : self::STRIPE_MODE_TEST;
        $this->set($tenantId, 'stripe_mode', $mode, 'string');
    }

    /**
     * Claves de Stripe del modo activo. Si el tenant no ha guardado las suyas,
     * se cae a las variables de entorno / config/payments.php.
     */
    public function getStripeSecretKey(int $tenantId, ?string $mode = null): string
    {
        $this->migrateLegacyStripeKeys($tenantId);
        $mode = $this->normalizeMode($mode ?? $this->getStripeMode($tenantId));

        $value = $this->rawStripeSecretForMode($tenantId, $mode);
        if ($value !== '') {
            return $value;
        }

        return $this->envSecretMatchingMode($mode);
    }

    public function getStripePublishableKey(int $tenantId, ?string $mode = null): string
    {
        $this->migrateLegacyStripeKeys($tenantId);
        $mode = $this->normalizeMode($mode ?? $this->getStripeMode($tenantId));

        $value = $this->get($tenantId, 'stripe_publishable_key_' . $mode);
        if ($value !== null && trim($value) !== '') {
            return trim($value);
        }

        return $this->envPublishableMatchingMode($mode);
    }

    public function getStripeWebhookSecret(int $tenantId, ?string $mode = null): string
    {
        $this->migrateLegacyStripeKeys($tenantId);
        $mode = $this->normalizeMode($mode ?? $this->getStripeMode($tenantId));

        $value = $this->rawStripeWebhookForMode($tenantId, $mode);
        if ($value !== '') {
            return $value;
        }

        return $this->envWebhookMatchingMode($mode);
    }

    /**
     * Secrets de webhook en orden: modo activo primero, luego el otro.
     * Así el mismo endpoint puede recibir eventos de ambos dashboards Stripe.
     *
     * @return list<string>
     */
    public function getStripeWebhookSecretsForVerification(int $tenantId): array
    {
        $this->migrateLegacyStripeKeys($tenantId);
        $active = $this->getStripeMode($tenantId);
        $other = $active === self::STRIPE_MODE_LIVE ? self::STRIPE_MODE_TEST : self::STRIPE_MODE_LIVE;

        $secrets = [];
        foreach ([$active, $other] as $mode) {
            $secret = $this->getStripeWebhookSecret($tenantId, $mode);
            if ($secret !== '' && !in_array($secret, $secrets, true)) {
                $secrets[] = $secret;
            }
        }

        return $secrets;
    }

    public function hasTenantStripeSecretKey(int $tenantId, ?string $mode = null): bool
    {
        $this->migrateLegacyStripeKeys($tenantId);
        $mode = $this->normalizeMode($mode ?? $this->getStripeMode($tenantId));

        return $this->rawStripeSecretForMode($tenantId, $mode) !== '';
    }

    public function hasStripeWebhookSecret(int $tenantId, ?string $mode = null): bool
    {
        $this->migrateLegacyStripeKeys($tenantId);
        $mode = $this->normalizeMode($mode ?? $this->getStripeMode($tenantId));

        return $this->rawStripeWebhookForMode($tenantId, $mode) !== '';
    }

    /**
     * Snapshot para la UI de Facturación (máscaras, badges, campos por modo).
     *
     * @return array{
     *   mode: string,
     *   test: array{has_secret: bool, secret_masked: string, publishable: string, has_webhook: bool},
     *   live: array{has_secret: bool, secret_masked: string, publishable: string, has_webhook: bool},
     *   active_configured: bool
     * }
     */
    public function getStripeUiState(int $tenantId): array
    {
        $this->migrateLegacyStripeKeys($tenantId);
        $mode = $this->getStripeMode($tenantId);

        $build = function (string $m) use ($tenantId): array {
            $secret = $this->rawStripeSecretForMode($tenantId, $m);
            if ($secret === '') {
                $secret = $this->envSecretMatchingMode($m);
            }
            $publishable = $this->get($tenantId, 'stripe_publishable_key_' . $m);
            $publishable = $publishable !== null && trim($publishable) !== ''
                ? trim($publishable)
                : $this->envPublishableMatchingMode($m);
            $webhook = $this->rawStripeWebhookForMode($tenantId, $m);
            if ($webhook === '') {
                $webhook = $this->envWebhookMatchingMode($m);
            }

            return [
                'has_secret' => $secret !== '',
                'secret_masked' => $this->maskKey($secret),
                'publishable' => $publishable,
                'has_webhook' => $webhook !== '',
            ];
        };

        $test = $build(self::STRIPE_MODE_TEST);
        $live = $build(self::STRIPE_MODE_LIVE);
        $active = $mode === self::STRIPE_MODE_LIVE ? $live : $test;

        return [
            'mode' => $mode,
            'test' => $test,
            'live' => $live,
            'active_configured' => (bool) $active['has_secret'],
        ];
    }

    /**
     * Guarda modo + claves test/live. Campos vacíos dejan intacta la clave ya guardada.
     *
     * @param array{
     *   mode?: string|null,
     *   test_secret?: string|null,
     *   test_publishable?: string|null,
     *   test_webhook?: string|null,
     *   live_secret?: string|null,
     *   live_publishable?: string|null,
     *   live_webhook?: string|null
     * } $input
     * @return list<string> Errores de validación (vacío = OK)
     */
    public function saveStripeConfig(int $tenantId, array $input): array
    {
        $this->migrateLegacyStripeKeys($tenantId);
        $errors = [];

        if (array_key_exists('mode', $input) && $input['mode'] !== null && trim((string) $input['mode']) !== '') {
            $mode = trim((string) $input['mode']);
            if ($mode !== self::STRIPE_MODE_TEST && $mode !== self::STRIPE_MODE_LIVE) {
                $errors[] = 'El modo de Stripe debe ser test o live.';
            } else {
                $this->saveStripeMode($tenantId, $mode);
            }
        }

        $errors = array_merge(
            $errors,
            $this->saveStripeKeysForMode(
                $tenantId,
                self::STRIPE_MODE_TEST,
                $input['test_secret'] ?? null,
                $input['test_publishable'] ?? null,
                $input['test_webhook'] ?? null,
            ),
            $this->saveStripeKeysForMode(
                $tenantId,
                self::STRIPE_MODE_LIVE,
                $input['live_secret'] ?? null,
                $input['live_publishable'] ?? null,
                $input['live_webhook'] ?? null,
            ),
        );

        return $errors;
    }

    /**
     * Compatibilidad: guarda en el modo activo (o el inferido por el prefijo de la secret).
     *
     * @return list<string>
     */
    public function saveStripeKeys(int $tenantId, ?string $secretKey, ?string $publishableKey, ?string $webhookSecret): array
    {
        $this->migrateLegacyStripeKeys($tenantId);

        $mode = $this->getStripeMode($tenantId);
        if ($secretKey !== null && trim($secretKey) !== '') {
            $inferred = $this->inferModeFromSecret(trim($secretKey));
            if ($inferred !== null) {
                $mode = $inferred;
            }
        }

        $payload = [
            'mode' => $mode,
            'test_secret' => null,
            'test_publishable' => null,
            'test_webhook' => null,
            'live_secret' => null,
            'live_publishable' => null,
            'live_webhook' => null,
        ];

        if ($mode === self::STRIPE_MODE_LIVE) {
            $payload['live_secret'] = $secretKey;
            $payload['live_publishable'] = $publishableKey;
            $payload['live_webhook'] = $webhookSecret;
        } else {
            $payload['test_secret'] = $secretKey;
            $payload['test_publishable'] = $publishableKey;
            $payload['test_webhook'] = $webhookSecret;
        }

        return $this->saveStripeConfig($tenantId, $payload);
    }

    /**
     * @return list<string>
     */
    private function saveStripeKeysForMode(
        int $tenantId,
        string $mode,
        ?string $secretKey,
        ?string $publishableKey,
        ?string $webhookSecret,
    ): array {
        $errors = [];
        $crypt = new SecretCrypt();
        $label = $mode === self::STRIPE_MODE_LIVE ? 'Live' : 'Test';

        if ($secretKey !== null && trim($secretKey) !== '') {
            $secretKey = trim($secretKey);
            if (str_starts_with($secretKey, 'pk_')) {
                $errors[] = "[{$label}] La clave secreta no puede ser la publishable (pk_...). Usa sk_{$mode}_....";
            } elseif (str_starts_with($secretKey, 'whsec_')) {
                $errors[] = "[{$label}] Has pegado el webhook secret (whsec_...) en la clave secreta. La secreta empieza por sk_.";
            } elseif (!preg_match('/^(sk|rk)_' . preg_quote($mode, '/') . '_/', $secretKey)) {
                $errors[] = "[{$label}] La clave secreta debe empezar por sk_{$mode}_ o rk_{$mode}_.";
            } else {
                $this->set($tenantId, 'stripe_secret_key_' . $mode, $crypt->encrypt($secretKey), 'encrypted');
            }
        }

        if ($publishableKey !== null && trim($publishableKey) !== '') {
            $publishableKey = trim($publishableKey);
            if (str_starts_with($publishableKey, 'sk_') || str_starts_with($publishableKey, 'rk_')) {
                $errors[] = "[{$label}] La clave pública debe empezar por pk_{$mode}_, no por sk_.";
            } elseif (!preg_match('/^pk_' . preg_quote($mode, '/') . '_/', $publishableKey)) {
                $errors[] = "[{$label}] La clave pública debe empezar por pk_{$mode}_.";
            } else {
                $this->set($tenantId, 'stripe_publishable_key_' . $mode, $publishableKey, 'string');
            }
        }

        if ($webhookSecret !== null && trim($webhookSecret) !== '') {
            $webhookSecret = trim($webhookSecret);
            if (!str_starts_with($webhookSecret, 'whsec_')) {
                $errors[] = "[{$label}] El webhook signing secret debe empezar por whsec_....";
            } else {
                $this->set($tenantId, 'stripe_webhook_secret_' . $mode, $crypt->encrypt($webhookSecret), 'encrypted');
            }
        }

        return $errors;
    }

    /**
     * Copia stripe_secret_key / publishable / webhook legacy a *_test o *_live
     * según el prefijo (sk_test_ → test, sk_live_ → live). Idempotente.
     */
    public function migrateLegacyStripeKeys(int $tenantId): void
    {
        if (isset(self::$migratedTenants[$tenantId])) {
            return;
        }
        self::$migratedTenants[$tenantId] = true;

        $crypt = new SecretCrypt();

        $legacySecretRaw = $this->get($tenantId, 'stripe_secret_key');
        $legacySecret = $legacySecretRaw !== null ? trim((string) $crypt->decrypt($legacySecretRaw)) : '';
        $legacyPub = $this->get($tenantId, 'stripe_publishable_key');
        $legacyPub = $legacyPub !== null ? trim($legacyPub) : '';
        $legacyWhRaw = $this->get($tenantId, 'stripe_webhook_secret');
        $legacyWh = $legacyWhRaw !== null ? trim((string) $crypt->decrypt($legacyWhRaw)) : '';

        $modeFromSecret = $legacySecret !== '' ? $this->inferModeFromSecret($legacySecret) : null;
        $modeFromPub = $legacyPub !== '' ? $this->inferModeFromPublishable($legacyPub) : null;
        $mode = $modeFromSecret ?? $modeFromPub;

        $existingMode = $this->get($tenantId, 'stripe_mode');
        if (($existingMode === null || trim($existingMode) === '') && $mode !== null) {
            $this->set($tenantId, 'stripe_mode', $mode, 'string');
        }

        if ($mode === null) {
            // Sin prefijo claro: si hay secret legacy y no hay claves por modo, asumir test.
            if ($legacySecret !== ''
                && $this->rawStripeSecretForMode($tenantId, self::STRIPE_MODE_TEST) === ''
                && $this->rawStripeSecretForMode($tenantId, self::STRIPE_MODE_LIVE) === ''
            ) {
                $mode = self::STRIPE_MODE_TEST;
                if ($existingMode === null || trim((string) $existingMode) === '') {
                    $this->set($tenantId, 'stripe_mode', $mode, 'string');
                }
            } else {
                return;
            }
        }

        if ($legacySecret !== '' && $this->rawStripeSecretForMode($tenantId, $mode) === '') {
            $this->set($tenantId, 'stripe_secret_key_' . $mode, $crypt->encrypt($legacySecret), 'encrypted');
        }

        if ($legacyPub !== '' && ($this->get($tenantId, 'stripe_publishable_key_' . $mode) ?? '') === '') {
            $this->set($tenantId, 'stripe_publishable_key_' . $mode, $legacyPub, 'string');
        }

        if ($legacyWh !== '' && $this->rawStripeWebhookForMode($tenantId, $mode) === '') {
            $this->set($tenantId, 'stripe_webhook_secret_' . $mode, $crypt->encrypt($legacyWh), 'encrypted');
        }
    }

    /** @return array<int, array{label: string, days: int, price: float}> */
    private function defaultPresets(): array
    {
        return [
            ['label' => '1 mes', 'days' => 30, 'price' => 15.0],
            ['label' => '3 meses', 'days' => 90, 'price' => 40.0],
            ['label' => '6 meses', 'days' => 180, 'price' => 70.0],
            ['label' => '1 año', 'days' => 365, 'price' => 70.0],
        ];
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === self::STRIPE_MODE_LIVE ? self::STRIPE_MODE_LIVE : self::STRIPE_MODE_TEST;
    }

    private function inferModeFromSecret(string $secret): ?string
    {
        if (preg_match('/^(sk|rk)_live_/', $secret)) {
            return self::STRIPE_MODE_LIVE;
        }
        if (preg_match('/^(sk|rk)_test_/', $secret)) {
            return self::STRIPE_MODE_TEST;
        }

        return null;
    }

    private function inferModeFromPublishable(string $key): ?string
    {
        if (str_starts_with($key, 'pk_live_')) {
            return self::STRIPE_MODE_LIVE;
        }
        if (str_starts_with($key, 'pk_test_')) {
            return self::STRIPE_MODE_TEST;
        }

        return null;
    }

    private function rawStripeSecretForMode(int $tenantId, string $mode): string
    {
        $value = $this->getDecrypted($tenantId, 'stripe_secret_key_' . $mode);

        return $value !== null ? trim($value) : '';
    }

    private function rawStripeWebhookForMode(int $tenantId, string $mode): string
    {
        $value = $this->getDecrypted($tenantId, 'stripe_webhook_secret_' . $mode);

        return $value !== null ? trim($value) : '';
    }

    private function envSecretMatchingMode(string $mode): string
    {
        $env = trim((string) config('payments.stripe.secret_key', env('STRIPE_SECRET_KEY', '')));
        if ($env === '') {
            return '';
        }
        $envMode = $this->inferModeFromSecret($env);

        return ($envMode === null || $envMode === $mode) ? $env : '';
    }

    private function envPublishableMatchingMode(string $mode): string
    {
        $env = trim((string) config('payments.stripe.publishable_key', env('STRIPE_PUBLISHABLE_KEY', '')));
        if ($env === '') {
            return '';
        }
        $envMode = $this->inferModeFromPublishable($env);

        return ($envMode === null || $envMode === $mode) ? $env : '';
    }

    private function envWebhookMatchingMode(string $mode): string
    {
        // Un solo whsec en .env: se asocia al mismo modo que STRIPE_SECRET_KEY (o test).
        $env = trim((string) config('payments.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', '')));
        if ($env === '') {
            return '';
        }

        $envSecret = trim((string) config('payments.stripe.secret_key', env('STRIPE_SECRET_KEY', '')));
        $envMode = $envSecret !== '' ? $this->inferModeFromSecret($envSecret) : self::STRIPE_MODE_TEST;
        $envMode = $envMode ?? self::STRIPE_MODE_TEST;

        return $envMode === $mode ? $env : '';
    }

    private function maskKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        $tail = substr($key, -4);

        return str_repeat('•', 10) . $tail;
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
