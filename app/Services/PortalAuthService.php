<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Repositories\MediaUserRepository;
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

    /**
     * @return array{ok: bool, user?: MediaUser, error?: string}
     */
    public function attemptWithReason(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Introduce tu usuario y contraseña.'];
        }

        $db = \Core\Database::getInstance();
        $row = $db->fetchOne(
            'SELECT * FROM media_users WHERE (username = ? OR email = ?) AND deleted_at IS NULL LIMIT 1',
            [$username, $username]
        );

        if (!$row) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
        }

        $user = new MediaUser($row);
        $hash = (string) ($user->password ?? '');

        if ($hash === '' || !$this->passwords->verify($password, $hash)) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
        }

        $status = (string) ($user->status ?? '');
        if (in_array($status, ['blocked', 'deleted'], true)) {
            return ['ok' => false, 'error' => 'Tu cuenta está bloqueada. Contacta con soporte.'];
        }
        if ($status === 'suspended') {
            return ['ok' => false, 'error' => 'Tu cuenta está suspendida. Contacta con soporte para reactivarla.'];
        }
        if ($status === 'pending') {
            return ['ok' => false, 'error' => 'Tu cuenta aún no está activa. Espera la confirmación o contacta con soporte.'];
        }
        // active, invited, expired → permitir (expired puede renovar en el portal)
        if (!in_array($status, ['active', 'invited', 'expired'], true)) {
            return ['ok' => false, 'error' => 'No puedes acceder con el estado actual de tu cuenta.'];
        }

        $this->login($user);

        return ['ok' => true, 'user' => $user];
    }

    public function attempt(string $username, string $password): ?MediaUser
    {
        $result = $this->attemptWithReason($username, $password);

        return !empty($result['ok']) ? ($result['user'] ?? null) : null;
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
