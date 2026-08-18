<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Peticiones\PeticionesDatabase;

/**
 * Acceso a tablas remotas legacy `peticiones` y `motivo` (sin migrar esquema).
 */
class PeticionesRepository
{
    public const FILTER_PENDIENTES = 'pendientes';
    public const FILTER_PROCESO = 'proceso';
    public const FILTER_DENEGADAS = 'denegadas';
    public const FILTER_TODAS = 'todas';

    public function __construct(
        private ?PeticionesDatabase $db = null,
    ) {
    }

    private function db(): PeticionesDatabase
    {
        return $this->db ??= PeticionesDatabase::getInstance();
    }

    /**
     * @return array{pendientes: int, proceso: int, denegadas: int, todas: int}
     */
    public function counts(): array
    {
        $db = $this->db();

        $pendientes = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM peticiones
             WHERE subido = '0' AND aceptado = '0'
               AND (activo = '1' AND (idmotivo = '0' OR idmotivo IS NULL))"
        )['c'] ?? 0);

        $proceso = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM peticiones WHERE subido = '0' AND aceptado = '1'"
        )['c'] ?? 0);

        $denegadas = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM peticiones
             WHERE subido = '0' AND (activo = '0' OR idmotivo > '0')"
        )['c'] ?? 0);

        $todas = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM peticiones WHERE subido = '0'"
        )['c'] ?? 0);

        return [
            'pendientes' => $pendientes,
            'proceso' => $proceso,
            'denegadas' => $denegadas,
            'todas' => $todas,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function list(string $filter, int $limit = 48, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = match ($filter) {
            self::FILTER_PENDIENTES => "subido = '0' AND aceptado = '0' AND (activo = '1' AND (idmotivo = '0' OR idmotivo IS NULL))",
            self::FILTER_PROCESO => "subido = '0' AND aceptado = '1'",
            self::FILTER_DENEGADAS => "subido = '0' AND (activo = '0' OR idmotivo > '0')",
            default => "subido = '0'",
        };

        return $this->db()->fetchAll(
            "SELECT * FROM peticiones WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}"
        );
    }

    public function find(int $id): ?array
    {
        return $this->db()->fetchOne('SELECT * FROM peticiones WHERE id = ? LIMIT 1', [$id]);
    }

    public function updateTitle(int $id, string $title): void
    {
        $this->db()->query(
            'UPDATE peticiones SET nombrepeticion = ? WHERE id = ?',
            [$title, $id]
        );
    }

    public function accept(int $id, string $now): void
    {
        $this->db()->query(
            "UPDATE peticiones SET aceptado = '1', fechasino = ?, idmotivo = '0', activo = '1' WHERE id = ?",
            [$now, $id]
        );
    }

    public function markUploaded(int $id, string $now): void
    {
        $this->db()->query(
            "UPDATE peticiones SET subido = '1', fechasubida = ?, activo = '0', idmotivo = '0' WHERE id = ?",
            [$now, $id]
        );
    }

    public function deny(int $id, int $motivoId, string $now): void
    {
        $this->db()->query(
            "UPDATE peticiones SET aceptado = '0', activo = '0', idmotivo = ?, fechasino = ? WHERE id = ?",
            [$motivoId, $now, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db()->query('DELETE FROM peticiones WHERE id = ?', [$id]);
    }

    /**
     * @param array{url: string, nombrepeticion: string, img: string, idusuario?: string|null, username?: string|null} $data
     */
    public function insertManual(array $data, string $now): int
    {
        // Misma forma que el panel legacy (sin scrape).
        $this->db()->query(
            "INSERT INTO peticiones (url, nombrepeticion, img, subido, aceptado, activo, fechapeticion)
             VALUES (?, ?, ?, '0', '0', '1', ?)",
            [
                $data['url'],
                $data['nombrepeticion'],
                $data['img'],
                $now,
            ]
        );

        $id = (int) $this->db()->pdo()->lastInsertId();

        $idusuario = isset($data['idusuario']) ? trim((string) $data['idusuario']) : '';
        $username = isset($data['username']) ? trim((string) $data['username']) : '';
        if ($id > 0 && ($idusuario !== '' || $username !== '')) {
            $this->db()->query(
                'UPDATE peticiones SET idusuario = COALESCE(NULLIF(?, \'\'), idusuario), username = COALESCE(NULLIF(?, \'\'), username) WHERE id = ?',
                [$idusuario, $username, $id]
            );
        }

        return $id;
    }

    /** @return array<int, array<string, mixed>> */
    public function activeMotivos(): array
    {
        return $this->db()->fetchAll(
            "SELECT * FROM motivo WHERE activo = '1' ORDER BY nombre ASC"
        );
    }

    public function motivoNombre(int $id): string
    {
        $row = $this->db()->fetchOne('SELECT nombre FROM motivo WHERE id = ? LIMIT 1', [$id]);

        return (string) ($row['nombre'] ?? 'Sin motivo');
    }

    /**
     * Intentos de enlazar peticiones al cliente por username (columna legacy opcional).
     *
     * @return array{ok: bool, items: array<int, array<string, mixed>>, note?: string}
     */
    public function listForUsername(string $username, int $limit = 10): array
    {
        $username = trim($username);
        if ($username === '') {
            return ['ok' => false, 'items' => [], 'note' => 'Sin usuario para filtrar.'];
        }

        $limit = max(1, min(50, $limit));

        try {
            $items = $this->db()->fetchAll(
                "SELECT id, nombrepeticion, subido, aceptado, activo, idmotivo, fechapeticion, username
                 FROM peticiones
                 WHERE username = ? AND subido = '0'
                 ORDER BY id DESC
                 LIMIT {$limit}",
                [$username]
            );

            return ['ok' => true, 'items' => $items];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'items' => [],
                'note' => 'No se pudo consultar peticiones por usuario (columna o conexión).',
            ];
        }
    }
}
