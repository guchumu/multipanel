<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\BackupService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Backup management controller.
 */
class BackupController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private BackupService $backups = new BackupService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('backups.index', [
            'title' => 'Backups',
            'backups' => $this->backups->list($tenantId),
        ]);
    }

    public function create(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $result = $this->backups->create($tenantId);

        if ($result) {
            Session::getInstance()->flash('success', 'Backup creado: ' . $result['filename']);
        } else {
            Session::getInstance()->flash('error', 'Error al crear el backup. Verifica mysqldump.');
        }

        return $this->redirect('/backups');
    }

    public function incremental(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $result = $this->backups->createIncremental($tenantId);

        if ($result) {
            Session::getInstance()->flash('success', 'Backup incremental: ' . $result['filename']);
        } else {
            Session::getInstance()->flash('error', 'Error al crear backup incremental.');
        }

        return $this->redirect('/backups');
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->backups->delete($id);
        Session::getInstance()->flash('success', 'Backup eliminado.');
        return $this->redirect('/backups');
    }

    public function download(Request $request, int $id): Response
    {
        $backup = \Core\Database::getInstance()->fetchOne('SELECT * FROM backups WHERE id = ?', [$id]);
        if (!$backup || !file_exists($backup['path'])) {
            Session::getInstance()->flash('error', 'Archivo no encontrado.');
            return $this->redirect('/backups');
        }

        return Response::download($backup['path'], $backup['filename']);
    }
}
