<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Core\Exceptions\HttpException;

/**
 * CSRF token validation for state-changing requests.
 */
class CsrfMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->input('_token')
                ?? $request->header('X-CSRF-TOKEN')
                ?? $request->header('X-Csrf-Token')
                ?? $request->header('X-XSRF-TOKEN');

            if (!Session::getInstance()->validateCsrf(is_string($token) ? $token : null)) {
                throw new HttpException(
                    'Token CSRF inválido. Recarga la página (F5) e inténtalo de nuevo. Si sigue fallando, cierra sesión y vuelve a entrar.',
                    419
                );
            }
        }

        return $next($request);
    }
}
