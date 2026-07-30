<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\PortalAuthService;
use Core\Request;
use Core\Response;

/**
 * Require authenticated portal (client) user.
 */
class PortalAuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = new PortalAuthService();

        if (!$auth->check()) {
            return Response::redirect('/portal/login');
        }

        return $next($request);
    }
}
