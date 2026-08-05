<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Services\Media\MediaServerFactory;
use Core\Database;
use Core\Logger;

/**
 * Agrupa bibliotecas con el mismo nombre normalizado entre servidores
 * y dispara escaneos en todas las del grupo (reutiliza refreshLibrary).
 */
final class LinkedLibraryService
{

    /**
     * Normaliza el nombre de categoría para vincular (trim + minúsculas + espacios).
     */
    public static function normalizeName(string $name): string
    {
        $name = trim(mb_strtolower($name));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name;
    }

    /**
     * Clave estable para URLs/API a partir del nombre normalizado.
     */
    public static function groupKey(string $normalizedName): string
    {
        return rtrim(strtr(base64_encode($normalizedName), '+/', '-_'), '=');
    }

    public static function decodeGroupKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        $pad = strlen($key) % 4;
        if ($pad > 0) {
            $key .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode(strtr($key, '-_', '+/'), true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array{
     *     groups: array<int, array{
     *         key: string,
     *         name: string,
     *         normalized_name: string,
     *         type: string,
     *         linked: bool,
     *         server_count: int,
     *         libraries: array<int, array{
     *             id: int,
     *             name: string,
     *             type: string,
     *             external_id: string,
     *             server_id: int,
     *             server_uuid: string,
     *             server_name: string,
     *             server_type: string
     *         }>
     *     }>,
     *     linked_count: int,
     *     total_libraries: int
     * }
     */
    public function getGroupedLibraries(int $tenantId): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT l.id, l.name, l.type, l.external_id, l.server_id,
                    s.uuid AS server_uuid, s.name AS server_name, s.type AS server_type
             FROM libraries l
             INNER JOIN servers s ON s.id = l.server_id
             WHERE s.tenant_id = ?
             ORDER BY l.name ASC, s.name ASC',
            [$tenantId]
        );

        $buckets = [];
        foreach ($rows as $row) {
            $displayName = trim((string) ($row['name'] ?? ''));
            if ($displayName === '') {
                continue;
            }
            $normalized = self::normalizeName($displayName);
            if (!isset($buckets[$normalized])) {
                $buckets[$normalized] = [
                    'key' => self::groupKey($normalized),
                    'name' => $displayName,
                    'normalized_name' => $normalized,
                    'type' => (string) ($row['type'] ?? ''),
                    'libraries' => [],
                    'server_ids' => [],
                ];
            }
            $sid = (int) $row['server_id'];
            $buckets[$normalized]['libraries'][] = [
                'id' => (int) $row['id'],
                'name' => $displayName,
                'type' => (string) ($row['type'] ?? ''),
                'external_id' => (string) ($row['external_id'] ?? ''),
                'server_id' => $sid,
                'server_uuid' => (string) ($row['server_uuid'] ?? ''),
                'server_name' => (string) ($row['server_name'] ?? ''),
                'server_type' => (string) ($row['server_type'] ?? ''),
            ];
            $buckets[$normalized]['server_ids'][$sid] = true;
            // Preferir el nombre con capitalización más "típica" (primera aparición ordenada)
            if ($buckets[$normalized]['type'] === '' && !empty($row['type'])) {
                $buckets[$normalized]['type'] = (string) $row['type'];
            }
        }

        $groups = [];
        $linkedCount = 0;
        foreach ($buckets as $bucket) {
            $serverCount = count($bucket['server_ids']);
            $linked = $serverCount >= 2;
            if ($linked) {
                $linkedCount++;
            }
            $groups[] = [
                'key' => $bucket['key'],
                'name' => $bucket['name'],
                'normalized_name' => $bucket['normalized_name'],
                'type' => $bucket['type'],
                'linked' => $linked,
                'server_count' => $serverCount,
                'libraries' => $bucket['libraries'],
            ];
        }

        usort($groups, static function (array $a, array $b): int {
            if ($a['linked'] !== $b['linked']) {
                return $a['linked'] ? -1 : 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return [
            'groups' => $groups,
            'linked_count' => $linkedCount,
            'total_libraries' => count($rows),
        ];
    }

    /**
     * Escanea todas las bibliotecas de un grupo (por clave) o todas las vinculadas.
     *
     * @return array{success: bool, message: string, scanned: int, failed: int, results: array<int, array<string, mixed>>}
     */
    public function scanGroup(int $tenantId, ?string $groupKey = null): array
    {
        $grouped = $this->getGroupedLibraries($tenantId);
        $targets = [];

        if ($groupKey === null || $groupKey === '' || $groupKey === 'all') {
            foreach ($grouped['groups'] as $group) {
                if ($group['linked']) {
                    foreach ($group['libraries'] as $lib) {
                        $targets[] = $lib + ['group_name' => $group['name']];
                    }
                }
            }
            $label = 'todas las categorías vinculadas';
        } else {
            $normalized = self::decodeGroupKey($groupKey);
            if ($normalized === null) {
                return [
                    'success' => false,
                    'message' => 'Categoría no válida.',
                    'scanned' => 0,
                    'failed' => 0,
                    'results' => [],
                ];
            }

            $found = null;
            foreach ($grouped['groups'] as $group) {
                if ($group['normalized_name'] === $normalized || $group['key'] === $groupKey) {
                    $found = $group;
                    break;
                }
            }

            if ($found === null) {
                return [
                    'success' => false,
                    'message' => 'No se encontró esa categoría vinculada.',
                    'scanned' => 0,
                    'failed' => 0,
                    'results' => [],
                ];
            }

            foreach ($found['libraries'] as $lib) {
                $targets[] = $lib + ['group_name' => $found['name']];
            }
            $label = $found['name'];
        }

        if ($targets === []) {
            return [
                'success' => false,
                'message' => 'No hay bibliotecas vinculadas para escanear. Sincroniza los servidores primero.',
                'scanned' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $serverCache = [];
        $scanned = 0;
        $failed = 0;
        $results = [];

        foreach ($targets as $lib) {
            $sid = (int) $lib['server_id'];
            if (!isset($serverCache[$sid])) {
                $server = Server::find($sid);
                if ($server === null || (int) $server->tenant_id !== $tenantId) {
                    $failed++;
                    $results[] = [
                        'success' => false,
                        'server' => (string) $lib['server_name'],
                        'library' => (string) $lib['name'],
                        'message' => 'Servidor no encontrado',
                    ];
                    continue;
                }
                $serverCache[$sid] = $server;
            }

            /** @var Server $server */
            $server = $serverCache[$sid];
            $externalId = (string) $lib['external_id'];

            try {
                $media = MediaServerFactory::make($server);
                $ok = $media->refreshLibrary($externalId);
                if ($ok) {
                    $scanned++;
                    $results[] = [
                        'success' => true,
                        'server' => (string) $server->name,
                        'library' => (string) $lib['name'],
                        'message' => 'Escaneo iniciado',
                    ];
                } else {
                    $failed++;
                    $results[] = [
                        'success' => false,
                        'server' => (string) $server->name,
                        'library' => (string) $lib['name'],
                        'message' => 'No se pudo iniciar el escaneo',
                    ];
                }
            } catch (\Throwable $e) {
                $failed++;
                Logger::error('linked_library.scan failed', [
                    'server_id' => $sid,
                    'external_id' => $externalId,
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'success' => false,
                    'server' => (string) $server->name,
                    'library' => (string) $lib['name'],
                    'message' => $e->getMessage(),
                ];
            }
        }

        $success = $scanned > 0;
        try {
            AuditService::log('libraries.linked_scanned', 'library', null, null, [
                'group' => $label,
                'scanned' => $scanned,
                'failed' => $failed,
            ]);
        } catch (\Throwable) {
        }

        $message = $success
            ? sprintf(
                'Escaneo iniciado en %d biblioteca%s (%s)%s.',
                $scanned,
                $scanned === 1 ? '' : 's',
                $label,
                $failed > 0 ? sprintf(', %d con error', $failed) : ''
            )
            : sprintf('No se pudo iniciar el escaneo de %s.', $label);

        return [
            'success' => $success,
            'message' => $message,
            'scanned' => $scanned,
            'failed' => $failed,
            'results' => $results,
        ];
    }
}
