<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\Media\MediaDiscoveryService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\ServerEndpoint;
use App\Services\LinkedLibraryService;
use App\Services\ServerConnectionDebugService;
use App\Services\ServerLoadService;
use App\Services\ServerSyncService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;
use Ramsey\Uuid\Uuid;

/**
 * Media server management controller.
 */
class ServerController extends Controller
{
    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
        private AuthService $auth = new AuthService(),
        private AuditService $audit = new AuditService(),
        private ServerSyncService $sync = new ServerSyncService(),
        private MediaDiscoveryService $discovery = new MediaDiscoveryService(),
        private ServerConnectionDebugService $connectionDebug = new ServerConnectionDebugService(),
        private ServerLoadService $load = new ServerLoadService(),
        private LinkedLibraryService $linkedLibraries = new LinkedLibraryService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        $placement = new \App\Services\ServerPlacementService();
        $placement->ensureQuotaColumn();
        $quotaById = [];
        foreach ($this->servers->allByTenant($tenantId) as $s) {
            $quotaById[(int) $s->id] = [
                'used' => $placement->countUsers((int) $s->id),
                'quota' => $placement->quotaOf($s->user_quota ?? 0),
            ];
        }

        return $this->view('servers.index', [
            'title' => 'Servidores',
            'servers' => $this->servers->allByTenant($tenantId),
            'load' => $this->load->getTenantLoad($tenantId),
            'linkedLibraries' => $this->linkedLibraries->getGroupedLibraries($tenantId),
            'quotaById' => $quotaById,
        ]);
    }

    public function loadApi(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        // Solo lectura y lento (consulta todos los servidores): soltar el lock
        // de sesión para no bloquear otras páginas del mismo navegador.
        \Core\Session::getInstance()->close();

        return $this->json([
            'load' => $this->load->getTenantLoad($tenantId),
            'updated_at' => date('c'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('servers.create', ['title' => 'Nuevo servidor']);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|max:255',
            'type' => 'required|in:plex,jellyfin',
            'url' => 'required|max:500',
            'port' => 'required|integer',
        ]);

        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $endpoint = ServerEndpoint::normalize(
            $data['url'],
            (int) $data['port'],
            (bool) $request->input('ssl')
        );

        $server = new Server([
            'tenant_id' => $tenantId,
            'uuid' => Uuid::uuid4()->toString(),
            'name' => $data['name'],
            'description' => $request->input('description'),
            'type' => $data['type'],
            'url' => $endpoint['url'],
            'port' => $endpoint['port'],
            'ssl' => $endpoint['ssl'] ? 1 : 0,
            'token' => $request->input('token'),
            'api_key' => $request->input('api_key'),
            'machine_id' => trim((string) $request->input('machine_id', '')) ?: null,
            'location' => $request->input('location'),
            'check_interval_minutes' => (int) ($request->input('check_interval') ?? 5),
            'user_quota' => max(0, min(100000, (int) $request->input('user_quota', 0))),
            'status' => 'offline',
        ]);

        $server->save();

        // Primer servidor de ese tipo → predeterminado automáticamente.
        if (!$this->servers->hasDefaultOfType($tenantId, (string) $data['type'])) {
            $this->servers->setDefault($tenantId, (int) $server->id, (string) $data['type']);
        }

        $this->audit->log('server.created', 'server', (int) $server->id, null, $server->toArray());

        $synced = $this->sync->sync($server);
        $stats = $this->sync->lastUserSyncStats();
        $msg = $synced
            ? sprintf('Servidor creado. %d usuarios importados, %d actualizados.', $stats['imported'], $stats['updated'])
            : 'Servidor creado pero OFFLINE: ' . ($server->last_error ?? 'no se pudo conectar. Usa URL pública (ej. tunel/mooo.com), no IP 192.168.x.');

        Session::getInstance()->flash('success', $msg);
        return $this->redirect('/servers/' . $server->uuid);
    }

    public function discoverPlex(Request $request): Response
    {
        $login = trim((string) $request->input('login', ''));
        $password = (string) $request->input('password', '');

        if ($login === '' || $password === '') {
            return $this->json(['error' => 'Usuario y contraseña Plex requeridos.'], 422);
        }

        try {
            $result = $this->discovery->discoverPlex($login, $password);
            return $this->json(['success' => true, ...$result]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    public function discoverJellyfin(Request $request): Response
    {
        $host = trim((string) $request->input('url', ''));
        $port = (int) $request->input('port', 8096);
        $ssl = (bool) $request->input('ssl');
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($host === '' || $username === '' || $password === '') {
            return $this->json(['error' => 'URL, usuario y contraseña Jellyfin requeridos.'], 422);
        }

        try {
            $result = $this->discovery->discoverJellyfin($host, $port, $ssl, $username, $password);
            return $this->json(['success' => true, ...$result]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->redirect('/servers');
        }

        $db = \Core\Database::getInstance();
        $dbStats = $db->fetchOne(
            'SELECT COUNT(*) AS users FROM media_users WHERE server_id = ? AND deleted_at IS NULL',
            [$server->id]
        );
        $dbLibraries = $db->fetchOne(
            'SELECT COUNT(*) AS libraries FROM libraries WHERE server_id = ?',
            [$server->id]
        );
        $libraries = $db->fetchAll(
            'SELECT id, external_id, name, type, path, item_count, is_enabled
             FROM libraries WHERE server_id = ? ORDER BY name ASC',
            [$server->id]
        );

        return $this->view('servers.show', [
            'title' => $server->name,
            'server' => $server,
            'panelUsers' => (int) ($dbStats['users'] ?? 0),
            'panelLibraries' => (int) ($dbLibraries['libraries'] ?? 0),
            'libraries' => $libraries ?: [],
            'debug' => $this->connectionDebug->loadDebug($server) ?? $this->sync->lastDebug(),
        ]);
    }

    public function edit(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->redirect('/servers');
        }

        return $this->view('servers.edit', [
            'title' => 'Editar: ' . $server->name,
            'server' => $server,
        ]);
    }

    public function update(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->redirect('/servers');
        }

        $data = $this->validate($request, [
            'name' => 'required|max:255',
            'type' => 'required|in:plex,jellyfin',
            'url' => 'required|max:500',
            'port' => 'required|integer',
        ]);

        $before = $server->toArray();
        $endpoint = ServerEndpoint::normalize(
            $data['url'],
            (int) $data['port'],
            (bool) $request->input('ssl')
        );

        $server->name = $data['name'];
        $server->description = $request->input('description');
        $server->type = $data['type'];
        $server->url = $endpoint['url'];
        $server->port = $endpoint['port'];
        $server->ssl = $endpoint['ssl'] ? 1 : 0;
        $server->location = $request->input('location');
        $server->check_interval_minutes = (int) ($request->input('check_interval') ?? 5);
        $quota = trim((string) $request->input('user_quota', ''));
        $server->user_quota = ($quota === '') ? 0 : max(0, min(100000, (int) $quota));

        $token = trim((string) $request->input('token', ''));
        if ($token !== '') {
            $server->token = $token;
        }

        $apiKey = trim((string) $request->input('api_key', ''));
        if ($apiKey !== '') {
            $server->api_key = $apiKey;
        }

        $machineId = trim((string) $request->input('machine_id', ''));
        if ($machineId !== '') {
            $server->machine_id = $machineId;
        }

        $server->save();
        $this->audit->log('server.updated', 'server', (int) $server->id, $before, $server->toArray());

        $msg = 'Servidor actualizado.';
        if ($request->input('sync_after')) {
            $synced = $this->sync->sync($server);
            $stats = $this->sync->lastUserSyncStats();
            $msg = $synced
                ? sprintf(
                    'Servidor actualizado y sincronizado (%d nuevos, %d actualizados, %d ausentes).',
                    (int) ($stats['imported'] ?? 0),
                    (int) ($stats['updated'] ?? 0),
                    (int) ($stats['missing'] ?? 0)
                )
                : 'Servidor actualizado pero OFFLINE: ' . ($server->last_error ?? 'no se pudo conectar.');
        }

        Session::getInstance()->flash('success', $msg);
        return $this->redirect('/servers/' . $server->uuid);
    }

    public function sync(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->json(['error' => 'Servidor no encontrado'], 404);
        }

        $success = $this->sync->sync($server);
        $stats = $this->sync->lastUserSyncStats();
        $this->audit->log('server.synced', 'server', (int) $server->id);

        $message = $success
            ? sprintf(
                'Forzar sync OK: %d nuevos, %d actualizados, %d ausentes del servidor, %d restaurados (%d en panel).',
                (int) ($stats['imported'] ?? 0),
                (int) ($stats['updated'] ?? 0),
                (int) ($stats['missing'] ?? 0),
                (int) ($stats['restored'] ?? 0),
                (int) ($stats['total'] ?? 0)
            )
            : 'Sync fallido: ' . ($server->last_error ?? 'no se pudo conectar al servidor.');

        if ($success && !empty($stats['warning'])) {
            $message .= ' ' . $stats['warning'];
        }

        return $this->json([
            'success' => $success,
            'status' => $server->status,
            'message' => $message,
            'users' => $stats,
            'last_error' => $server->last_error,
            'debug' => $this->sync->lastDebug(),
        ]);
    }

    public function debug(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->json(['error' => 'Servidor no encontrado'], 404);
        }

        $debug = $this->sync->runFullDiagnose($server);
        $synced = false;

        if (!empty($debug['connected'])) {
            $synced = $this->sync->syncConnectionOnly($server);
            if (!$synced) {
                $this->sync->touchOnline($server, 0);
            }
            $server = $this->servers->findByUuid($uuid) ?? $server;
        }

        return $this->json([
            'success' => !empty($debug['connected']),
            'synced' => $synced,
            'status' => $server->status,
            'active_sessions' => (int) $server->active_sessions,
            'message' => !empty($debug['connected'])
                ? ($synced
                    ? 'Conexión OK. Servidor marcado online.'
                    : 'Conexión detectada en debug; estado actualizado a online.')
                : 'Debug fallido: ' . ($debug['final_error'] ?? $server->last_error ?? 'sin conexión'),
            'last_error' => $server->last_error,
            'debug' => $debug,
        ]);
    }

    public function test(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->json(['error' => 'Servidor no encontrado'], 404);
        }

        $success = $this->sync->syncConnectionOnly($server);

        return $this->json([
            'connected' => $success,
            'status' => $server->status,
            'message' => $success
                ? sprintf('Conexión OK. %d streams activos.', (int) $server->active_sessions)
                : 'Conexión fallida: ' . ($server->last_error ?? 'no se pudo conectar.'),
            'last_error' => $server->last_error,
            'debug' => $this->sync->lastDebug(),
        ]);
    }

    /**
     * Trigger a media-server library scan for one section already synced to the panel.
     * Does not remove users or alter library shares — only asks Plex/Jellyfin to scan.
     */
    public function scanLibrary(Request $request, string $uuid, string $externalId): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->json(['success' => false, 'error' => 'Servidor no encontrado', 'message' => 'Servidor no encontrado.'], 404);
        }

        $externalId = trim(urldecode($externalId));
        if ($externalId === '') {
            return $this->json(['success' => false, 'message' => 'Biblioteca no válida.'], 422);
        }

        $db = \Core\Database::getInstance();
        $library = $db->fetchOne(
            'SELECT id, external_id, name FROM libraries WHERE server_id = ? AND external_id = ? LIMIT 1',
            [$server->id, $externalId]
        );
        if ($library === null) {
            return $this->json([
                'success' => false,
                'message' => 'Biblioteca no encontrada en el panel. Usa «Forzar sincronización» primero.',
            ], 404);
        }

        $platform = $server->type === 'jellyfin' ? 'Jellyfin' : 'Plex';

        try {
            $media = MediaServerFactory::make($server);
            $ok = $media->refreshLibrary($externalId);

            if ($ok) {
                try {
                    $this->audit->log('server.library_scanned', 'server', (int) $server->id, null, [
                        'external_id' => $externalId,
                        'library' => $library['name'] ?? null,
                    ]);
                } catch (\Throwable $auditError) {
                    \Core\Logger::warning('server.library_scanned audit failed', ['error' => $auditError->getMessage()]);
                }
            }

            return $this->json([
                'success' => $ok,
                'message' => $ok
                    ? sprintf('Escaneo iniciado en %s: %s', $platform, (string) ($library['name'] ?? $externalId))
                    : sprintf('No se pudo iniciar el escaneo en %s.', $platform),
            ], $ok ? 200 : 502);
        } catch (\Throwable $e) {
            \Core\Logger::error('server.scan_library failed', [
                'uuid' => $uuid,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
            return $this->json([
                'success' => false,
                'message' => 'Error al iniciar el escaneo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger a scan of all libraries on the Plex/Jellyfin server.
     */
    public function scanAllLibraries(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->json(['success' => false, 'error' => 'Servidor no encontrado', 'message' => 'Servidor no encontrado.'], 404);
        }

        $platform = $server->type === 'jellyfin' ? 'Jellyfin' : 'Plex';

        try {
            $media = MediaServerFactory::make($server);
            $result = $media->refreshAllLibraries();
            $ok = !empty($result['success']);

            if ($ok) {
                try {
                    $this->audit->log('server.libraries_scanned', 'server', (int) $server->id, null, [
                        'scanned' => $result['scanned'] ?? null,
                        'failed' => $result['failed'] ?? null,
                    ]);
                } catch (\Throwable $auditError) {
                    \Core\Logger::warning('server.libraries_scanned audit failed', ['error' => $auditError->getMessage()]);
                }
            }

            $message = $ok
                ? sprintf('Escaneo iniciado en %s (todas las bibliotecas).', $platform)
                : sprintf(
                    'No se pudo iniciar el escaneo en %s.%s',
                    $platform,
                    !empty($result['error']) ? ' ' . $result['error'] : ''
                );

            if ($ok && (int) ($result['failed'] ?? 0) > 0) {
                $message = sprintf(
                    'Escaneo iniciado en %s: %d biblioteca(s), %d con error.',
                    $platform,
                    (int) ($result['scanned'] ?? 0),
                    (int) ($result['failed'] ?? 0)
                );
            }

            return $this->json([
                'success' => $ok,
                'message' => $message,
                'scanned' => (int) ($result['scanned'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
            ], $ok ? 200 : 502);
        } catch (\Throwable $e) {
            \Core\Logger::error('server.scan_all_libraries failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
            return $this->json([
                'success' => false,
                'message' => 'Error al iniciar el escaneo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Escanea todas las categorías vinculadas (mismo nombre en ≥2 servidores).
     */
    public function scanLinkedLibraries(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        \Core\Session::getInstance()->close();

        $result = $this->linkedLibraries->scanGroup($tenantId, 'all');

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    /**
     * Escanea una categoría vinculada en todos los servidores donde exista.
     */
    public function scanLinkedLibraryGroup(Request $request, string $groupKey): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        \Core\Session::getInstance()->close();

        $result = $this->linkedLibraries->scanGroup($tenantId, $groupKey);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function syncAll(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $synced = $this->sync->syncAll($tenantId);
        $total = count($this->servers->allByTenant($tenantId));
        $stats = $this->sync->lastUserSyncStats();

        $msg = sprintf(
            'Forzar sincronización: %d/%d servidores online. Usuarios: %d nuevos, %d actualizados, %d ausentes, %d restaurados.',
            $synced,
            $total,
            (int) ($stats['imported'] ?? 0),
            (int) ($stats['updated'] ?? 0),
            (int) ($stats['missing'] ?? 0),
            (int) ($stats['restored'] ?? 0)
        );
        if (!empty($stats['warning'])) {
            $msg .= ' ' . $stats['warning'];
        }

        Session::getInstance()->flash('success', $msg);

        return $this->redirect('/servers');
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->redirect('/servers');
        }

        $this->audit->log('server.deleted', 'server', (int) $server->id, $server->toArray());
        $server->deleted_at = now()->format('Y-m-d H:i:s');
        $server->save();

        Session::getInstance()->flash('success', 'Servidor eliminado.');
        return $this->redirect('/servers');
    }

    public function setDefault(Request $request, string $uuid): Response
    {
        try {
            $server = $this->servers->findByUuid($uuid);
            if ($server === null) {
                return $this->json(['error' => 'Servidor no encontrado'], 404);
            }

            $this->servers->setDefault((int) $server->tenant_id, (int) $server->id, (string) $server->type);

            try {
                AuditService::log('server.set_default', 'server', (int) $server->id);
            } catch (\Throwable $auditError) {
                \Core\Logger::warning('server.set_default audit log failed', ['error' => $auditError->getMessage()]);
            }

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    '"%s" es ahora el servidor %s predeterminado. Puedes tener uno de Plex y uno de Jellyfin.',
                    $server->name,
                    strtoupper((string) $server->type)
                ),
                'type' => $server->type,
                'server_id' => (int) $server->id,
            ]);
        } catch (\Throwable $e) {
            \Core\Logger::error('server.set_default failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'No se pudo marcar como predeterminado: ' . $e->getMessage(),
            ], 500);
        }
    }
}
