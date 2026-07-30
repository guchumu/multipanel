<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Core\Database;

/**
 * User data access layer.
 */
class UserRepository
{
    public function findByEmail(string $email): ?User
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM `users` WHERE `email` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$email]
        );

        return $row ? new User($row) : null;
    }

    public function findByUsername(string $username): ?User
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM `users` WHERE `username` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$username]
        );

        return $row ? new User($row) : null;
    }

    /** @return array<int, User> */
    public function paginate(int $tenantId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM `users` WHERE `tenant_id` = ? AND `deleted_at` IS NULL ORDER BY `created_at` DESC LIMIT ? OFFSET ?',
            [$tenantId, $perPage, $offset]
        );

        return array_map(fn ($row) => new User($row), $rows);
    }

    public function count(int $tenantId): int
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT COUNT(*) as total FROM `users` WHERE `tenant_id` = ? AND `deleted_at` IS NULL',
            [$tenantId]
        );

        return (int) ($row['total'] ?? 0);
    }
}
