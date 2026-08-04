<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
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

    /**
     * Diagnóstico de carátulas: para cada servidor con sesiones activas,
     * intenta descargar la carátula de cada sesión desde el propio panel y
     * reporta en qué paso falla (resolución, ruta, HTTP...). Pensado para
     * depurar en producción sin acceso a logs.
     */
    public function thumbsDebug(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        \Core\Session::getInstance()->close();

        $report = [];

        foreach ($this->servers->allByTenant($tenantId) as $server) {
            $entry = [
                'server' => (string) $server->name,
                'type' => (string) $server->type,
                'configured_url' => ((bool) $server->ssl ? 'https' : 'http') . '://' . $server->url . ':' . (int) $server->port,
            ];

            try {
                $media = MediaServerFactory::make($server);

                if ($media instanceof PlexService) {
                    $entry['connection_error'] = $media->getLastError();
                    $sessions = $media->getActiveSessions();
                    $entry['active_sessions'] = count($sessions);
                    $entry['thumbs'] = [];

                    foreach ($sessions as $session) {
                        $artPath = (string) ($session['art_path'] ?? '');
                        $item = [
                            'title' => (string) ($session['title'] ?? ''),
                            'art_path' => $artPath,
                        ];

                        if ($artPath === '') {
                            $item['result'] = 'La sesión no trae ruta de carátula (art_path vacío).';
                        } else {
                            $start = microtime(true);
                            $artwork = $media->fetchArtwork($artPath);
                            $item['ms'] = (int) round((microtime(true) - $start) * 1000);
                            $item['result'] = $artwork !== null
                                ? 'OK — ' . strlen($artwork['body']) . ' bytes (' . $artwork['content_type'] . ')'
                                : 'FALLO — ' . ($media->getLastArtworkError() ?? 'motivo desconocido');
                        }

                        $entry['thumbs'][] = $item;
                    }
                } elseif ($media instanceof JellyfinService) {
                    $sessions = $media->getActiveSessions();
                    $entry['active_sessions'] = count($sessions);
                    $entry['thumbs'] = [];

                    foreach ($sessions as $session) {
                        $itemId = (string) ($session['item_id'] ?? '');
                        $item = [
                            'title' => (string) ($session['title'] ?? ''),
                            'item_id' => $itemId,
                        ];

                        if ($itemId === '') {
                            $item['result'] = 'La sesión no trae item_id.';
                        } else {
                            $start = microtime(true);
                            $artwork = $media->fetchItemImage($itemId);
                            $item['ms'] = (int) round((microtime(true) - $start) * 1000);
                            $item['result'] = $artwork !== null
                                ? 'OK — ' . strlen($artwork['body']) . ' bytes (' . $artwork['content_type'] . ')'
                                : 'FALLO — no se pudo descargar la imagen del ítem.';
                        }

                        $entry['thumbs'][] = $item;
                    }
                } else {
                    $entry['result'] = 'Tipo de servidor sin soporte de carátulas.';
                }
            } catch (\Throwable $e) {
                $entry['exception'] = $e->getMessage();
            }

            $report[] = $entry;
        }

        return $this->json(['report' => $report], 200);
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
