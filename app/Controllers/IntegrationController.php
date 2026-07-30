<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\Integrations\SonarrService;
use App\Services\Integrations\RadarrService;
use App\Services\Integrations\TautulliService;
use App\Services\Integrations\OverseerrService;
use App\Services\Integrations\LidarrService;
use App\Services\Integrations\ProwlarrService;
use App\Services\Integrations\BazarrService;
use App\Services\Integrations\OmbiService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Updater;

/**
 * Third-party integrations management.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $integrations = Database::getInstance()->fetchAll(
            'SELECT * FROM integrations WHERE tenant_id = ? ORDER BY type, name',
            [$tenantId]
        );

        return $this->view('integrations.index', [
            'title' => 'Integraciones',
            'integrations' => $integrations,
            'types' => ['sonarr', 'radarr', 'tautulli', 'overseerr', 'lidarr', 'prowlarr', 'bazarr', 'ombi'],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|max:100',
            'type' => 'required|in:sonarr,radarr,tautulli,overseerr,lidarr,prowlarr,bazarr,ombi',
            'url' => 'required|url',
            'api_key' => 'required',
        ]);

        Database::getInstance()->insert('integrations', [
            'tenant_id' => (int) ($this->auth->user()->tenant_id ?? 1),
            'type' => $data['type'],
            'name' => $data['name'],
            'url' => $data['url'],
            'api_key' => $data['api_key'],
        ]);

        Session::getInstance()->flash('success', 'Integración añadida.');
        return $this->redirect('/integrations');
    }

    public function test(Request $request, int $id): Response
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM integrations WHERE id = ?', [$id]);
        if (!$row) {
            return $this->json(['error' => 'No encontrada'], 404);
        }

        $service = $this->makeService($row);
        $connected = $service?->testConnection() ?? false;

        Database::getInstance()->update('integrations', [
            'last_check_at' => date('Y-m-d H:i:s'),
            'last_error' => $connected ? null : 'Connection failed',
        ], 'id = ?', [$id]);

        return $this->json(['connected' => $connected]);
    }

    public function stats(Request $request, int $id): Response
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM integrations WHERE id = ?', [$id]);
        if (!$row) {
            return $this->json(['error' => 'No encontrada'], 404);
        }

        $service = $this->makeService($row);
        $stats = $service?->getStats() ?? [];

        return $this->json(['data' => $stats]);
    }

    public function destroy(Request $request, int $id): Response
    {
        Database::getInstance()->delete('integrations', 'id = ?', [$id]);
        Session::getInstance()->flash('success', 'Integración eliminada.');
        return $this->redirect('/integrations');
    }

    /** @param array<string, mixed> $row */
    private function makeService(array $row): object|null
    {
        return match ($row['type']) {
            'sonarr' => new SonarrService($row['url'], $row['api_key']),
            'radarr' => new RadarrService($row['url'], $row['api_key']),
            'tautulli' => new TautulliService($row['url'], $row['api_key']),
            'overseerr' => new OverseerrService($row['url'], $row['api_key']),
            'lidarr' => new LidarrService($row['url'], $row['api_key']),
            'prowlarr' => new ProwlarrService($row['url'], $row['api_key']),
            'bazarr' => new BazarrService($row['url'], $row['api_key']),
            'ombi' => new OmbiService($row['url'], $row['api_key']),
            default => null,
        };
    }
}
