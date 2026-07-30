<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Repositories\MediaUserRepository;
use App\Services\PasswordService;
use Core\Logger;
use Core\Session;

/**
 * Authentication for client self-service portal.
 */
final class PortalAuthService
{
    public function __construct(
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
        private PasswordService $passwords = new PasswordService(),
    ) {
    }

    public function attempt(string $username, string $password): ?MediaUser
    {
        $db = \Core\Database::getInstance();
        $row = $db->fetchOne(
            'SELECT * FROM media_users WHERE (username = ? OR email = ?) AND deleted_at IS NULL LIMIT 1',
            [$username, $username]
        );

        if (!$row) {
            return null;
        }

        $user = new MediaUser($row);

        if (!$this->passwords->verify($password, $user->password ?? '')) {
            return null;
        }

        if (!in_array($user->status, ['active', 'invited'], true)) {
            return null;
        }

        $this->login($user);
        return $user;
    }

    public function login(MediaUser $user): void
    {
        $session = Session::getInstance();
        $session->regenerate();
        $session->set('portal_user_id', $user->id);
        $session->set('portal_user_uuid', $user->uuid);

        $user->last_login_at = now()->format('Y-m-d H:i:s');
        $user->last_login_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user->save();

        Logger::info('Portal user logged in', ['user_id' => $user->id]);
    }

    public function logout(): void
    {
        Session::getInstance()->remove('portal_user_id');
        Session::getInstance()->remove('portal_user_uuid');
    }

    public function user(): ?MediaUser
    {
        $id = Session::getInstance()->get('portal_user_id');
        return $id ? MediaUser::find((int) $id) : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }
}
