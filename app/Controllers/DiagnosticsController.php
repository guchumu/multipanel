<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\DiagnosticsService;
use App\Services\LicenseService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * System diagnostics and health panel.
 */
class DiagnosticsController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private DiagnosticsService $diagnostics = new DiagnosticsService(),
        private LicenseService $license = new LicenseService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $checks = $this->diagnostics->runAll();
        $score = $this->diagnostics->getScore();

        return $this->view('diagnostics.index', [
            'title' => 'Diagnósticos',
            'checks' => $checks,
            'score' => $score,
            'license' => $this->license->getLicenseInfo(),
            'phpInfo' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memory' => ini_get('memory_limit'),
                'upload' => ini_get('upload_max_filesize'),
                'timezone' => date_default_timezone_get(),
            ],
        ]);
    }

    public function run(Request $request): Response
    {
        return $this->json([
            'score' => $this->diagnostics->getScore(),
            'checks' => $this->diagnostics->runAll(),
            'timestamp' => date('c'),
        ]);
    }

    public function license(Request $request): Response
    {
        $key = $request->input('license_key', '');
        if (!$key) {
            Session::getInstance()->flash('error', 'Clave de licencia requerida.');
            return $this->redirect('/diagnostics');
        }

        if ($this->license->activate($key)) {
            Session::getInstance()->flash('success', 'Licencia activada correctamente.');
        } else {
            Session::getInstance()->flash('error', 'Clave de licencia inválida o expirada.');
        }

        return $this->redirect('/diagnostics');
    }
}
