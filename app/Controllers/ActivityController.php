<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\StreamingActivityService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Live streaming activity (now playing).
 */
class ActivityController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private StreamingActivityService $activity = new StreamingActivityService(),
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $snapshot = $this->activity->getSnapshot($tenantId, $serverId);

        return $this->view('activity.index', [
            'title' => 'En directo',
            'servers' => $this->servers->allByTenant($tenantId),
            'sessions' => $snapshot['sessions'],
            'grouped' => $snapshot['grouped'],
            'serverStats' => $snapshot['server_stats'],
            'totalCount' => $snapshot['total_count'],
            'currentServerId' => $serverId,
        ]);
    }

    public function api(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;

        // Solo lectura y potencialmente lento (consulta servidores de medios):
        // soltamos el lock de sesión para no bloquear otras páginas del navegador.
        \Core\Session::getInstance()->close();

        $snapshot = $this->activity->getSnapshot($tenantId, $serverId);

        return $this->json([
            'sessions' => $snapshot['sessions'],
            'grouped' => $snapshot['grouped'],
            'server_stats' => $snapshot['server_stats'],
            'count' => $snapshot['filtered_count'],
            'total_count' => $snapshot['total_count'],
        ]);
    }

    public function thumb(Request $request, string $uuid): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $server = $this->servers->findByUuid($uuid);

        if ($server === null || (int) $server->tenant_id !== $tenantId) {
            return new Response('', 404);
        }

        // El navegador pide varias carátulas en paralelo; sin esto cada una
        // retendría el lock de sesión y se servirían en serie.
        \Core\Session::getInstance()->close();

        $artPath = (string) $request->input('path', '');
        $itemId = (string) $request->input('item', '');
        $artwork = $this->activity->fetchArtwork($server, $artPath !== '' ? $artPath : null, $itemId !== '' ? $itemId : null);

        if ($artwork === null) {
            return new Response('', 404);
        }

        return new Response($artwork['body'], 200, [
            'Content-Type' => $artwork['content_type'],
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function kill(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = (int) $request->input('server_id');
        $sessionId = trim((string) $request->input('session_id', ''));

        if ($serverId <= 0 || $sessionId === '') {
            return $this->json(['success' => false, 'message' => 'Datos de sesión incompletos.'], 422);
        }

        $server = Server::find($serverId);
        if ($server === null || (int) $server->tenant_id !== $tenantId) {
            return $this->json(['success' => false, 'message' => 'Servidor no encontrado.'], 404);
        }

        $ok = $this->activity->terminateSession($server, $sessionId);

        return $this->json([
            'success' => $ok,
            'message' => $ok ? 'Reproducción detenida.' : 'No se pudo detener la reproducción.',
        ], $ok ? 200 : 500);
    }
}
