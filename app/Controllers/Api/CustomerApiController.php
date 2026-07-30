<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\CustomerService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * CRM customers REST API.
 */
class CustomerApiController extends Controller
{
    public function __construct(
        private CustomerService $customers = new CustomerService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($request->input('tenant_id', 1));
        $search = $request->input('q');

        return $this->json([
            'data' => $this->customers->list($tenantId, is_string($search) ? $search : null),
            'stats' => $this->customers->stats($tenantId),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $customer = $this->customers->findByUuid($uuid);
        if (!$customer) {
            return $this->json(['error' => 'Not found'], 404);
        }
        return $this->json(['data' => $customer]);
    }
}
