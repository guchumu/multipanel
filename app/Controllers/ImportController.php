<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ImportService;
use App\Services\PlexManagerImportService;
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
        private PlexManagerImportService $plexManager = new PlexManagerImportService(),
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->view('import.index', ['title' => 'Importar / Exportar']);
    }

    public function upload(Request $request): Response
    {
        try {
            return $this->handleUpload($request);
        } catch (\Throwable $e) {
            \Core\Logger::error('Import upload failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            Session::getInstance()->flash('error', 'Error al importar: ' . $e->getMessage());
            return $this->redirect('/import');
        }
    }

    private function handleUpload(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file || !isset($file['error'])) {
            Session::getInstance()->flash('error', 'No se recibió ningún archivo.');
            return $this->redirect('/import');
        }

        $uploadError = (int) $file['error'];
        if ($uploadError !== UPLOAD_ERR_OK) {
            Session::getInstance()->flash('error', $this->uploadErrorMessage($uploadError));
            return $this->redirect('/import');
        }

        $type = (string) $request->input('type', 'csv');
        $originalName = strtolower((string) ($file['name'] ?? ''));
        if ($type !== 'plex_manager' && (str_ends_with($originalName, '.sql') || str_contains($originalName, 'plex_manager'))) {
            $type = 'plex_manager';
        }

        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $tmpPath = (string) $file['tmp_name'];

        if ($type === 'plex_manager') {
            $result = $this->plexManager->importFromSqlFile($tmpPath, $tenantId);
            $parsed = $result['parsed'] ?? ['servers' => 0, 'users' => 0];
            $probe = $result['probe'] ?? [];

            if (($parsed['servers'] ?? 0) === 0 && ($parsed['users'] ?? 0) === 0) {
                $kb = (int) round(((int) ($probe['file_bytes'] ?? 0)) / 1024);
                $detail = sprintf(
                    'Archivo recibido: %d KB. Parser %s. INSERT `servers`: %s. INSERT `users`: %s.',
                    $kb,
                    $probe['parser'] ?? '?',
                    !empty($probe['has_servers_marker']) ? 'sí' : 'NO',
                    !empty($probe['has_users_marker']) ? 'sí' : 'NO'
                );

                if ($kb === 0) {
                    $detail .= ' El archivo llegó vacío — revisa upload_max_filesize/post_max_size en Plesk (necesitas ~256 KB mínimo).';
                } elseif (empty($probe['has_servers_marker']) && empty($probe['has_users_marker'])) {
                    $detail .= ' El contenido no parece un plex_manager.sql de phpMyAdmin.';
                } elseif (!empty($probe['has_servers_marker']) || !empty($probe['has_users_marker'])) {
                    $detail .= ' Revisa errores abajo o confirma Parser 3.1 tras git pull.';
                }

                Session::getInstance()->flash('error', $detail);
                if (!empty($result['errors'])) {
                    Session::getInstance()->flash('import_errors', implode("\n", array_slice($result['errors'], 0, 15)));
                }
                return $this->redirect('/import');
            }

            $msg = sprintf(
                'Migración plex_manager: leídas %d filas servers / %d users → importados %d servidores, %d usuarios, %d clientes, %d bibliotecas. Omitidos/actualizados: %d.',
                $parsed['servers'],
                $parsed['users'],
                $result['servers'],
                $result['users'],
                $result['customers'],
                $result['libraries'] ?? 0,
                $result['skipped']
            );

            foreach ($result['sync'] ?? [] as $sync) {
                $msg .= sprintf(
                    ' Sync %s: %s.',
                    $sync['name'],
                    ($sync['ok'] ?? false) ? 'online' : ('offline' . (!empty($sync['error']) ? ' — ' . $sync['error'] : ''))
                );
            }

            if ($result['servers'] === 0 && $result['users'] === 0 && $result['skipped'] === 0) {
                Session::getInstance()->flash('error', $msg . ' Revisa errores abajo.');
            } else {
                Session::getInstance()->flash('success', $msg);
            }

            if (!empty($result['errors'])) {
                Session::getInstance()->flash('import_errors', implode("\n", array_slice($result['errors'], 0, 15)));
            }

            return $this->redirect('/import');
        }

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

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Archivo demasiado grande. Sube el SQL por FTP o aumenta upload_max_filesize/post_max_size en PHP (Plesk).',
            UPLOAD_ERR_PARTIAL => 'La subida se interrumpió. Vuelve a intentarlo.',
            UPLOAD_ERR_NO_FILE => 'No seleccionaste ningún archivo.',
            default => 'Error al subir el archivo (código ' . $code . ').',
        };
    }
}
