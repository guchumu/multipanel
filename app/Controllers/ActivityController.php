<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use App\Services\MediaUserEndpointService;
use App\Services\PlaybackStopMessageService;
use App\Services\ServerLoadService;
use App\Services\StreamingActivityService;
use Core\Cache;
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
        private PlaybackStopMessageService $stopMessages = new PlaybackStopMessageService(),
        private ServerLoadService $load = new ServerLoadService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $snapshot = $this->activity->getSnapshot($tenantId, $serverId);
        $streamSettings = new \App\Services\StreamLimitSettingsService();

        return $this->view('activity.index', [
            'title' => 'En directo',
            'servers' => $this->servers->allByTenant($tenantId),
            'sessions' => $snapshot['sessions'],
            'grouped' => $snapshot['grouped'],
            'serverStats' => $snapshot['server_stats'],
            'totalCount' => $snapshot['total_count'],
            'currentServerId' => $serverId,
            'stopMessages' => $this->stopMessages->listForTenant($tenantId),
            'autoKillVideoTranscodes' => $streamSettings->isAutoKillVideoTranscodesEnabled($tenantId),
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
        // Overview reutiliza el mismo snapshot cacheado (TTL 15s).
        $overview = $this->load->getActivityOverview($tenantId);
        $autoKill = (new \App\Services\StreamLimitSettingsService())->isAutoKillVideoTranscodesEnabled($tenantId);

        return $this->json([
            'sessions' => $snapshot['sessions'],
            'grouped' => $snapshot['grouped'],
            'server_stats' => $snapshot['server_stats'],
            'count' => $snapshot['filtered_count'],
            'total_count' => $snapshot['total_count'],
            'auto_kill_video_transcodes' => $autoKill,
            'summary' => [
                'total_streams' => $overview['total_streams'],
                'total_transcodes' => $overview['total_transcodes'],
                'total_direct_play' => $overview['total_direct_play'],
                'total_direct_stream' => $overview['total_direct_stream'],
                'by_server' => $overview['by_server'],
            ],
        ]);
    }

    public function thumb(Request $request, string $uuid): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $server = $this->servers->findByUuid($uuid);

        if ($server === null || (int) $server->tenant_id !== $tenantId) {
            return $this->thumbPlaceholder('Servidor no encontrado', 404);
        }

        // El navegador pide varias carátulas en paralelo; sin esto cada una
        // retendría el lock de sesión y se servirían en serie.
        \Core\Session::getInstance()->close();

        // Preferir ?p= (base64url). Mantener ?path= por compatibilidad.
        $artPath = StreamingActivityService::decodeThumbParam((string) $request->input('p', '')) ?? '';
        if ($artPath === '') {
            $artPath = (string) $request->input('path', '');
        }
        $itemId = (string) $request->input('item', '');
        $artwork = $this->activity->fetchArtwork($server, $artPath !== '' ? $artPath : null, $itemId !== '' ? $itemId : null);

        if ($artwork === null) {
            // Como SERVEROLD: SVG para que el <img> no quede roto en silencio.
            $hint = $artPath !== '' ? $artPath : ($itemId !== '' ? 'item:' . $itemId : 'sin ruta');
            return $this->thumbPlaceholder('Sin carátula', 200, $hint);
        }

        return new Response($artwork['body'], 200, [
            'Content-Type' => $artwork['content_type'],
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function thumbPlaceholder(string $label, int $status = 200, string $detail = ''): Response
    {
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $detail = htmlspecialchars(strlen($detail) > 80 ? substr($detail, 0, 77) . '...' : $detail, ENT_QUOTES, 'UTF-8');
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="300" viewBox="0 0 200 300">
  <rect width="200" height="300" fill="#2b2f36"/>
  <text x="100" y="145" fill="#9aa0a6" text-anchor="middle" font-family="Arial,sans-serif" font-size="14">{$label}</text>
  <text x="100" y="170" fill="#6c757d" text-anchor="middle" font-family="Arial,sans-serif" font-size="10">{$detail}</text>
</svg>
SVG;

        return new Response($svg, $status, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-store',
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
                            $item['proxy_url'] = '/activity/thumb/' . (string) $server->uuid
                                . '?p=' . StreamingActivityService::encodeThumbParam($artPath);
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

    /**
     * Activa/desactiva el auto-corte de transcodes de vídeo (cron streams).
     * Al activar, ejecuta un pase inmediato además del cron.
     */
    public function setAutoKillVideoTranscodes(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $raw = $request->input('enabled');
        if (is_bool($raw)) {
            $enabled = $raw;
        } else {
            $enabled = in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
        }

        (new \App\Services\StreamLimitSettingsService())->setAutoKillVideoTranscodesEnabled($tenantId, $enabled);

        $killed = 0;
        if ($enabled) {
            $result = $this->activity->autoKillVideoTranscodesIfEnabled($tenantId, null);
            $killed = (int) ($result['killed'] ?? 0);
        }

        $message = $enabled
            ? 'Auto-corte ACTIVADO. El cron streams cortará solo cuando Vídeo diga Transcode.'
            : 'Auto-corte desactivado.';
        if ($enabled && $killed > 0) {
            $message .= " Cortadas ahora: {$killed}.";
        }

        return $this->json([
            'success' => true,
            'enabled' => $enabled,
            'killed' => $killed,
            'message' => $message,
        ]);
    }

    public function kill(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = (int) $request->input('server_id');
        $sessionId = trim((string) $request->input('session_id', ''));
        $message = trim((string) ($request->input('message') ?? $request->input('reason') ?? ''));

        if ($serverId <= 0 || $sessionId === '') {
            return $this->json(['success' => false, 'message' => 'Datos de sesión incompletos.'], 422);
        }

        $server = Server::find($serverId);
        if ($server === null || (int) $server->tenant_id !== $tenantId) {
            return $this->json(['success' => false, 'message' => 'Servidor no encontrado.'], 404);
        }

        $reason = $message !== '' ? $message : $this->stopMessages->defaultBody($tenantId);
        $ok = $this->activity->terminateSession($server, $sessionId, $reason);

        return $this->json([
            'success' => $ok,
            'message' => $ok
                ? ($message !== '' ? 'Reproducción detenida y mensaje enviado.' : 'Reproducción detenida.')
                : 'No se pudo detener la reproducción.',
        ], $ok ? 200 : 500);
    }

    /**
     * Corta solo sesiones con transcode de vídeo (no audio-only).
     * Usa el mensaje al detener predeterminado.
     */
    public function killVideoTranscodes(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverFilter = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $message = trim((string) ($request->input('message') ?? ''));

        $result = $this->activity->killVideoTranscodes(
            $tenantId,
            $serverFilter,
            $message !== '' ? $message : null
        );
        $killed = (int) ($result['killed'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $matched = (int) ($result['matched'] ?? 0);

        if ($matched === 0) {
            return $this->json([
                'success' => true,
                'killed' => 0,
                'failed' => 0,
                'matched' => 0,
                'message' => 'No hay sesiones con Vídeo = Transcode para cortar.',
            ]);
        }

        return $this->json([
            'success' => $killed > 0 || $failed === 0,
            'killed' => $killed,
            'failed' => $failed,
            'matched' => $matched,
            'message' => $killed > 0
                ? sprintf(
                    'Cortados %d transcode(s) de vídeo. Mensaje predefinido enviado.',
                    $killed
                ) . ($failed > 0 ? " Fallidos: {$failed}." : '')
                : sprintf('No se pudo cortar ninguna sesión (%d fallo(s)).', $failed),
        ], ($killed > 0 || $failed === 0) ? 200 : 502);
    }

    public function sessionKind(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = (int) $request->input('server_id');
        $sessionId = trim((string) $request->input('session_id', ''));
        $kind = (string) $request->input('kind', '');

        if ($serverId <= 0 || $sessionId === '') {
            return $this->json(['success' => false, 'message' => 'Datos de sesión incompletos.'], 422);
        }

        $snapshot = $this->activity->getSnapshot($tenantId);
        $session = null;
        foreach ($snapshot['sessions'] as $row) {
            if ((int) ($row['server_id'] ?? 0) === $serverId
                && (string) ($row['session_id'] ?? '') === $sessionId) {
                $session = $row;
                break;
            }
        }

        if ($session === null) {
            return $this->json(['success' => false, 'message' => 'Sesión no encontrada (puede haber terminado).'], 404);
        }

        $result = (new MediaUserEndpointService())->setKindFromSession($tenantId, $session, $kind);
        if (!$result['success']) {
            return $this->json($result, 422);
        }

        Cache::forget('activity_snapshot_' . $tenantId);

        return $this->json([
            'success' => true,
            'kind' => $result['kind'] ?? $kind,
            'household_source' => 'manual',
            'endpoint_id' => $result['endpoint_id'] ?? null,
            'message' => $result['message'] ?? 'Guardado.',
        ]);
    }
}
