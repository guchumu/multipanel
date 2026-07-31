<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LegacyRegistrationService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Legacy GET webhook for payment registration (guarda-registro.php compatible).
 */
class RegistroController extends Controller
{
    public function __construct(
        private LegacyRegistrationService $registration = new LegacyRegistrationService(),
    ) {
    }

    public function store(Request $request): Response
    {
        $secret = env('REGISTRO_SECRET', '');
        if ($secret !== '' && $request->input('key') !== $secret) {
            return $this->json(['status' => 'error', 'message' => 'No autorizado'], 401);
        }

        $clientId = $request->input('idcliente');
        $paid = $request->input('pagado');
        $paymentType = $request->input('tipopago');

        if (!$clientId || $paid === null || $paid === '' || !$paymentType) {
            return $this->json(['status' => 'error', 'message' => 'Faltan parámetros obligatorios'], 400);
        }

        $emails = [];
        for ($i = 1; $i <= 4; $i++) {
            $email = trim((string) $request->input("emailplex{$i}", ''));
            if ($email === '') {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json([
                    'status' => 'error',
                    'message' => "Email inválido: {$email} (emailplex{$i})",
                ], 400);
            }
            $emails[] = $email;
        }

        if ($emails === []) {
            return $this->json(['status' => 'error', 'message' => 'Debe proporcionar al menos un email'], 400);
        }

        $result = $this->registration->process(1, [
            'idcliente' => (string) $clientId,
            'pagado' => $paid,
            'tipopago' => (string) $paymentType,
            'tiempomes' => $request->input('tiempomes', 1),
            'servicio' => (string) ($request->input('servicio') ?? 'plex'),
            'emails' => $emails,
        ]);

        $statusCode = in_array($result['status'], ['success', 'ok'], true) ? 200 : (
            str_contains((string) ($result['message'] ?? ''), 'Límite') ? 429 : 500
        );

        return $this->json($result, $statusCode);
    }
}
