<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Services\Payments\PaymentLinkService;
use App\Services\Payments\PaymentService;
use Core\Database;
use Core\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Subscription and billing management service.
 */
final class BillingService
{
    public function createPlan(int $tenantId, array $data): int
    {
        return Database::getInstance()->insert('subscription_plans', [
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? strtolower(str_replace(' ', '-', $data['name'])),
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? 0,
            'currency' => $data['currency'] ?? 'EUR',
            'interval' => $data['interval'] ?? 'monthly',
            'trial_days' => $data['trial_days'] ?? 0,
            'max_streams' => $data['max_streams'] ?? 1,
            'max_devices' => $data['max_devices'] ?? 5,
            'features' => isset($data['features']) ? json_encode($data['features']) : null,
        ]);
    }

    public function createCustomer(int $tenantId, array $data): int
    {
        return Database::getInstance()->insert('customers', [
            'tenant_id' => $tenantId,
            'uuid' => Uuid::uuid4()->toString(),
            'email' => $data['email'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'media_user_id' => $data['media_user_id'] ?? null,
            'status' => $data['status'] ?? 'prospect',
        ]);
    }

    public function createSubscription(int $tenantId, int $customerId, int $planId, array $options = []): int
    {
        $plan = Database::getInstance()->fetchOne('SELECT * FROM subscription_plans WHERE id = ?', [$planId]);
        if (!$plan) {
            throw new \RuntimeException('Plan no encontrado.');
        }

        $startsAt = $options['starts_at'] ?? date('Y-m-d H:i:s');
        $trialDays = (int) $plan['trial_days'];
        $interval = $plan['interval'];

        $endsAt = match ($interval) {
            'daily' => date('Y-m-d H:i:s', strtotime('+1 day', strtotime($startsAt))),
            'weekly' => date('Y-m-d H:i:s', strtotime('+1 week', strtotime($startsAt))),
            'monthly' => date('Y-m-d H:i:s', strtotime('+1 month', strtotime($startsAt))),
            'quarterly' => date('Y-m-d H:i:s', strtotime('+3 months', strtotime($startsAt))),
            'yearly' => date('Y-m-d H:i:s', strtotime('+1 year', strtotime($startsAt))),
            'lifetime' => null,
            default => date('Y-m-d H:i:s', strtotime('+1 month', strtotime($startsAt))),
        };

        return Database::getInstance()->insert('subscriptions', [
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'plan_id' => $planId,
            'media_user_id' => $options['media_user_id'] ?? null,
            'status' => $trialDays > 0 ? 'trialing' : 'active',
            'gateway' => $options['gateway'] ?? 'manual',
            'amount' => $plan['price'],
            'currency' => $plan['currency'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'trial_ends_at' => $trialDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$trialDays} days")) : null,
        ]);
    }

    public function markPaid(int $subscriptionId): bool
    {
        $db = Database::getInstance();
        $sub = $db->fetchOne('SELECT * FROM subscriptions WHERE id = ?', [$subscriptionId]);
        if (!$sub) {
            return false;
        }

        $meta = json_decode((string) ($sub['metadata'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $plan = $db->fetchOne('SELECT * FROM subscription_plans WHERE id = ?', [$sub['plan_id']]);
        $days = isset($meta['renewal_days'])
            ? (int) $meta['renewal_days']
            : $this->intervalToDays((string) ($plan['interval'] ?? 'monthly'));

        $db->update('subscriptions', [
            'status' => 'active',
            'ends_at' => $days !== null ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null,
        ], 'id = ?', [$subscriptionId]);

        $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad((string) $subscriptionId, 6, '0', STR_PAD_LEFT);

        $db->insert('invoices', [
            'tenant_id' => $sub['tenant_id'],
            'customer_id' => $sub['customer_id'],
            'subscription_id' => $subscriptionId,
            'number' => $invoiceNumber,
            'status' => 'paid',
            'subtotal' => $sub['amount'],
            'tax' => 0,
            'total' => $sub['amount'],
            'currency' => $sub['currency'],
            'paid_at' => date('Y-m-d H:i:s'),
            'gateway' => $sub['gateway'],
        ]);

        // Si la suscripción está ligada a un usuario media (Plex/Jellyfin), el pago
        // le suma automáticamente los días correspondientes y reactiva su acceso.
        if (!empty($sub['media_user_id']) && $days !== null) {
            try {
                $mediaUser = MediaUser::find((int) $sub['media_user_id']);
                if ($mediaUser !== null) {
                    (new MediaUserManagementService())->applyPayment(
                        $mediaUser,
                        $days,
                        (float) $sub['amount'],
                        (string) $sub['currency']
                    );
                    $this->applyShopExtras($mediaUser, $meta, $days);
                }
            } catch (\Throwable $e) {
                Logger::error('No se pudo aplicar el pago al usuario media', [
                    'subscription_id' => $subscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            (new InvoiceService())->generateForSubscription($subscriptionId);
            \App\Services\EventHub::push('subscription.paid', ['subscription_id' => $subscriptionId]);
        } catch (\Throwable) {
        }

        return true;
    }

    /**
     * Busca (o crea) el cliente de facturación asociado a un usuario media,
     * para poder generarle enlaces de pago (Stripe, PayPal, etc.).
     */
    public function findOrCreateCustomerForMediaUser(int $tenantId, MediaUser $user): int
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT id FROM customers WHERE tenant_id = ? AND media_user_id = ? LIMIT 1',
            [$tenantId, $user->id]
        );
        if ($row) {
            return (int) $row['id'];
        }

        $email = trim((string) ($user->email ?? ''));

        return $this->createCustomer($tenantId, [
            'email' => $email !== '' ? $email : ($user->username . '@sin-email.local'),
            'first_name' => $user->display_name ?? $user->username,
            'media_user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    /**
     * Crea una suscripción "pendiente de pago" para una renovación puntual
     * generada desde la ficha del usuario (no asociada a un plan fijo).
     */
    public function createRenewalSubscription(
        int $tenantId,
        int $customerId,
        ?int $mediaUserId,
        float $amount,
        string $currency,
        int $days,
        string $gateway = 'stripe',
        array $shopMeta = [],
    ): int {
        $meta = array_merge(['renewal_days' => $days], $shopMeta);

        return Database::getInstance()->insert('subscriptions', [
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'plan_id' => $this->ensureManualRenewalPlan($tenantId),
            'media_user_id' => $mediaUserId,
            'status' => 'trialing',
            'gateway' => $gateway,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'starts_at' => date('Y-m-d H:i:s'),
            'ends_at' => null,
            'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Genera un enlace de pago (checkout) para renovar manualmente a un usuario media,
     * sumándole automáticamente los días indicados en cuanto Stripe confirme el cobro.
     *
     * @return array{success: bool, message: string, checkout_url?: string, stripe_checkout_url?: string, short_code?: string|null}
     */
    public function createRenewalCheckout(
        MediaUser $user,
        float $amount,
        string $currency,
        int $days,
        string $gateway = 'stripe',
        array $shopMeta = [],
    ): array {
        if ($amount <= 0 || $days <= 0) {
            return ['success' => false, 'message' => 'El importe y los días deben ser mayores que 0.'];
        }

        $tenantId = (int) $user->tenant_id;
        $billingSettings = new BillingSettingsService();

        if ($gateway === 'stripe' && trim($billingSettings->getStripeSecretKey($tenantId)) === '') {
            return ['success' => false, 'message' => 'Stripe no está configurado: añade tu clave secreta en Ajustes > Facturación.'];
        }

        $customerId = $this->findOrCreateCustomerForMediaUser($tenantId, $user);
        $subscriptionId = $this->createRenewalSubscription(
            $tenantId,
            $customerId,
            (int) $user->id,
            $amount,
            $currency,
            $days,
            $gateway,
            $shopMeta
        );

        // El concepto que ve el cliente es siempre el mismo (ej. "Digital services"),
        // configurable en Ajustes > Facturación. La duración y el usuario nunca se
        // muestran en la pasarela de pago, solo se usan a nivel interno.
        $concept = $billingSettings->getPaymentConcept($tenantId);

        $result = (new PaymentService($this))->checkout($gateway, $amount, $currency, [
            'plan_name' => $concept,
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'media_user_id' => (int) $user->id,
            'tenant_id' => $tenantId,
        ], $tenantId);

        if (empty($result['checkout_url'])) {
            $detail = trim((string) ($result['error'] ?? ''));
            if ($detail === '') {
                $detail = 'error desconocido (revisa storage/logs/multipanel.log y que APP_URL / claves Stripe sean correctas)';
            }

            return ['success' => false, 'message' => 'No se pudo generar el enlace de pago: ' . $detail];
        }

        $stripeUrl = (string) $result['checkout_url'];
        $sessionId = isset($result['session_id']) ? (string) $result['session_id'] : null;
        $expiresAt = null;
        if (isset($result['expires_at']) && is_numeric($result['expires_at'])) {
            $expiresAt = (new \DateTimeImmutable('@' . (int) $result['expires_at']))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get() ?: 'UTC'));
        } elseif ($gateway === 'stripe') {
            // Stripe Checkout Session caduca por defecto a las ~24h.
            $expiresAt = new \DateTimeImmutable('+24 hours');
        }

        $shareUrl = $stripeUrl;
        $short = (new PaymentLinkService())->create(
            $tenantId,
            $stripeUrl,
            (int) $user->id,
            $sessionId,
            $expiresAt,
        );
        if (!empty($short['success']) && !empty($short['short_url'])) {
            $shareUrl = (string) $short['short_url'];
        }

        AuditService::log('media_user.payment_link_created', 'media_user', (int) $user->id, null, [
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'days' => $days,
            'gateway' => $gateway,
            'short_url' => $shareUrl !== $stripeUrl ? $shareUrl : null,
        ]);

        return [
            'success' => true,
            'message' => 'Enlace de pago generado.',
            'checkout_url' => $shareUrl,
            'stripe_checkout_url' => $stripeUrl,
            'short_code' => $short['code'] ?? null,
        ];
    }

    private function ensureManualRenewalPlan(int $tenantId): int
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT id FROM subscription_plans WHERE tenant_id = ? AND slug = ? LIMIT 1',
            [$tenantId, 'renovacion-manual']
        );
        if ($row) {
            return (int) $row['id'];
        }

        return $db->insert('subscription_plans', [
            'tenant_id' => $tenantId,
            'name' => 'Renovación manual',
            'slug' => 'renovacion-manual',
            'description' => 'Plan interno usado para generar enlaces de pago puntuales desde la ficha de un usuario.',
            'price' => 0,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'is_active' => 0,
        ]);
    }

    /**
     * Cuentas individuales y visionados contratados desde la tienda del portal.
     *
     * @param array<string, mixed> $meta
     */
    private function applyShopExtras(MediaUser $buyer, array $meta, int $days): void
    {
        $shopServerId = (int) ($meta['server_id'] ?? 0);
        if ($shopServerId > 0 && empty($buyer->server_id)) {
            $buyer->server_id = $shopServerId;
            $buyer->save();
        }

        $accounts = $meta['shop_accounts'] ?? null;
        if (is_array($accounts) && $accounts !== []) {
            foreach ($accounts as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
                $streams = $this->clampShopStreams((int) ($row['streams'] ?? PortalShopService::INCLUDED_STREAMS));
                if ((int) $i === 0) {
                    $buyer->max_streams = $streams;
                    $buyer->max_home_streams = $streams;
                    $buyer->save();
                    continue;
                }
                $this->provisionShopAccount($buyer, $email, $streams, $days, $shopServerId);
            }

            return;
        }

        $extraStreams = max(0, (int) ($meta['extra_streams'] ?? 0));
        if ($extraStreams > 0) {
            $current = (int) ($buyer->max_streams ?? PortalShopService::INCLUDED_STREAMS);
            if ($current <= 0) {
                $current = PortalShopService::INCLUDED_STREAMS;
            }
            $buyer->max_streams = $current + $extraStreams;
            $buyer->save();
        }

        $emails = $meta['extra_emails'] ?? [];
        if (!is_array($emails) || $emails === []) {
            return;
        }

        foreach ($emails as $raw) {
            $this->provisionShopAccount(
                $buyer,
                (string) $raw,
                PortalShopService::INCLUDED_STREAMS,
                $days,
                $shopServerId
            );
        }
    }

    private function clampShopStreams(int $streams): int
    {
        return max(
            PortalShopService::INCLUDED_STREAMS,
            min(PortalShopService::MAX_STREAMS_PER_ACCOUNT, $streams)
        );
    }

    private function provisionShopAccount(MediaUser $buyer, string $rawEmail, int $streams, int $days, int $shopServerId = 0): void
    {
        $email = mb_strtolower(trim($rawEmail));
        $buyerEmail = mb_strtolower(trim((string) ($buyer->email ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $email === $buyerEmail) {
            return;
        }

        $db = Database::getInstance();
        $tenantId = (int) ($buyer->tenant_id ?? 1);
        $exists = $db->fetchOne(
            'SELECT id FROM media_users WHERE tenant_id = ? AND LOWER(TRIM(email)) = ? AND deleted_at IS NULL LIMIT 1',
            [$tenantId, $email]
        );
        if ($exists) {
            $other = MediaUser::find((int) $exists['id']);
            if ($other === null) {
                return;
            }
            if ($days > 0) {
                (new MediaUserManagementService())->addDays($other, $days);
            }
            $current = (int) ($other->max_streams ?? PortalShopService::INCLUDED_STREAMS);
            if ($streams > $current) {
                $other->max_streams = $streams;
                $other->max_home_streams = $streams;
                $other->save();
            }

            return;
        }

        $local = explode('@', $email)[0] ?? '';
        $username = strtolower((string) preg_replace('/[^a-z0-9._-]/', '', $local));
        if (strlen($username) < 3) {
            $username = 'user' . bin2hex(random_bytes(2));
        }
        $dup = $db->fetchOne(
            'SELECT id FROM media_users WHERE tenant_id = ? AND username = ? AND deleted_at IS NULL LIMIT 1',
            [$tenantId, $username]
        );
        if ($dup) {
            $username = substr($username, 0, 16) . '_' . bin2hex(random_bytes(2));
        }

        try {
            $id = $db->insert('media_users', [
                'tenant_id' => $tenantId,
                'uuid' => Uuid::uuid4()->toString(),
                'server_id' => $shopServerId > 0 ? $shopServerId : $buyer->server_id,
                'username' => $username,
                'email' => $email,
                'password' => (new PasswordService())->hash(PortalDefaultPasswordService::DEFAULT_PASSWORD),
                'display_name' => $username,
                'status' => 'pending',
                'max_streams' => $streams,
                'max_home_streams' => $streams,
                'max_devices' => 5,
                'expires_at' => $buyer->expires_at ?? null,
                'notes' => 'Contratado desde el portal por ' . ($buyer->email ?: $buyer->username),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('No se pudo crear usuario extra del portal', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $created = MediaUser::find((int) $id);
        $serverId = (int) ($created?->server_id ?? 0);
        $server = $serverId > 0 ? \App\Models\Server::find($serverId) : null;
        if ($created !== null && $server !== null) {
            try {
                $result = (new MediaUserProvisioningService())->provision($created, $server);
                if (empty($result['success'])) {
                    Logger::warning('Invitación extra del portal pendiente', [
                        'email' => $email,
                        'message' => $result['message'] ?? '',
                    ]);
                }
            } catch (\Throwable $e) {
                Logger::warning('No se pudo invitar usuario extra del portal', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function intervalToDays(string $interval): ?int
    {
        return match ($interval) {
            'daily' => 1,
            'weekly' => 7,
            'monthly' => 30,
            'quarterly' => 90,
            'yearly' => 365,
            'lifetime' => null,
            default => 30,
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function getOverdueSubscriptions(int $tenantId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT s.*, c.email as customer_email, mu.username
             FROM subscriptions s
             JOIN customers c ON c.id = s.customer_id
             LEFT JOIN media_users mu ON mu.id = s.media_user_id
             WHERE s.tenant_id = ? AND s.status = 'past_due'",
            [$tenantId]
        );
    }

    public function markPastDue(int $tenantId): int
    {
        $stmt = Database::getInstance()->query(
            "UPDATE subscriptions SET status = 'past_due'
             WHERE tenant_id = ? AND status = 'active' AND ends_at IS NOT NULL AND ends_at < NOW()",
            [$tenantId]
        );

        return $stmt->rowCount();
    }
}
