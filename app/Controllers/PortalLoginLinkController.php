<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PortalAuthService;
use App\Services\PortalLoginLinkService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Enlace mágico público: GET /u/{code} → sesión de portal.
 */
final class PortalLoginLinkController extends Controller
{
    public function __construct(
        private PortalLoginLinkService $links = new PortalLoginLinkService(),
        private PortalAuthService $auth = new PortalAuthService(),
    ) {
    }

    public function enter(Request $request, string $code): Response
    {
        $result = $this->links->consume($code, $request->ip());
        if (empty($result['ok']) || !isset($result['user'])) {
            $msg = (string) ($result['error'] ?? 'Este enlace no está disponible.');

            return Response::html(
                '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Enlace no disponible</title></head>'
                . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#16324f;">'
                . '<h1 style="font-size:1.35rem;">Este enlace ya no vale</h1>'
                . '<p>' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<p><a href="/portal/login">Entrar con email y contraseña</a></p>'
                . '</body></html>',
                404
            );
        }

        $this->auth->login($result['user']);

        return $this->redirect((string) ($result['redirect'] ?? '/portal'));
    }
}
