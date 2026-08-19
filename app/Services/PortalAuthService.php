<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Repositories\MediaUserRepository;
use Core\Database;
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
        $password = (string) $password;
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Introduce tu email y contraseña.'];
        }

        $candidates = $this->findLoginCandidates($username);
        if ($candidates === []) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
        }

        $matched = null;
        foreach ($candidates as $user) {
            if ($this->passwordMatches($user, $password)) {
                $matched = $user;
                break;
            }
        }

        if ($matched === null) {
            // Si pegan la contraseña por defecto, reparamos hash vacío/roto en todos los candidatos.
            if ($password === PortalDefaultPasswordService::DEFAULT_PASSWORD) {
                $matched = $candidates[0];
                $this->applyDefaultPasswordToCandidates($candidates);
            }
        }

        if ($matched === null) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
        }

        $status = strtolower(trim((string) ($matched->status ?? '')));
        if (in_array($status, ['blocked', 'deleted'], true)) {
            return ['ok' => false, 'error' => 'Tu cuenta está bloqueada. Contacta con soporte.'];
        }
        if ($status === 'suspended') {
            return ['ok' => false, 'error' => 'Tu cuenta está suspendida. Contacta con soporte para reactivarla.'];
        }

        // active / invited / expired / pending / inactive / vacío → permitir (pueden renovar).
        $this->login($matched);

        return ['ok' => true, 'user' => $matched];
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

    /**
     * Misma lógica que recuperación: email (case-insensitive) o username,
     * varias filas posibles → más reciente primero.
     *
     * @return list<MediaUser>
     */
    private function findLoginCandidates(string $login): array
    {
        $db = Database::getInstance();
        $loginLower = mb_strtolower($login);

        $rows = $db->fetchAll(
            'SELECT * FROM `media_users`
             WHERE `deleted_at` IS NULL
               AND (
                    LOWER(TRIM(`email`)) = ?
                 OR LOWER(TRIM(`username`)) = ?
               )
             ORDER BY `id` DESC
             LIMIT 20',
            [$loginLower, $loginLower]
        );

        return array_map(static fn (array $row): MediaUser => new MediaUser($row), $rows);
    }

    private function passwordMatches(MediaUser $user, string $password): bool
    {
        $hash = trim((string) ($user->password ?? ''));
        if ($hash === '' || $hash === '0') {
            return false;
        }

        try {
            return $this->passwords->verify($password, $hash);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<MediaUser> $candidates */
    private function applyDefaultPasswordToCandidates(array $candidates): void
    {
        $hash = $this->passwords->hash(PortalDefaultPasswordService::DEFAULT_PASSWORD);
        foreach ($candidates as $user) {
            $user->password = $hash;
            $user->save();
        }
    }
}
