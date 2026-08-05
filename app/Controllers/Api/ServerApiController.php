<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\ServerSyncService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Exceptions\NotFoundException;
use Ramsey\Uuid\Uuid;

/**
 * REST API server endpoints.
 */
class ServerApiController extends Controller
{
    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
        private ServerSyncService $sync = new ServerSyncService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = 1;
        $list = array_map(fn ($s) => $this->formatServer($s), $this->servers->allByTenant($tenantId));
        return $this->json(['data' => $list]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            throw new NotFoundException('Servidor no encontrado.');
        }

        return $this->json(['data' => $this->formatServer($server)]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|max:255',
            'type' => 'required|in:plex,jellyfin',
            'url' => 'required',
            'port' => 'required|integer',
        ]);

        $server = new Server([
            'tenant_id' => 1,
            'uuid' => Uuid::uuid4()->toString(),
            'name' => $data['name'],
            'type' => $data['type'],
            'url' => $data['url'],
            'port' => (int) $data['port'],
            'ssl' => $request->input('ssl') ? 1 : 0,
            'token' => $request->input('token'),
            'api_key' => $request->input('api_key'),
            'status' => 'offline',
        ]);

        $server->save();

        return $this->json(['data' => $this->formatServer($server)], 201);
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            throw new NotFoundException('Servidor no encontrado.');
        }

        $server->deleted_at = now()->format('Y-m-d H:i:s');
        $server->save();

        return $this->json(['message' => 'Servidor eliminado.']);
    }

    public function sync(Request $request, string $uuid): Response
    {
        $server = $this->servers->findByUuid($uuid);
        if ($server === null) {
            throw new NotFoundException('Servidor no encontrado.');
        }

        $success = $this->sync->sync($server);
        $stats = $this->sync->lastUserSyncStats();

        return $this->json([
            'success' => $success,
            'data' => $this->formatServer($server),
            'users' => $stats,
            'message' => $success
                ? sprintf(
                    'Forzar sync OK: %d nuevos, %d actualizados, %d ausentes, %d restaurados.',
                    (int) ($stats['imported'] ?? 0),
                    (int) ($stats['updated'] ?? 0),
                    (int) ($stats['missing'] ?? 0),
                    (int) ($stats['restored'] ?? 0)
                )
                : 'Sync fallido',
        ]);
    }

    /** @return array<string, mixed> */
    private function formatServer(Server $server): array
    {
        return [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'type' => $server->type,
            'url' => $server->url,
            'port' => $server->port,
            'ssl' => (bool) $server->ssl,
            'status' => $server->status,
            'version' => $server->version,
            'active_sessions' => $server->active_sessions,
            'total_libraries' => $server->total_libraries,
            'last_sync_at' => $server->last_sync_at,
        ];
    }
}
