<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use Core\Request;
use Core\Response;
use Core\View;
use Core\Exceptions\HttpException;

/**
 * Require authenticated panel user.
 */
class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = new AuthService();

        if (!$auth->check()) {
            if ($request->isApi()) {
                throw new HttpException('No autenticado.', 401);
            }
            return Response::redirect('/login');
        }

        View::share('user', $auth->user());

        return $next($request);
    }
}
