<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\Media\MediaDiscoveryService;
use App\Services\ServerConnectionDebugService;
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
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('servers.index', [
            'title' => 'Servidores',
            'servers' => $this->servers->allByTenant($tenantId),
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
            'status' => 'offline',
        ]);

        $server->save();
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

        return $this->view('servers.show', [
            'title' => $server->name,
            'server' => $server,
            'panelUsers' => (int) ($dbStats['users'] ?? 0),
            'panelLibraries' => (int) ($dbLibraries['libraries'] ?? 0),
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
                ? sprintf('Servidor actualizado y sincronizado (%d usuarios nuevos, %d actualizados).', $stats['imported'], $stats['updated'])
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

        return $this->json([
            'success' => $success,
            'status' => $server->status,
            'message' => $success
                ? sprintf('Sync OK: %d usuarios nuevos, %d actualizados (%d total).', $stats['imported'], $stats['updated'], $stats['total'])
                : 'Sync fallido: ' . ($server->last_error ?? 'no se pudo conectar al servidor.'),
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

        return $this->json([
            'success' => !empty($debug['connected']),
            'status' => $server->status,
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

        $success = $this->sync->sync($server);
        $stats = $this->sync->lastUserSyncStats();

        return $this->json([
            'connected' => $success,
            'status' => $server->status,
            'message' => $success
                ? sprintf('Conexión OK. %d streams activos.', (int) $server->active_sessions)
                : 'Conexión fallida: ' . ($server->last_error ?? 'no se pudo conectar.'),
            'users' => $stats,
            'last_error' => $server->last_error,
            'debug' => $this->sync->lastDebug(),
        ]);
    }

    public function syncAll(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $synced = $this->sync->syncAll($tenantId);
        $total = count($this->servers->allByTenant($tenantId));

        Session::getInstance()->flash(
            'success',
            sprintf('Sincronización completada: %d de %d servidores online.', $synced, $total)
        );

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
}
