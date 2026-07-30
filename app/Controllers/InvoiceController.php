<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\InvoiceService;
use App\Services\PermissionService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Invoice management controller.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private InvoiceService $invoices = new InvoiceService(),
        private PermissionService $permissions = new PermissionService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'billing.manage');

        $tenantId = (int) ($user->tenant_id ?? 1);

        return $this->view('invoices.index', [
            'title' => 'Facturas',
            'invoices' => $this->invoices->list($tenantId),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'billing.manage');

        $invoice = \Core\Database::getInstance()->fetchOne('SELECT * FROM invoices WHERE id = ?', [$id]);
        if (!$invoice || !file_exists($invoice['pdf_path'] ?? '')) {
            Session::getInstance()->flash('error', 'Factura no encontrada.');
            return $this->redirect('/invoices');
        }

        $isPdf = str_ends_with($invoice['pdf_path'], '.pdf');
        return new Response(
            (string) file_get_contents($invoice['pdf_path']),
            200,
            ['Content-Type' => $isPdf ? 'application/pdf' : 'text/html; charset=utf-8']
        );
    }

    public function download(Request $request, int $id): Response
    {
        $user = $this->auth->user();
        $this->permissions->authorize($user, 'billing.manage');

        $invoice = \Core\Database::getInstance()->fetchOne('SELECT * FROM invoices WHERE id = ?', [$id]);
        if (!$invoice || !file_exists($invoice['pdf_path'] ?? '')) {
            Session::getInstance()->flash('error', 'Factura no encontrada.');
            return $this->redirect('/invoices');
        }

        $filename = ($invoice['number'] ?? 'invoice') . (str_ends_with($invoice['pdf_path'], '.pdf') ? '.pdf' : '.html');
        return \Core\Response::download($invoice['pdf_path'], $filename);
    }
}
