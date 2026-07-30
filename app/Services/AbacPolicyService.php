<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Attribute-based policy evaluation engine.
 */
final class AbacPolicyService
{
    /** @var list<array<string, mixed>> */
    private array $policies;

    public function __construct()
    {
        $this->policies = config('abac.policies', $this->defaultPolicies());
    }

    public function evaluate(User $user, string $action, array $resource = []): bool
    {
        $context = [
            'user_id' => $user->id,
            'role_id' => $user->role_id,
            'tenant_id' => $user->tenant_id,
            'action' => $action,
            'resource' => $resource,
            'hour' => (int) date('G'),
        ];

        foreach ($this->policies as $policy) {
            if (($policy['action'] ?? '') !== $action && ($policy['action'] ?? '') !== '*') {
                continue;
            }

            if (!$this->matchConditions($policy['conditions'] ?? [], $context)) {
                continue;
            }

            return ($policy['effect'] ?? 'deny') === 'allow';
        }

        return (int) $user->role_id <= 2;
    }

    /** @param array<string, mixed> $conditions */
    /** @param array<string, mixed> $context */
    private function matchConditions(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $expected) {
            $actual = $context[$key] ?? ($context['resource'][$key] ?? null);
            if (is_array($expected)) {
                if (!in_array($actual, $expected, false)) {
                    return false;
                }
            } elseif ($actual != $expected) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array<string, mixed>> */
    private function defaultPolicies(): array
    {
        return [
            [
                'action' => 'billing.manage',
                'effect' => 'allow',
                'conditions' => ['role_id' => [1, 2, 3]],
            ],
            [
                'action' => 'servers.delete',
                'effect' => 'allow',
                'conditions' => ['role_id' => [1, 2]],
            ],
            [
                'action' => '*',
                'effect' => 'deny',
                'conditions' => ['hour' => range(0, 5)],
                'description' => 'Deny all actions between 00:00-05:59 for non-admins',
            ],
        ];
    }
}
