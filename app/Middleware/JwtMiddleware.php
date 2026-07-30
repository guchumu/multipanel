<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\JwtService;
use Core\Request;
use Core\Response;
use Core\Exceptions\HttpException;

/**
 * JWT bearer token authentication for API routes.
 */
class JwtMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new HttpException('Token de acceso requerido.', 401);
        }

        $jwt = new JwtService();
        $payload = $jwt->validate($token);

        if (($payload->type ?? '') === 'refresh') {
            throw new HttpException('Token de refresh no válido para esta operación.', 401);
        }

        $request->input('_jwt_user_id'); // placeholder for future request attribute bag

        return $next($request);
    }
}
