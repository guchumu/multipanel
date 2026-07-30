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
            $token = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');
            if (!Session::getInstance()->validateCsrf($token)) {
                throw new HttpException('Token CSRF inválido.', 419);
            }
        }

        return $next($request);
    }
}
