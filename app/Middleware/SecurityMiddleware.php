<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\SecurityService;
use Core\Request;
use Core\Response;
use Core\Exceptions\HttpException;

/**
 * Global security: headers, IP blacklist, install lock.
 */
class SecurityMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if ($this->isInstallBlocked($request)) {
            throw new HttpException('Instalador deshabilitado.', 403);
        }

        $security = new SecurityService();
        if ($security->isBlocked($request->ip())) {
            throw new HttpException('Acceso bloqueado.', 403);
        }

        $response = $next($request);

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    private function isInstallBlocked(Request $request): bool
    {
        if (!str_starts_with($request->uri(), '/install')) {
            return false;
        }

        return env('APP_INSTALLED', false) || file_exists(storage_path('.installed'));
    }
}
