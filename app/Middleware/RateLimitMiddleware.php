<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Cache;
use Core\Request;
use Core\Response;
use Core\Exceptions\HttpException;

/**
 * Rate limiting middleware.
 */
class RateLimitMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $max = (int) config('rate_limit.max', 60);
        $window = (int) config('rate_limit.window', 60);
        $key = 'rate:' . $request->ip() . ':' . $request->uri();

        $attempts = (int) Cache::get($key, 0);

        if ($attempts >= $max) {
            throw new HttpException('Demasiadas solicitudes. Inténtelo más tarde.', 429);
        }

        Cache::set($key, $attempts + 1, $window);

        return $next($request);
    }
}
