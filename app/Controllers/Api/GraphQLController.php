<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\GraphQL\Schema;
use App\Services\JwtService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Exceptions\HttpException;

/**
 * GraphQL API endpoint.
 */
class GraphQLController extends Controller
{
    public function handle(Request $request): Response
    {
        $body = $request->json();
        if (!$body || !isset($body['query'])) {
            throw new HttpException('GraphQL query required.', 400);
        }

        $tenantId = 1;
        $token = $request->bearerToken();

        if (!$token && !preg_match('/\bhealth\b/', $body['query'] ?? '')) {
            throw new HttpException('Authentication required. Use Bearer token.', 401);
        }

        if ($token) {
            try {
                $payload = (new JwtService())->validate($token);
                $tenantId = (int) ($payload->tenant_id ?? 1);
            } catch (\Throwable) {
                throw new HttpException('Invalid token.', 401);
            }
        }

        $gql = Schema::build();
        $result = $gql->execute($body, $tenantId);

        return $this->json($result);
    }

    public function schema(Request $request): Response
    {
        $gql = Schema::build();
        return Response::html('<pre>' . htmlspecialchars($gql->getSchema()) . '</pre>');
    }
}
