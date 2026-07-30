<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\Updater;

/**
 * System updater controller.
 */
class UpdaterController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private Updater $updater = new Updater(),
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view('updater.index', [
            'title' => 'Actualizaciones',
            'status' => $this->updater->checkForUpdates(),
            'pending' => $this->updater->getPendingMigrations(),
        ]);
    }

    public function run(Request $request): Response
    {
        $results = $this->updater->runMigrations();

        if (empty($results)) {
            Session::getInstance()->flash('success', 'Sistema actualizado. No había migraciones pendientes.');
        } else {
            Session::getInstance()->flash('success', 'Migraciones ejecutadas: ' . count($results));
        }

        return $this->redirect('/updater');
    }
}
