<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Payments\PaymentLinkService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Public short payment link redirect: GET /p/{code} → Stripe checkout.
 */
class PaymentLinkController extends Controller
{
    public function __construct(
        private PaymentLinkService $paymentLinks = new PaymentLinkService(),
    ) {
    }

    public function show(Request $request, string $code): Response
    {
        $url = $this->paymentLinks->resolve($code);
        if ($url === null) {
            return Response::html(
                '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Enlace no disponible</title></head><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;">'
                . '<h1 style="font-size:1.25rem;">Enlace de pago no disponible</h1>'
                . '<p>Este enlace no existe, ya se usó o ha caducado. Pide uno nuevo al administrador.</p>'
                . '</body></html>',
                404
            );
        }

        return $this->redirect($url);
    }
}
