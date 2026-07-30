<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Core\Exceptions\HttpException;
use Core\Logger;
use Core\Session;
use Ramsey\Uuid\Uuid;

/**
 * Authentication service for panel users.
 */
final class AuthService
{
    public function __construct(
        private UserRepository $users = new UserRepository(),
        private PasswordService $passwords = new PasswordService(),
        private JwtService $jwt = new JwtService(),
    ) {
    }

    public function attempt(string $email, string $password, bool $remember = false): ?User
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !$this->passwords->verify($password, $user->password)) {
            Logger::warning('Failed login attempt', ['email' => $email]);
            return null;
        }

        if ($user->status !== 'active') {
            throw new HttpException('Cuenta inactiva o suspendida.', 403);
        }

        if ($this->passwords->needsRehash($user->password)) {
            $user->password = $this->passwords->hash($password);
            $user->save();
        }

        $this->login($user, $remember);
        \Core\EventDispatcher::dispatch('user.login', $user);
        return $user;
    }

    public function login(User $user, bool $remember = false): void
    {
        $session = Session::getInstance();
        $session->regenerate();
        $session->set('user_id', $user->id);
        $session->set('tenant_id', $user->tenant_id);

        $user->last_login_at = now()->format('Y-m-d H:i:s');
        $user->last_login_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user->save();

        Logger::info('User logged in', ['user_id' => $user->id]);
    }

    public function logout(): void
    {
        Session::getInstance()->destroy();
    }

    public function user(): ?User
    {
        $userId = Session::getInstance()->get('user_id');
        if ($userId === null) {
            return null;
        }

        return User::find((int) $userId);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function generateApiToken(User $user): string
    {
        return $this->jwt->generate([
            'sub' => $user->id,
            'tenant_id' => $user->tenant_id,
            'role_id' => $user->role_id,
            'type' => 'access',
        ]);
    }

    public function register(array $data): User
    {
        $user = new User([
            'uuid' => Uuid::uuid4()->toString(),
            'tenant_id' => $data['tenant_id'] ?? 1,
            'role_id' => $data['role_id'] ?? 2,
            'email' => $data['email'],
            'username' => $data['username'],
            'password' => $this->passwords->hash($data['password']),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'status' => $data['status'] ?? 'pending',
        ]);

        $user->save();
        return $user;
    }
}
