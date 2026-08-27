<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SessionKillLinkService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Enlace público: GET /k/{code} corta una reproducción (WhatsApp admin).
 */
final class SessionKillLinkController extends Controller
{
    public function __construct(
        private SessionKillLinkService $links = new SessionKillLinkService(),
    ) {
    }

    public function kill(Request $request, string $code): Response
    {
        $result = $this->links->consume($code);
        $error = trim((string) ($result['error'] ?? ''));
        $killed = !empty($result['killed']);

        if (!$result['ok']) {
            return Response::html($this->page(
                'Enlace no disponible',
                $error !== '' ? $error : 'Este enlace no existe, ya se usó o ha caducado.',
                false
            ), 404);
        }

        if ($killed) {
            return Response::html($this->page(
                'Reproducción cortada',
                'Se ha detenido la emisión con el mensaje configurado para el límite de reproducciones.',
                true
            ), 200);
        }

        return Response::html($this->page(
            'No se pudo cortar',
            $error !== '' ? $error : 'La reproducción ya no estaba activa.',
            false
        ), 200);
    }

    private function page(string $title, string $body, bool $success): string
    {
        $color = $success ? '#0d6efd' : '#6c757d';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#16324f;">'
            . '<h1 style="font-size:1.35rem;color:' . $color . ';">'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<p>' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '</body></html>';
    }
}
