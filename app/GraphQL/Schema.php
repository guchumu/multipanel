<?php

declare(strict_types=1);

namespace App\GraphQL;

use App\Models\MediaUser;
use App\Models\Server;
use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\ServerSyncService;
use App\Services\StatsService;
use Core\GraphQL;
use Ramsey\Uuid\Uuid;

/**
 * GraphQL schema registration for MultiPanel.
 */
final class Schema
{
    public static function build(): GraphQL
    {
        $gql = new GraphQL();
        $mediaUsers = new MediaUserRepository();
        $servers = new ServerRepository();
        $stats = new StatsService();
        $sync = new ServerSyncService();

        $gql->query('dashboard', function ($vars, $tenantId) use ($stats) {
            $s = $stats->getDashboardStats($tenantId);
            return [
                'usersActive' => $s['users']['active'] ?? 0,
                'usersSuspended' => $s['users']['suspended'] ?? 0,
                'usersTotal' => $s['users']['total'] ?? 0,
                'serversOnline' => $s['servers']['online'] ?? 0,
                'serversTotal' => ($s['servers']['online'] ?? 0) + ($s['servers']['offline'] ?? 0),
            ];
        });

        $gql->query('servers', function ($vars, $tenantId) use ($servers) {
            return array_map(fn ($s) => self::formatServer($s), $servers->allByTenant($tenantId));
        });

        $gql->query('server', function ($vars, $tenantId) use ($servers) {
            $s = $servers->findByUuid($vars['uuid'] ?? '');
            return $s ? self::formatServer($s) : null;
        });

        $gql->query('mediaUsers', function ($vars, $tenantId) use ($mediaUsers) {
            $page = (int) ($vars['page'] ?? 1);
            $list = $mediaUsers->paginate($tenantId, $page, 20, $vars['status'] ?? null);
            return [
                'data' => array_map(fn ($u) => self::formatMediaUser($u), $list),
                'total' => $mediaUsers->countTotal($tenantId),
                'page' => $page,
            ];
        });

        $gql->query('mediaUser', function ($vars, $tenantId) use ($mediaUsers) {
            $u = $mediaUsers->findByUuid($vars['uuid'] ?? '');
            return $u ? self::formatMediaUser($u) : null;
        });

        $gql->query('stats', function ($vars, $tenantId) use ($stats) {
            $s = $stats->getDashboardStats($tenantId);
            return [
                'todaySessions' => $s['streaming']['today_sessions'] ?? 0,
                'todayHours' => $s['streaming']['today_hours'] ?? 0,
                'monthSessions' => $s['streaming']['month_sessions'] ?? 0,
                'mrr' => $s['billing']['mrr'] ?? 0,
            ];
        });

        $gql->query('health', fn () => [
            'status' => 'ok',
            'version' => config('app.version', '1.0.0'),
        ]);

        $gql->mutation('createMediaUser', function ($vars, $tenantId) {
            $user = new MediaUser([
                'tenant_id' => $tenantId,
                'uuid' => Uuid::uuid4()->toString(),
                'username' => $vars['username'],
                'email' => $vars['email'] ?? null,
                'status' => 'pending',
                'max_streams' => (int) ($vars['maxStreams'] ?? 1),
            ]);
            $user->save();
            return self::formatMediaUser($user);
        });

        $gql->mutation('suspendMediaUser', function ($vars) use ($mediaUsers) {
            $u = $mediaUsers->findByUuid($vars['uuid'] ?? '');
            if (!$u) {
                throw new \RuntimeException('User not found');
            }
            $u->status = 'suspended';
            $u->save();
            return self::formatMediaUser($u);
        });

        $gql->mutation('activateMediaUser', function ($vars) use ($mediaUsers) {
            $u = $mediaUsers->findByUuid($vars['uuid'] ?? '');
            if (!$u) {
                throw new \RuntimeException('User not found');
            }
            $u->status = 'active';
            $u->save();
            return self::formatMediaUser($u);
        });

        $gql->mutation('syncServer', function ($vars) use ($servers, $sync) {
            $s = $servers->findByUuid($vars['uuid'] ?? '');
            if (!$s) {
                throw new \RuntimeException('Server not found');
            }
            $ok = $sync->sync($s);
            return ['success' => $ok, 'status' => $s->status];
        });

        return $gql;
    }

    /** @return array<string, mixed> */
    private static function formatServer(Server $s): array
    {
        return [
            'uuid' => $s->uuid,
            'name' => $s->name,
            'type' => $s->type,
            'status' => $s->status,
            'activeSessions' => (int) $s->active_sessions,
            'version' => $s->version,
        ];
    }

    /** @return array<string, mixed> */
    private static function formatMediaUser(MediaUser $u): array
    {
        return [
            'uuid' => $u->uuid,
            'username' => $u->username,
            'email' => $u->email,
            'status' => $u->status,
            'maxStreams' => (int) $u->max_streams,
            'expiresAt' => $u->expires_at,
        ];
    }
}
