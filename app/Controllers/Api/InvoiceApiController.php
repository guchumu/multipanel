<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\InvoiceService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Invoices REST API.
 */
class InvoiceApiController extends Controller
{
    public function __construct(
        private InvoiceService $invoices = new InvoiceService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($request->input('tenant_id', 1));
        return $this->json(['data' => $this->invoices->list($tenantId)]);
    }

    public function show(Request $request, int $id): Response
    {
        $invoice = \Core\Database::getInstance()->fetchOne('SELECT * FROM invoices WHERE id = ?', [$id]);
        if (!$invoice) {
            return $this->json(['error' => 'Not found'], 404);
        }
        return $this->json(['data' => $invoice]);
    }
}
