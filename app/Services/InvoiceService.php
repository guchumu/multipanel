<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * HTML invoice generation and storage.
 */
final class InvoiceService
{
    public function generateForSubscription(int $subscriptionId): ?string
    {
        $db = Database::getInstance();
        $sub = $db->fetchOne(
            'SELECT s.*, c.email, c.first_name, c.last_name, c.company, c.tax_id, p.name as plan_name
             FROM subscriptions s
             JOIN customers c ON c.id = s.customer_id
             JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.id = ?',
            [$subscriptionId]
        );

        if (!$sub) {
            return null;
        }

        $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad((string) $subscriptionId, 6, '0', STR_PAD_LEFT);
        $existing = $db->fetchOne('SELECT id FROM invoices WHERE number = ? AND tenant_id = ?', [$invoiceNumber, $sub['tenant_id']]);

        if ($existing) {
            $invoice = $db->fetchOne('SELECT * FROM invoices WHERE id = ?', [$existing['id']]);
        } else {
            $invoiceId = $db->insert('invoices', [
                'tenant_id' => $sub['tenant_id'],
                'customer_id' => $sub['customer_id'],
                'subscription_id' => $subscriptionId,
                'number' => $invoiceNumber,
                'status' => 'paid',
                'subtotal' => $sub['amount'],
                'tax' => round((float) $sub['amount'] * 0.21, 2),
                'total' => round((float) $sub['amount'] * 1.21, 2),
                'currency' => $sub['currency'],
                'paid_at' => date('Y-m-d H:i:s'),
                'gateway' => $sub['gateway'],
            ]);
            $invoice = $db->fetchOne('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        }

        $html = $this->renderHtml($invoice, $sub);
        $path = storage_path('invoices');
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filename = $invoice['number'] . '.html';
        $fullPath = $path . '/' . $filename;
        file_put_contents($fullPath, $html);

        $pdfPath = $path . '/' . $invoice['number'] . '.pdf';
        $this->generatePdf($invoice, $sub, $pdfPath);

        $db->update('invoices', ['pdf_path' => $pdfPath], 'id = ?', [$invoice['id']]);

        return $pdfPath;
    }

    /** @param array<string, mixed> $invoice */
    /** @param array<string, mixed> $sub */
    private function generatePdf(array $invoice, array $sub, string $path): void
    {
        $pdf = new \Core\SimplePdf();
        $pdf->addPage();
        $company = config('app.name', 'MultiPanel');
        $customer = trim(($sub['first_name'] ?? '') . ' ' . ($sub['last_name'] ?? '')) ?: $sub['email'];

        $pdf->addTextLine($company, 50, 18, 24);
        $pdf->addTextLine('Factura: ' . $invoice['number'], 50, 14);
        $pdf->addTextLine('Fecha: ' . date('d/m/Y', strtotime($invoice['paid_at'] ?? $invoice['created_at'])), 50, 12);
        $pdf->addTextLine('Cliente: ' . $customer, 50, 12);
        $pdf->addTextLine('Email: ' . $sub['email'], 50, 12);
        $pdf->addTextLine($sub['plan_name'] . ' — ' . number_format((float) $invoice['subtotal'], 2) . ' ' . $invoice['currency'], 50, 12);
        $pdf->addTextLine('IVA: ' . number_format((float) $invoice['tax'], 2) . ' ' . $invoice['currency'], 50, 12);
        $pdf->addTextLine('TOTAL: ' . number_format((float) $invoice['total'], 2) . ' ' . $invoice['currency'], 50, 14, 22);

        $pdf->save($path);
    }

    /** @param array<string, mixed> $invoice */
    /** @param array<string, mixed> $sub */
    private function renderHtml(array $invoice, array $sub): string
    {
        $company = config('app.name', 'MultiPanel');
        $customerName = trim(($sub['first_name'] ?? '') . ' ' . ($sub['last_name'] ?? '')) ?: $sub['email'];
        $subtotal = number_format((float) $invoice['subtotal'], 2);
        $tax = number_format((float) $invoice['tax'], 2);
        $total = number_format((float) $invoice['total'], 2);
        $date = date('d/m/Y', strtotime($invoice['paid_at'] ?? $invoice['created_at']));

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Factura {$invoice['number']}</title>
<style>
body{font-family:Arial,sans-serif;max-width:800px;margin:40px auto;color:#333}
.header{display:flex;justify-content:space-between;border-bottom:2px solid #0d6efd;padding-bottom:20px;margin-bottom:30px}
table{width:100%;border-collapse:collapse;margin:20px 0}
th,td{padding:10px;text-align:left;border-bottom:1px solid #eee}
.total{text-align:right;font-size:1.2em;font-weight:bold}
@media print{body{margin:0}}
</style>
</head>
<body>
<div class="header">
    <div><h2>{$company}</h2><p>Factura</p></div>
    <div style="text-align:right"><strong>{$invoice['number']}</strong><br>Fecha: {$date}</div>
</div>
<p><strong>Cliente:</strong> {$customerName}<br>
<strong>Email:</strong> {$sub['email']}<br>
<strong>NIF/CIF:</strong> {$sub['tax_id']}</p>
<table>
<thead><tr><th>Concepto</th><th>Importe</th></tr></thead>
<tbody>
<tr><td>{$sub['plan_name']} — Suscripción</td><td>{$subtotal} {$invoice['currency']}</td></tr>
<tr><td>IVA (21%)</td><td>{$tax} {$invoice['currency']}</td></tr>
</tbody>
</table>
<p class="total">Total: {$total} {$invoice['currency']}</p>
<p style="color:#666;font-size:0.9em">Pagado vía {$invoice['gateway']}. Gracias por confiar en {$company}.</p>
<script>window.onload=()=>{if(location.search.includes('print'))window.print()}</script>
</body>
</html>
HTML;
    }

    /** @return list<array<string, mixed>> */
    public function list(int $tenantId, int $limit = 50): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT i.*, c.email as customer_email, c.first_name, c.last_name
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE i.tenant_id = ? ORDER BY i.created_at DESC LIMIT ?',
            [$tenantId, $limit]
        );
    }
}
