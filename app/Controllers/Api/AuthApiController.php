<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\JwtService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Exceptions\HttpException;

/**
 * REST API authentication controller.
 */
class AuthApiController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private JwtService $jwt = new JwtService(),
    ) {
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = $this->auth->attempt($data['email'], $data['password']);

        if ($user === null) {
            throw new HttpException('Credenciales inválidas.', 401);
        }

        return $this->json([
            'access_token' => $this->auth->generateApiToken($user),
            'refresh_token' => $this->jwt->generateRefreshToken((int) $user->id),
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl', 3600),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
                'name' => $user->fullName(),
            ],
        ]);
    }

    public function refresh(Request $request): Response
    {
        $token = $request->bearerToken() ?? $request->input('refresh_token');
        if ($token === null) {
            throw new HttpException('Refresh token requerido.', 401);
        }

        $payload = $this->jwt->validate($token);
        if (($payload->type ?? '') !== 'refresh') {
            throw new HttpException('Token inválido.', 401);
        }

        $user = \App\Models\User::find((int) $payload->sub);
        if ($user === null) {
            throw new HttpException('Usuario no encontrado.', 404);
        }

        return $this->json([
            'access_token' => $this->auth->generateApiToken($user),
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl', 3600),
        ]);
    }

    public function me(Request $request): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new HttpException('No autenticado.', 401);
        }

        $userId = (new JwtService())->getUserId($token);
        $user = \App\Models\User::find($userId);

        if ($user === null) {
            throw new HttpException('Usuario no encontrado.', 404);
        }

        return $this->json([
            'id' => $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'name' => $user->fullName(),
            'status' => $user->status,
        ]);
    }
}
