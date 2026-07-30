<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\ApiKeyService;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Exceptions\HttpException;

/**
 * Authenticate API requests via X-API-Key header.
 */
class ApiKeyMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $key = $request->header('X-API-Key') ?? $request->input('api_key');

        if (!$key) {
            throw new HttpException('API key requerida.', 401);
        }

        $record = (new ApiKeyService())->validate((string) $key);
        if (!$record) {
            throw new HttpException('API key inválida o expirada.', 401);
        }

        Session::getInstance()->set('api_key_tenant_id', (int) $record['tenant_id']);
        Session::getInstance()->set('api_key_permissions', json_decode($record['permissions'] ?? '[]', true));

        return $next($request);
    }
}
