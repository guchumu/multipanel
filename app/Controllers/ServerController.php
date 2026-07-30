<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\Media\MediaServerFactory;
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

        $server = new Server([
            'tenant_id' => $tenantId,
            'uuid' => Uuid::uuid4()->toString(),
            'name' => $data['name'],
            'description' => $request->input('description'),
            'type' => $data['type'],
            'url' => $data['url'],
            'port' => (int) $data['port'],
            'ssl' => $request->input('ssl') ? 1 : 0,
            'token' => $request->input('token'),
            'api_key' => $request->input('api_key'),
            'location' => $request->input('location'),
            'check_interval_minutes' => (int) ($request->input('check_interval') ?? 5),
            'status' => 'offline',
        ]);

        $server->save();
        $this->audit->log('server.created', 'server', (int) $server->id, null, $server->toArray());

        Session::getInstance()->flash('success', 'Servidor creado correctamente.');
        return $this->redirect('/servers');
    }

    public function show(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->redirect('/servers');
        }

        return $this->view('servers.show', [
            'title' => $server->name,
            'server' => $server,
        ]);
    }

    public function sync(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->json(['error' => 'Servidor no encontrado'], 404);
        }

        $success = $this->sync->sync($server);
        $this->audit->log('server.synced', 'server', (int) $server->id);

        return $this->json([
            'success' => $success,
            'status' => $server->status,
            'message' => $success ? 'Sincronización completada.' : 'Error en sincronización.',
        ]);
    }

    public function test(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            return $this->json(['error' => 'Servidor no encontrado'], 404);
        }

        $media = MediaServerFactory::make($server);
        $connected = $media->testConnection();

        return $this->json([
            'connected' => $connected,
            'message' => $connected ? 'Conexión exitosa.' : 'No se pudo conectar.',
        ]);
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
