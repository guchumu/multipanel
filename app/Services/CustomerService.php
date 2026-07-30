<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * CRM customer management service.
 */
final class CustomerService
{
    /** @return list<array<string, mixed>> */
    public function list(int $tenantId, ?string $search = null, int $limit = 50): array
    {
        $sql = "SELECT c.*, mu.username as media_username,
                (SELECT COUNT(*) FROM subscriptions s WHERE s.customer_id = c.id AND s.status = 'active') as active_subs
                FROM customers c
                LEFT JOIN media_users mu ON mu.id = c.media_user_id
                WHERE c.tenant_id = ?";
        $params = [$tenantId];

        if ($search) {
            $sql .= ' AND (c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company LIKE ?)';
            $term = '%' . $search . '%';
            $params = array_merge($params, [$term, $term, $term, $term]);
        }

        $sql .= ' ORDER BY c.created_at DESC LIMIT ?';
        $params[] = $limit;

        return Database::getInstance()->fetchAll($sql, $params);
    }

    public function findByUuid(string $uuid): ?array
    {
        return Database::getInstance()->fetchOne(
            'SELECT c.*, mu.username as media_username FROM customers c
             LEFT JOIN media_users mu ON mu.id = c.media_user_id WHERE c.uuid = ?',
            [$uuid]
        );
    }

    public function create(int $tenantId, array $data): int
    {
        return (new BillingService())->createCustomer($tenantId, $data);
    }

    public function update(string $uuid, array $data): bool
    {
        $customer = $this->findByUuid($uuid);
        if (!$customer) {
            return false;
        }

        $fields = ['first_name', 'last_name', 'email', 'phone', 'company', 'address', 'city', 'country', 'tax_id', 'status', 'notes'];
        $update = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (empty($update)) {
            return false;
        }

        Database::getInstance()->update('customers', $update, 'uuid = ?', [$uuid]);
        return true;
    }

    public function stats(int $tenantId): array
    {
        $db = Database::getInstance();
        return [
            'total' => (int) ($db->fetchOne('SELECT COUNT(*) as c FROM customers WHERE tenant_id = ?', [$tenantId])['c'] ?? 0),
            'active' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM customers WHERE tenant_id = ? AND status = 'active'", [$tenantId])['c'] ?? 0),
            'prospect' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM customers WHERE tenant_id = ? AND status = 'prospect'", [$tenantId])['c'] ?? 0),
            'churned' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM customers WHERE tenant_id = ? AND status = 'churned'", [$tenantId])['c'] ?? 0),
        ];
    }
}
