<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PeticionesRepository;
use App\Services\AuthService;
use App\Services\Peticiones\PeticionesConfig;
use App\Services\Peticiones\PeticionesDatabase;
use App\Services\Peticiones\PeticionesService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Admin de peticiones de contenido (BD remota legacy en paralelo).
 */
class PeticionesController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private PeticionesService $service = new PeticionesService(),
        private PeticionesRepository $repo = new PeticionesRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $cfg = PeticionesConfig::forTenant($tenantId);

        $filter = (string) ($request->input('filtro') ?? PeticionesRepository::FILTER_PENDIENTES);
        $allowed = [
            PeticionesRepository::FILTER_PENDIENTES,
            PeticionesRepository::FILTER_PROCESO,
            PeticionesRepository::FILTER_DENEGADAS,
            PeticionesRepository::FILTER_TODAS,
        ];
        if (!in_array($filter, $allowed, true)) {
            $filter = PeticionesRepository::FILTER_PENDIENTES;
        }

        $page = max(1, (int) ($request->input('page') ?? 1));
        $perPage = 48;
        $offset = ($page - 1) * $perPage;

        $error = null;
        $items = [];
        $counts = ['pendientes' => 0, 'proceso' => 0, 'denegadas' => 0, 'todas' => 0];
        $motivos = [];
        $platformsById = [];
        $needsTmdbIds = [];

        if (!$cfg['configured']) {
            $error = 'Configura la BD remota en Configuración → Peticiones / BD remota.';
        } else {
            try {
                PeticionesDatabase::reset();
                $counts = $this->repo->counts();
                $items = $this->repo->list($filter, $perPage, $offset);
                $motivos = $this->repo->activeMotivos();

                if ($cfg['tmdb_api_key'] !== '') {
                    $hydrated = $this->service->applyCachedMetadata($items);
                    $items = $hydrated['items'];
                    $platformsById = $hydrated['platformsById'];
                    $needsTmdbIds = $hydrated['needsTmdbIds'];
                }
            } catch (\Throwable $e) {
                $error = 'No se pudo conectar a la BD remota: ' . $e->getMessage()
                    . ' Comprueba firewall (MySQL debe aceptar la IP del VPS MultiPanel en el puerto 3306).';
            }
        }

        return $this->view('peticiones.index', [
            'title' => 'Peticiones',
            'filter' => $filter,
            'items' => $items,
            'counts' => $counts,
            'motivos' => $motivos,
            'platformsById' => $platformsById,
            'needsTmdbIds' => $needsTmdbIds,
            'error' => $error,
            'configured' => $cfg['configured'],
            'page' => $page,
            'perPage' => $perPage,
            'hasTmdb' => $cfg['tmdb_api_key'] !== '',
        ]);
    }

    public function action(Request $request): Response
    {
        $accion = (string) ($request->input('accion') ?? $request->input('accion_ajax') ?? '');
        $id = (int) ($request->input('id') ?? 0);

        try {
            PeticionesDatabase::reset();

            $result = match ($accion) {
                'aceptar' => $this->service->aceptar($id),
                'subir' => $this->service->subir($id),
                'denegar' => $this->service->denegar($id, (int) ($request->input('id_motivo') ?? 0)),
                'borrar' => $this->service->borrar($id),
                'rename', 'titulo' => $this->service->rename($id, (string) ($request->input('titulo') ?? '')),
                'meta' => $this->service->enrichCard($id),
                'actualizar-metadatos' => $this->service->refreshMetadata($this->intIds($request->input('ids') ?? [])),
                'add', 'anadir' => $this->service->addManual(
                    (string) ($request->input('url') ?? ''),
                    (string) ($request->input('titulo') ?? $request->input('nombrepeticion') ?? ''),
                    (string) ($request->input('img') ?? ''),
                    ($request->input('idusuario') !== null && $request->input('idusuario') !== '')
                        ? (string) $request->input('idusuario')
                        : null,
                    ($request->input('username') !== null && $request->input('username') !== '')
                        ? (string) $request->input('username')
                        : null,
                ),
                default => ['ok' => false, 'message' => 'Acción desconocida'],
            };
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $status = !empty($result['ok']) ? 200 : 422;

        return $this->json($result, $status);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function intIds(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = preg_split('/[,\s]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
