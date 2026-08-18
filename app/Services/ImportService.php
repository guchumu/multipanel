<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Services\Import\ServicioServerMapper;
use App\Services\PasswordService;
use Core\Database;
use Core\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Bulk import service for CSV and JSON files.
 */
final class ImportService
{
    public function __construct(
        private PasswordService $passwords = new PasswordService(),
        private AuditService $audit = new AuditService(),
    ) {
    }

    /** @return array{imported: int, errors: array<int, string>} */
    public function importMediaUsersFromCsv(string $filePath, int $tenantId): array
    {
        $imported = 0;
        $errors = [];

        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            return ['imported' => 0, 'errors' => ['No se pudo abrir el archivo.']];
        }

        $headers = fgetcsv($fp);
        if (!$headers) {
            fclose($fp);
            return ['imported' => 0, 'errors' => ['CSV vacío o inválido.']];
        }

        $headers = array_map('strtolower', array_map('trim', $headers));
        $line = 1;

        while (($row = fgetcsv($fp)) !== false) {
            $line++;
            if (count($row) < count($headers)) {
                $errors[] = "Línea {$line}: columnas insuficientes";
                continue;
            }

            $data = array_combine($headers, $row);
            if (!$data || empty($data['username'])) {
                $errors[] = "Línea {$line}: username requerido";
                continue;
            }

            if (!$this->passesServicioFilter($data)) {
                continue;
            }

            try {
                $existing = Database::getInstance()->fetchOne(
                    'SELECT id FROM media_users WHERE tenant_id = ? AND username = ? AND deleted_at IS NULL',
                    [$tenantId, $data['username']]
                );

                if ($existing) {
                    $errors[] = "Línea {$line}: username '{$data['username']}' ya existe";
                    continue;
                }

                $password = $data['password'] ?? $this->passwords->generate();

                $telegramChatId = $this->resolveImportTelegram($data);
                $expiresAt = $this->resolveImportExpires($data);
                $notes = $this->resolveImportNotes($data);

                $user = new MediaUser([
                    'tenant_id' => $tenantId,
                    'uuid' => Uuid::uuid4()->toString(),
                    'username' => $data['username'],
                    'email' => $data['email'] ?? null,
                    'password' => $this->passwords->hash($password),
                    'display_name' => $data['display_name'] ?? $data['name'] ?? null,
                    'status' => $data['status'] ?? 'pending',
                    'max_streams' => (int) ($data['max_streams'] ?? 1),
                    'max_devices' => (int) ($data['max_devices'] ?? 5),
                    'expires_at' => $expiresAt,
                    'telegram_chat_id' => $telegramChatId,
                    'notes' => $notes,
                ]);

                $user->save();
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Línea {$line}: {$e->getMessage()}";
            }
        }

        fclose($fp);
        Logger::info('CSV import completed', ['imported' => $imported, 'errors' => count($errors)]);
        $this->audit->log('import.media_users', 'media_user', null, null, ['imported' => $imported]);

        return ['imported' => $imported, 'errors' => $errors];
    }

    /** @return array{imported: int, errors: array<int, string>} */
    public function importMediaUsersFromJson(string $filePath, int $tenantId): array
    {
        $content = file_get_contents($filePath);
        $rows = json_decode($content ?: '[]', true);

        if (!is_array($rows)) {
            return ['imported' => 0, 'errors' => ['JSON inválido.']];
        }

        $imported = 0;
        $errors = [];

        foreach ($rows as $i => $data) {
            if (!is_array($data) || empty($data['username'])) {
                $errors[] = "Registro {$i}: username requerido";
                continue;
            }

            if (!$this->passesServicioFilter($data)) {
                continue;
            }

            try {
                $password = $data['password'] ?? $this->passwords->generate();
                $user = new MediaUser([
                    'tenant_id' => $tenantId,
                    'uuid' => Uuid::uuid4()->toString(),
                    'username' => $data['username'],
                    'email' => $data['email'] ?? null,
                    'password' => $this->passwords->hash($password),
                    'status' => $data['status'] ?? 'pending',
                    'max_streams' => (int) ($data['max_streams'] ?? 1),
                    'expires_at' => $this->resolveImportExpires($data),
                    'telegram_chat_id' => $this->resolveImportTelegram($data),
                    'notes' => $this->resolveImportNotes($data),
                ]);
                $user->save();
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Registro {$i}: {$e->getMessage()}";
            }
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Si la fila trae columna servicio/service, solo se aceptan códigos 1 y 5.
     * Sin esa columna se importa (CSV genérico).
     *
     * @param array<string, mixed> $data
     */
    private function passesServicioFilter(array $data): bool
    {
        $raw = $data['servicio'] ?? $data['service'] ?? null;
        if ($raw === null || $raw === '') {
            return true;
        }
        if (!is_numeric($raw)) {
            return false;
        }

        return ServicioServerMapper::isAllowed((int) $raw);
    }

    /** @param array<string, mixed> $data */
    private function resolveImportTelegram(array $data): ?string
    {
        foreach (['telegram_chat_id', 'telegram_id', 'idcliente', 'client_id'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '' && $value !== '0') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function resolveImportExpires(array $data): ?string
    {
        foreach (['expires_at', 'end_date', 'expiration', 'fecha_fin'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '' && !str_starts_with($value, '0000-00-00')) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function resolveImportNotes(array $data): ?string
    {
        foreach (['private_notes', 'notes', 'admin_notes', 'nota', 'notas'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
