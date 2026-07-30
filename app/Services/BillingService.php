<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
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

        $db->update('subscriptions', [
            'status' => 'active',
            'ends_at' => date('Y-m-d H:i:s', strtotime('+1 month')),
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

        try {
            (new InvoiceService())->generateForSubscription($subscriptionId);
            \App\Services\EventHub::push('subscription.paid', ['subscription_id' => $subscriptionId]);
        } catch (\Throwable) {
        }

        return true;
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
