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
    /** Soft app ceiling for uploaded SQL (bytes). Align with public/.user.ini (64M). */
    private const MAX_UPLOAD_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private AuthService $auth = new AuthService(),
        private ImportService $import = new ImportService(),
        private PlexManagerImportService $plexManager = new PlexManagerImportService(),
    ) {
    }

    public function show(Request $request): Response
    {
        $importsDir = base_path('storage/imports');
        $serverFiles = [];
        if (is_dir($importsDir)) {
            foreach (glob($importsDir . '/*.{sql,txt,SQL,TXT}', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    $serverFiles[] = [
                        'name' => basename($file),
                        'bytes' => (int) filesize($file),
                    ];
                }
            }
        }

        return $this->view('import.index', [
            'title' => 'Importar / Exportar',
            'serverFiles' => $serverFiles,
            'phpUploadMax' => (string) ini_get('upload_max_filesize'),
            'phpPostMax' => (string) ini_get('post_max_size'),
        ]);
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
        $type = (string) $request->input('type', 'csv');
        $serverPathInput = trim((string) $request->input('server_path', ''));
        $tmpPath = null;
        $originalName = '';

        if ($serverPathInput !== '') {
            $resolved = $this->resolveImportServerPath($serverPathInput);
            if ($resolved === null) {
                Session::getInstance()->flash(
                    'error',
                    'No se encontró el archivo en storage/imports/. Sube por FTP el SQL a esa carpeta (solo el nombre del archivo, ej. plex_manager.sql).'
                );
                return $this->redirect('/import');
            }
            $tmpPath = $resolved;
            $originalName = strtolower(basename($resolved));
            if ($type !== 'plex_manager' && (str_ends_with($originalName, '.sql') || str_contains($originalName, 'plex_manager'))) {
                $type = 'plex_manager';
            }
        } else {
            $file = $request->file('file');
            $contentLength = (int) ($request->header('Content-Length') ?? ($_SERVER['CONTENT_LENGTH'] ?? 0));

            if (!$file || !isset($file['error'])) {
                Session::getInstance()->flash('error', $this->missingUploadMessage($contentLength));
                return $this->redirect('/import');
            }

            $uploadError = (int) $file['error'];
            if ($uploadError !== UPLOAD_ERR_OK) {
                Session::getInstance()->flash('error', $this->uploadErrorMessage($uploadError, $contentLength, $file));
                return $this->redirect('/import');
            }

            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0 && is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                $size = (int) (@filesize((string) $file['tmp_name']) ?: 0);
            }

            if ($size > self::MAX_UPLOAD_BYTES) {
                Session::getInstance()->flash(
                    'error',
                    sprintf(
                        'Archivo de %.1f MB supera el límite de la app (%d MB). Súbelo por FTP a storage/imports/ e impórtalo con el nombre del archivo.',
                        $size / 1048576,
                        (int) (self::MAX_UPLOAD_BYTES / 1048576)
                    )
                );
                return $this->redirect('/import');
            }

            $originalName = strtolower((string) ($file['name'] ?? ''));
            if ($type !== 'plex_manager' && (str_ends_with($originalName, '.sql') || str_contains($originalName, 'plex_manager'))) {
                $type = 'plex_manager';
            }

            $tmpPath = (string) $file['tmp_name'];
        }

        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        if ($type === 'plex_manager') {
            $modeInput = (string) $request->input('mode', PlexManagerImportService::MODE_FULL);
            $mode = $modeInput === PlexManagerImportService::MODE_OVERLAY
                ? PlexManagerImportService::MODE_OVERLAY
                : PlexManagerImportService::MODE_FULL;

            $result = $this->plexManager->importFromSqlFile($tmpPath, $tenantId, $mode);
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
                    $detail .= ' El archivo llegó vacío — revisa upload_max_filesize/post_max_size en Plesk, o usa FTP → storage/imports/.';
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

            if ($mode === PlexManagerImportService::MODE_OVERLAY) {
                $userColumns = $result['user_columns'] ?? [];
                $columnsHint = is_array($userColumns) && $userColumns !== []
                    ? implode(', ', array_slice($userColumns, 0, 20))
                    : '(ninguna)';
                $msg = sprintf(
                    'Importar fechas/datos (solo servicio 1=Servitron/Server10, 5=NucBox): leídas %d users → %d coincidencias, %d filas actualizadas, %d omitidos por servicio, %d sin match en panel. Telegram escritos: %d (caducidad %d, notas %d). En SQL había Telegram en %d/%d users. BD tras import: Telegram=%d, caducidad=%d, email=%d (ids ej. %s). Columnas users: %s. CRM backfill: %d.',
                    $parsed['users'],
                    (int) ($result['matched'] ?? 0),
                    (int) ($result['updated'] ?? 0),
                    (int) ($result['skipped_servicio'] ?? 0),
                    (int) ($result['skipped'] ?? 0),
                    (int) ($result['applied_telegram'] ?? 0),
                    (int) ($result['applied_expires'] ?? 0),
                    (int) ($result['applied_notes'] ?? 0),
                    (int) ($result['sql_telegram'] ?? 0),
                    (int) ($parsed['users'] ?? 0),
                    (int) ($result['verified_telegram'] ?? 0),
                    (int) ($result['verified_expires'] ?? 0),
                    (int) ($result['verified_email'] ?? 0),
                    $this->formatSampleIds($result['sample_updated_ids'] ?? []),
                    $columnsHint,
                    (int) ($result['telegram_backfilled'] ?? 0)
                );
            } else {
                $userColumns = $result['user_columns'] ?? [];
                $columnsHint = is_array($userColumns) && $userColumns !== []
                    ? implode(', ', array_slice($userColumns, 0, 20))
                    : '(ninguna)';
                $msg = sprintf(
                    'Migración plex_manager (filtro servicio 1/5): leídas %d filas servers / %d users → importados %d servidores, %d usuarios nuevos, %d clientes, %d bibliotecas. Omitidos por servicio: %d. Omitidos/actualizados: %d. Telegram escritos: %d (caducidad %d, notas %d). En SQL había Telegram en %d/%d users. BD tras import: Telegram=%d, caducidad=%d, email=%d. Columnas users: %s. CRM backfill: %d.',
                    $parsed['servers'],
                    $parsed['users'],
                    $result['servers'],
                    $result['users'],
                    $result['customers'],
                    $result['libraries'] ?? 0,
                    (int) ($result['skipped_servicio'] ?? 0),
                    $result['skipped'],
                    (int) ($result['applied_telegram'] ?? 0),
                    (int) ($result['applied_expires'] ?? 0),
                    (int) ($result['applied_notes'] ?? 0),
                    (int) ($result['sql_telegram'] ?? 0),
                    (int) ($parsed['users'] ?? 0),
                    (int) ($result['verified_telegram'] ?? 0),
                    (int) ($result['verified_expires'] ?? 0),
                    (int) ($result['verified_email'] ?? 0),
                    $columnsHint,
                    (int) ($result['telegram_backfilled'] ?? 0)
                );
            }

            foreach ($result['sync'] ?? [] as $sync) {
                $msg .= sprintf(
                    ' Sync %s: %s.',
                    $sync['name'],
                    ($sync['ok'] ?? false) ? 'online' : ('offline' . (!empty($sync['error']) ? ' — ' . $sync['error'] : ''))
                );
            }

            $noWork = $mode === PlexManagerImportService::MODE_OVERLAY
                ? ((int) ($result['updated'] ?? 0) === 0 && (int) ($result['matched'] ?? 0) === 0)
                : ($result['servers'] === 0 && $result['users'] === 0 && $result['skipped'] === 0 && (int) ($result['updated'] ?? 0) === 0);

            if ($noWork && (int) ($result['skipped_servicio'] ?? 0) === 0) {
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
        echo "username,email,password,display_name,status,max_streams,max_devices,expires_at,telegram_chat_id,notes,servicio\n";
        echo "usuario1,usuario1@email.com,,Usuario Uno,active,1,5,2026-12-31,2023182976,,1\n";
        exit;
    }

    /**
     * Only basenames under storage/imports/ (FTP-safe path).
     */
    private function resolveImportServerPath(string $input): ?string
    {
        $importsDir = realpath(base_path('storage/imports'));
        if ($importsDir === false || !is_dir($importsDir)) {
            @mkdir(base_path('storage/imports'), 0775, true);
            $importsDir = realpath(base_path('storage/imports'));
            if ($importsDir === false) {
                return null;
            }
        }

        $normalized = str_replace('\\', '/', trim($input));
        $base = basename($normalized);
        if ($base === '' || $base === '.' || $base === '..' || str_contains($base, "\0")) {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
            return null;
        }

        $candidate = $importsDir . DIRECTORY_SEPARATOR . $base;
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }

        $importsPrefix = $importsDir . DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $importsPrefix) && $real !== $importsDir) {
            return null;
        }

        return $real;
    }

    private function parseIniSize(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (!preg_match('/^(\d+)\s*([KMG])?B?$/i', $value, $m)) {
            return (int) $value;
        }

        $n = (int) $m[1];
        return match (strtoupper($m[2] ?? '')) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }

    /** @param mixed $ids */
    private function formatSampleIds(mixed $ids): string
    {
        if (!is_array($ids) || $ids === []) {
            return '—';
        }

        $clean = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $clean[] = (string) $n;
            }
        }

        return $clean === [] ? '—' : implode(',', array_slice($clean, 0, 8));
    }

    /** @param array<string, mixed> $file */
    private function uploadErrorMessage(int $code, int $contentLength, array $file): string
    {
        $uploadMax = (string) ini_get('upload_max_filesize');
        $postMax = (string) ini_get('post_max_size');
        $uploadBytes = $this->parseIniSize($uploadMax);
        $postBytes = $this->parseIniSize($postMax);
        $limits = sprintf('Límites PHP actuales: upload_max_filesize=%s, post_max_size=%s.', $uploadMax ?: '?', $postMax ?: '?');
        $ftpHint = ' Alternativa: sube el SQL por FTP a storage/imports/ e impórtalo escribiendo el nombre del archivo (ej. plex_manager.sql).';

        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            if ($contentLength > 0 && $uploadBytes > 0 && $contentLength < $uploadBytes && $contentLength < max($postBytes, $uploadBytes)) {
                return 'La subida falló con error de tamaño, pero Content-Length ('
                    . round($contentLength / 1048576, 2) . ' MB) es menor que los límites PHP. '
                    . 'Puede ser un proxy/nginx (client_max_body_size) o un php.ini distinto al de Plesk. '
                    . $limits . $ftpHint;
            }

            return 'Archivo demasiado grande para la subida HTTP. ' . $limits . $ftpHint;
        }

        return match ($code) {
            UPLOAD_ERR_PARTIAL => 'La subida se interrumpió. Vuelve a intentarlo o usa FTP → storage/imports/.',
            UPLOAD_ERR_NO_FILE => 'No seleccionaste ningún archivo.',
            default => 'Error al subir el archivo (código ' . $code . '). ' . $limits . $ftpHint,
        };
    }

    private function missingUploadMessage(int $contentLength): string
    {
        $uploadMax = (string) ini_get('upload_max_filesize');
        $postMax = (string) ini_get('post_max_size');
        $postBytes = $this->parseIniSize($postMax);
        $ftpHint = ' Sube el SQL por FTP a storage/imports/ e impórtalo con el nombre del archivo.';

        if ($contentLength > 0 && $postBytes > 0 && $contentLength > $postBytes) {
            return sprintf(
                'La petición (%s MB) supera post_max_size (%s). Aumenta post_max_size/upload_max_filesize en Plesk o usa FTP.%s',
                round($contentLength / 1048576, 2),
                $postMax,
                $ftpHint
            );
        }

        if ($contentLength > 0) {
            return 'No se recibió el archivo (POST vacío). Límites PHP: upload_max_filesize='
                . ($uploadMax ?: '?') . ', post_max_size=' . ($postMax ?: '?') . '.' . $ftpHint;
        }

        return 'No se recibió ningún archivo. Selecciona un SQL o indica un fichero en storage/imports/.';
    }
}
