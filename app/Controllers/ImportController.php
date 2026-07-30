<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ImportService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Bulk import/export controller.
 */
class ImportController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private ImportService $import = new ImportService(),
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->view('import.index', ['title' => 'Importar / Exportar']);
    }

    public function upload(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Session::getInstance()->flash('error', 'Archivo no válido.');
            return $this->redirect('/import');
        }

        $type = $request->input('type', 'csv');
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $tmpPath = $file['tmp_name'];

        $result = match ($type) {
            'json' => $this->import->importMediaUsersFromJson($tmpPath, $tenantId),
            default => $this->import->importMediaUsersFromCsv($tmpPath, $tenantId),
        };

        $msg = "Importados: {$result['imported']}";
        if (!empty($result['errors'])) {
            $msg .= '. Errores: ' . count($result['errors']);
            Session::getInstance()->flash('import_errors', implode("\n", array_slice($result['errors'], 0, 10)));
        }

        Session::getInstance()->flash('success', $msg);
        return $this->redirect('/import');
    }

    public function template(Request $request): never
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="media_users_template.csv"');
        echo "username,email,password,display_name,status,max_streams,max_devices,expires_at,notes\n";
        echo "usuario1,usuario1@email.com,,Usuario Uno,active,1,5,2026-12-31,\n";
        exit;
    }
}
