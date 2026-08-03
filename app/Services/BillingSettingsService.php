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

    private function set(int $tenantId, string $key, string $value, string $type): void
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, 'billing', $key]
        );

        if ($existing) {
            $db->update('settings', ['value' => $value], 'id = ?', [$existing['id']]);
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
