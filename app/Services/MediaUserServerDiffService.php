<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Repositories\ServerRepository;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use Core\Database;

/**
 * Compara shares reales de Plex (shared_servers) con media_users del panel.
 */
final class MediaUserServerDiffService
{
    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
    ) {
    }

    /**
     * @return array{
     *   servers: list<array{
     *     server_id: int,
     *     server_name: string,
     *     ok: bool,
     *     error: ?string,
     *     plex_total: int,
     *     panel_total: int,
     *     matched: int,
     *     only_on_plex: list<array{email: string, username: string, user_id: string, share_id: int}>,
     *     only_on_panel: list<array{uuid: string, email: string, username: string, status: string, on_server: ?int, expires_at: ?string}>
     *   }>,
     *   totals: array{plex: int, panel: int, matched: int, only_on_plex: int, only_on_panel: int}
     * }
     */
    public function compare(int $tenantId, ?int $serverId = null): array
    {
        $all = $this->servers->allByTenant($tenantId);
        $targets = [];
        foreach ($all as $server) {
            if (!$server->isPlex()) {
                continue;
            }
            if ($serverId !== null && (int) $server->id !== $serverId) {
                continue;
            }
            $targets[] = $server;
        }

        $serversOut = [];
        $totals = ['plex' => 0, 'panel' => 0, 'matched' => 0, 'only_on_plex' => 0, 'only_on_panel' => 0];

        foreach ($targets as $server) {
            $block = $this->compareServer($tenantId, $server);
            $serversOut[] = $block;
            $totals['plex'] += $block['plex_total'];
            $totals['panel'] += $block['panel_total'];
            $totals['matched'] += $block['matched'];
            $totals['only_on_plex'] += count($block['only_on_plex']);
            $totals['only_on_panel'] += count($block['only_on_panel']);
        }

        return ['servers' => $serversOut, 'totals' => $totals];
    }

    /**
     * @return array{
     *   server_id: int,
     *   server_name: string,
     *   ok: bool,
     *   error: ?string,
     *   plex_total: int,
     *   panel_total: int,
     *   matched: int,
     *   only_on_plex: list<array{email: string, username: string, user_id: string, share_id: int}>,
     *   only_on_panel: list<array{uuid: string, email: string, username: string, status: string, on_server: ?int, expires_at: ?string}>
     * }
     */
    private function compareServer(int $tenantId, Server $server): array
    {
        $base = [
            'server_id' => (int) $server->id,
            'server_name' => (string) $server->name,
            'ok' => false,
            'error' => null,
            'plex_total' => 0,
            'panel_total' => 0,
            'matched' => 0,
            'only_on_plex' => [],
            'only_on_panel' => [],
        ];

        $media = MediaServerFactory::make($server);
        if (!$media instanceof PlexService) {
            $base['error'] = 'No es un servidor Plex.';

            return $base;
        }

        $fetch = $media->fetchSharedServers();
        if (empty($fetch['ok'])) {
            $base['error'] = (string) ($fetch['error'] ?? 'No se pudo leer shared_servers de plex.tv');

            return $base;
        }

        /** @var list<array{id: int, user_id: string, username: string, email: string}> $shares */
        $shares = $fetch['shares'] ?? [];
        $panelRows = Database::getInstance()->fetchAll(
            'SELECT uuid, email, username, status, on_server, expires_at, external_id
             FROM media_users
             WHERE tenant_id = ? AND server_id = ? AND deleted_at IS NULL
             ORDER BY email ASC, username ASC',
            [$tenantId, (int) $server->id]
        );

        $matchedPanelIds = [];
        $onlyOnPlex = [];

        foreach ($shares as $share) {
            $email = strtolower(trim((string) ($share['email'] ?? '')));
            $username = strtolower(trim((string) ($share['username'] ?? '')));
            $userId = trim((string) ($share['user_id'] ?? ''));
            $panelMatch = $this->findPanelMatch($panelRows, $email, $username, $userId);

            if ($panelMatch !== null) {
                $matchedPanelIds[$panelMatch['_idx']] = true;
                continue;
            }

            $onlyOnPlex[] = [
                'email' => (string) ($share['email'] ?? ''),
                'username' => (string) ($share['username'] ?? ''),
                'user_id' => $userId,
                'share_id' => (int) ($share['id'] ?? 0),
            ];
        }

        $onlyOnPanel = [];
        foreach ($panelRows as $idx => $row) {
            if (isset($matchedPanelIds[$idx])) {
                continue;
            }
            $onlyOnPanel[] = [
                'uuid' => (string) ($row['uuid'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'on_server' => isset($row['on_server']) ? (int) $row['on_server'] : null,
                'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            ];
        }

        $matched = count($matchedPanelIds);

        return [
            'server_id' => (int) $server->id,
            'server_name' => (string) $server->name,
            'ok' => true,
            'error' => null,
            'plex_total' => count($shares),
            'panel_total' => count($panelRows),
            'matched' => $matched,
            'only_on_plex' => $onlyOnPlex,
            'only_on_panel' => $onlyOnPanel,
        ];
    }

    /**
     * @param list<array<string, mixed>> $panelRows
     * @return array<string, mixed>|null
     */
    private function findPanelMatch(array $panelRows, string $email, string $username, string $userId): ?array
    {
        foreach ($panelRows as $idx => $row) {
            $rowEmail = strtolower(trim((string) ($row['email'] ?? '')));
            $rowUser = strtolower(trim((string) ($row['username'] ?? '')));
            $rowExt = trim((string) ($row['external_id'] ?? ''));

            if ($email !== '' && $rowEmail !== '' && $email === $rowEmail) {
                $row['_idx'] = $idx;

                return $row;
            }
            if ($userId !== '' && $rowExt !== '' && $userId === $rowExt) {
                $row['_idx'] = $idx;

                return $row;
            }
            if ($username !== '' && $rowUser !== '' && $username === $rowUser) {
                $row['_idx'] = $idx;

                return $row;
            }
        }

        return null;
    }
}
