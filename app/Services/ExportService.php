<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Data export service (CSV, JSON).
 */
final class ExportService
{
    /** @param array<int, array<string, mixed>> $rows */
    public function toCsv(array $rows, array $columns, string $filename): string
    {
        $path = storage_path('exports/' . $filename);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException('No se pudo crear el archivo de exportación.');
        }

        fputcsv($fp, $columns);

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($fp, $line);
        }

        fclose($fp);
        return $path;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function toJson(array $rows, string $filename): string
    {
        $path = storage_path('exports/' . $filename);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $path;
    }

    public function downloadResponse(string $path, string $contentType = 'text/csv'): never
    {
        if (!file_exists($path)) {
            http_response_code(404);
            exit('Archivo no encontrado.');
        }

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
