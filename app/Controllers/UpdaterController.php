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
        $session = Session::getInstance();

        if ($results === []) {
            $session->flash('success', 'Sistema actualizado. No había migraciones pendientes.');
            return $this->redirect('/updater');
        }

        $ok = [];
        $errors = [];
        foreach ($results as $name => $status) {
            if ($status === 'ok') {
                $ok[] = $name;
            } else {
                $errors[] = "{$name}: {$status}";
            }
        }

        if ($ok !== []) {
            $session->flash(
                'success',
                'Migraciones aplicadas (' . count($ok) . '): ' . implode(', ', $ok)
            );
        }

        if ($errors !== []) {
            $session->flash('error', 'Errores al migrar: ' . implode(' | ', $errors));
        }

        return $this->redirect('/updater');
    }
}
