<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\MediaUser;
use App\Models\Server;
use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\BillingSettingsService;
use App\Services\StreamingActivityService;
use App\Services\MediaUserBulkService;
use App\Services\MediaUserDedupeService;
use App\Services\IptvCleanupService;
use App\Services\MediaUserWipeService;
use App\Services\MediaUserMessageService;
use App\Services\MediaUserManagementService;
use App\Services\MediaUserActivityService;
use App\Services\MediaUserProvisioningService;
use App\Services\MonthlyRenewalEstimateService;
use App\Services\PasswordService;
use App\Services\PortalDefaultPasswordService;
use App\Services\PortalLoginLinkService;
use App\Services\ReengageCampaignService;
use App\Services\ServerSyncService;
use App\Services\StreamLimitSettingsService;
use App\Services\SubscriptionPeriod;
use App\Services\Notifications\NotificationService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;
use Ramsey\Uuid\Uuid;

/**
 * Media user management controller.
 */
class MediaUserController extends Controller
{
    public function __construct(
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
        private ServerRepository $servers = new ServerRepository(),
        private AuthService $auth = new AuthService(),
        private AuditService $audit = new AuditService(),
        private PasswordService $passwords = new PasswordService(),
        private MediaUserBulkService $bulk = new MediaUserBulkService(),
        private NotificationService $notifications = new NotificationService(),
        private MediaUserMessageService $messages = new MediaUserMessageService(),
        private MediaUserManagementService $management = new MediaUserManagementService(),
        private MediaUserActivityService $activity = new MediaUserActivityService(),
        private MediaUserProvisioningService $provisioning = new MediaUserProvisioningService(),
        private BillingService $billing = new BillingService(),
        private BillingSettingsService $billingSettings = new BillingSettingsService(),
        private StreamingActivityService $streaming = new StreamingActivityService(),
        private MediaUserDedupeService $dedupe = new MediaUserDedupeService(),
        private IptvCleanupService $iptvCleanup = new IptvCleanupService(),
        private MediaUserWipeService $wipe = new MediaUserWipeService(),
        private ServerSyncService $serverSync = new ServerSyncService(),
        private MonthlyRenewalEstimateService $monthlyEstimate = new MonthlyRenewalEstimateService(),
        private PortalDefaultPasswordService $portalPasswords = new PortalDefaultPasswordService(),
        private PortalLoginLinkService $portalLinks = new PortalLoginLinkService(),
        private ReengageCampaignService $reengage = new ReengageCampaignService(),
    ) {
    }

    /** Hub: borrar todos → sync servidores → importar fechas (servicio 1/5). */
    public function cleanupHub(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $activeCount = 0;
        try {
            $row = \Core\Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS c FROM media_users WHERE tenant_id = ? AND deleted_at IS NULL',
                [$tenantId]
            );
            $activeCount = (int) ($row['c'] ?? 0);
        } catch (\Throwable) {
            $activeCount = 0;
        }

        return $this->view('media_users.limpieza', [
            'title' => 'Limpieza / reinicio usuarios',
            'servers' => $this->servers->allByTenant($tenantId),
            'activeCount' => $activeCount,
            'confirmPhrase' => MediaUserWipeService::CONFIRM_PHRASE,
            'servicioLabels' => config('import_servicio.labels', [1 => 'Server10', 5 => 'NucBox']),
            'servicioMap' => config('import_servicio.map', []),
            'portalDefaultPassword' => PortalDefaultPasswordService::DEFAULT_PASSWORD,
        ]);
    }

    public function wipeAll(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $confirm = trim((string) $request->input('confirm', ''));
        $result = $this->wipe->softDeleteAll($tenantId, $confirm);

        if (!$result['ok']) {
            Session::getInstance()->flash('error', implode(' ', $result['errors']));
            return $this->redirect('/media-users/limpieza');
        }

        Session::getInstance()->flash('success', sprintf(
            'Soft-delete de %d usuarios media en el panel. No se borró nadie en Plex/Jellyfin. Siguiente paso: Forzar sincronización.',
            $result['deleted']
        ));

        return $this->redirect('/media-users/limpieza');
    }

    /** Asigna la contraseña de portal por defecto a todos los usuarios media. */
    public function setPortalDefaultPasswords(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $confirm = trim((string) $request->input('confirm', ''));
        $expected = 'PONER PASSWORD PORTAL';
        if ($confirm !== $expected) {
            Session::getInstance()->flash('error', 'Escribe exactamente: ' . $expected);
            return $this->redirect('/media-users/limpieza');
        }

        $result = $this->portalPasswords->setDefaultForAllUsers($tenantId);
        Session::getInstance()->flash(
            !empty($result['success']) ? 'success' : 'error',
            (string) ($result['message'] ?? 'Hecho')
        );

        return $this->redirect('/media-users/limpieza');
    }

    public function activity(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('media_users.activity', [
            'title' => 'Actividad de usuarios',
            'events' => $this->activity->recentForTenant($tenantId, 150),
        ]);
    }

    public function expiring(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $this->dedupe->mergeDuplicatesForTenant($tenantId);
        $days = max(1, (int) $request->input('days', 30));
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;

        $users = $this->mediaUsers->findExpiringSoon($tenantId, $days, $serverId);
        $this->reengage::ensureTable();
        $reengageStats = $this->reengage->stats($tenantId);
        $reengageCfg = $this->reengage->getConfig($tenantId);

        return $this->view('media_users.expiring', [
            'title' => 'Próximos vencimientos',
            'users' => $users,
            'servers' => $this->servers->allByTenant($tenantId),
            'currentDays' => $days,
            'currentServerId' => $serverId,
            'reengageStats' => $reengageStats,
            'reengageCfg' => $reengageCfg,
        ]);
    }

    /** Estimación mensual de caducidades / renovaciones previstas por servidor. */
    public function estimacion(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $monthsAhead = max(0, min(24, (int) $request->input('months', 12)));

        $estimate = $this->monthlyEstimate->estimate($tenantId, $monthsAhead, $serverId);

        return $this->view('media_users.estimacion', [
            'title' => 'Estimación mensual',
            'estimate' => $estimate,
            'servers' => $this->servers->allByTenant($tenantId),
            'currentServerId' => $serverId,
            'monthsAhead' => $monthsAhead,
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->redirect('/media-users');
        }

        $this->mediaUsers->ensureJellyfinPasswordColumn();

        $server = null;
        $serverType = null;
        if ($user->server_id) {
            $server = Server::find((int) $user->server_id);
            if ($server) {
                $user->server_name = $server->name;
                $serverType = (string) $server->type;
            }
        }

        $nowPlaying = [];
        if ($user->server_id) {
            $nowPlaying = $this->streaming->getSessionsForUser(
                (int) $user->tenant_id,
                (int) $user->server_id,
                (string) $user->username,
                $user->display_name ?? null
            );
        }

        $endpoints = (new \App\Services\MediaUserEndpointService())->listForUser((int) $user->id);

        $jellyfinPassword = null;
        $credentialsText = null;
        if ($serverType === 'jellyfin') {
            $jellyfinPassword = $this->provisioning->revealJellyfinPassword($user);
            if ($jellyfinPassword !== null && $server !== null) {
                $credentialsText = $this->provisioning->credentialsShareText($user, $server, $jellyfinPassword);
            }
        }

        $flashCredentials = Session::getInstance()->getFlash('jellyfin_credentials');
        if (is_array($flashCredentials)) {
            if (!empty($flashCredentials['password'])) {
                $jellyfinPassword = (string) $flashCredentials['password'];
            }
            if (!empty($flashCredentials['text'])) {
                $credentialsText = (string) $flashCredentials['text'];
            } elseif ($jellyfinPassword !== null && $server !== null) {
                $credentialsText = $this->provisioning->credentialsShareText($user, $server, $jellyfinPassword);
            }
        }

        return $this->view('media_users.show', [
            'title' => $user->display_name ?? $user->username,
            // Must not be named "user": AuthMiddleware shares auth $user for the layout/navbar.
            'mediaUser' => $user,
            'serverType' => $serverType,
            'jellyfinPassword' => $jellyfinPassword,
            'credentialsText' => $credentialsText,
            'timeline' => $this->activity->timeline((int) $user->id),
            'messages' => $this->messages->listForUser((int) $user->id, 20),
            'renewalPresets' => $this->billingSettings->getRenewalPresets((int) ($user->tenant_id ?? 1)),
            'defaultMaxStreams' => (new \App\Services\StreamLimitSettingsService())->getDefaultMaxStreams((int) ($user->tenant_id ?? 1)),
            'defaultMaxAwayStreams' => (new \App\Services\StreamLimitSettingsService())->getDefaultMaxAwayStreams((int) ($user->tenant_id ?? 1)),
            'nowPlaying' => $nowPlaying,
            'endpoints' => $endpoints,
            'portalLink' => $this->portalLinks->activeInfo((int) $user->id),
        ]);
    }

    public function broadcastForm(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('media_users.broadcast', [
            'title' => 'Mensaje masivo Telegram',
            'servers' => $this->servers->allByTenant($tenantId),
            'recipientCount' => count($this->mediaUsers->listForBroadcast($tenantId, 'active', null, true)),
        ]);
    }

    public function broadcastSend(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $title = trim((string) $request->input('title', 'Aviso'));
        $body = trim((string) $request->input('body', ''));
        $status = $request->input('status') ?: 'active';
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;

        if ($body === '') {
            Session::getInstance()->flash('error', 'Escribe el mensaje.');
            return $this->redirect('/media-users/broadcast');
        }

        $users = $this->mediaUsers->listForBroadcast($tenantId, $status, $serverId, true);
        $result = $this->management->broadcastTelegram($users, $title, $body);

        Session::getInstance()->flash('success', sprintf(
            'Envío completado: %d enviados, %d fallidos, %d sin Telegram.',
            $result['sent'],
            $result['failed'],
            $result['skipped']
        ));

        return $this->redirect('/media-users/broadcast');
    }

    public function expiringBroadcast(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $title = trim((string) $request->input('title', 'Aviso de vencimiento'));
        $body = trim((string) $request->input('body', ''));
        $uuids = $request->input('uuids', []);
        if (!is_array($uuids)) {
            $uuids = [];
        }

        $redirectDays = max(1, (int) $request->input('days', 15));
        $redirectServer = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $back = '/media-users/expiring?days=' . $redirectDays
            . ($redirectServer ? '&server_id=' . $redirectServer : '');

        if ($body === '') {
            Session::getInstance()->flash('error', 'Escribe el mensaje.');
            return $this->redirect($back);
        }

        if ($uuids === []) {
            Session::getInstance()->flash('error', 'Selecciona al menos un usuario.');
            return $this->redirect($back);
        }

        $users = $this->mediaUsers->findByUuids($tenantId, $uuids);
        if ($users === []) {
            Session::getInstance()->flash('error', 'No se encontraron usuarios seleccionados.');
            return $this->redirect($back);
        }

        $result = $this->management->broadcastTelegram($users, $title, $body);

        Session::getInstance()->flash('success', sprintf(
            'Selección: %d enviados, %d fallidos, %d sin Telegram.',
            $result['sent'],
            $result['failed'],
            $result['skipped']
        ));

        return $this->redirect($back);
    }

    public function expiringBulkRenew(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $days = max(1, min(3650, (int) $request->input('days', 30)));
        $uuids = $request->input('uuids', []);
        if (!is_array($uuids)) {
            $uuids = [];
        }

        $redirectDays = max(1, (int) $request->input('filter_days', 15));
        $redirectServer = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $back = '/media-users/expiring?days=' . $redirectDays
            . ($redirectServer ? '&server_id=' . $redirectServer : '');

        if ($uuids === []) {
            Session::getInstance()->flash('error', 'Selecciona al menos un usuario.');
            return $this->redirect($back);
        }

        $users = $this->mediaUsers->findByUuids($tenantId, $uuids);
        $ok = 0;
        $fail = 0;
        foreach ($users as $user) {
            $result = $this->management->addDays($user, $days);
            if (!empty($result['success'])) {
                $ok++;
            } else {
                $fail++;
            }
        }
        $missing = max(0, count($uuids) - count($users));

        Session::getInstance()->flash('success', sprintf(
            'Renovación +%d días: %d ok, %d fallidos%s.',
            $days,
            $ok,
            $fail,
            $missing > 0 ? ", {$missing} no encontrados" : ''
        ));

        return $this->redirect($back);
    }

    public function expiringBulkSuspend(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $uuids = $request->input('uuids', []);
        if (!is_array($uuids)) {
            $uuids = [];
        }

        $redirectDays = max(1, (int) $request->input('filter_days', 15));
        $redirectServer = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $back = '/media-users/expiring?days=' . $redirectDays
            . ($redirectServer ? '&server_id=' . $redirectServer : '');

        if ($uuids === []) {
            Session::getInstance()->flash('error', 'Selecciona al menos un usuario.');
            return $this->redirect($back);
        }

        $users = $this->mediaUsers->findByUuids($tenantId, $uuids);
        $ok = 0;
        $fail = 0;
        foreach ($users as $user) {
            if ($user->status === 'suspended') {
                $ok++;
                continue;
            }
            $result = $this->management->suspend($user);
            if (!empty($result['success'])) {
                $ok++;
            } else {
                $fail++;
            }
        }
        $missing = max(0, count($uuids) - count($users));

        Session::getInstance()->flash('success', sprintf(
            'Suspensión: %d ok, %d fallidos%s.',
            $ok,
            $fail,
            $missing > 0 ? ", {$missing} no encontrados" : ''
        ));

        return $this->redirect($back);
    }

    public function reengageInvite(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        return $this->json($this->reengage->invite($user, true));
    }

    public function reengageTrial(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        return $this->json($this->reengage->openTrial($user));
    }

    public function expiringBulkReengage(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $uuids = $request->input('uuids', []);
        if (!is_array($uuids)) {
            $uuids = [];
        }
        $redirectDays = max(1, (int) $request->input('filter_days', 30));
        $redirectServer = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $back = '/media-users/expiring?days=' . $redirectDays
            . ($redirectServer ? '&server_id=' . $redirectServer : '');

        if ($uuids === []) {
            Session::getInstance()->flash('error', 'Selecciona al menos un usuario.');
            return $this->redirect($back);
        }

        $users = $this->mediaUsers->findByUuids($tenantId, $uuids);
        $ok = 0;
        $fail = 0;
        foreach ($users as $user) {
            $result = $this->reengage->invite($user, true);
            if (!empty($result['sent'])) {
                $ok++;
            } else {
                $fail++;
            }
        }

        Session::getInstance()->flash('success', sprintf(
            'Reenganche: %d enviados, %d sin canal o error.',
            $ok,
            $fail + max(0, count($uuids) - count($users))
        ));

        return $this->redirect($back);
    }

    public function cleanupIptv(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $result = $this->iptvCleanup->findCandidates($tenantId, $serverId);

        return $this->view('media_users.cleanup_iptv', [
            'title' => 'Limpieza IPTV',
            'candidates' => $result['candidates'],
            'heuristic' => $result['heuristic'],
            'servers' => $this->servers->allByTenant($tenantId),
            'currentServerId' => $serverId,
        ]);
    }

    public function cleanupIptvApply(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $action = trim((string) $request->input('action', IptvCleanupService::ACTION_DETACH));
        $confirm = trim((string) $request->input('confirm', ''));
        $uuids = $request->input('uuids', []);
        if (!is_array($uuids)) {
            $uuids = [];
        }
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $back = '/media-users/cleanup-iptv' . ($serverId ? '?server_id=' . $serverId : '');

        $stats = $this->iptvCleanup->apply($tenantId, $uuids, $action, $confirm);

        if ($stats['errors'] !== [] && $stats['processed'] === 0) {
            Session::getInstance()->flash('error', implode(' ', $stats['errors']));
            return $this->redirect($back);
        }

        $msg = sprintf(
            'Limpieza IPTV: %d procesados (%d soft-delete, %d detach), %d omitidos.',
            $stats['processed'],
            $stats['soft_deleted'],
            $stats['detached'],
            $stats['skipped']
        );
        if ($stats['errors'] !== []) {
            $msg .= ' Avisos: ' . implode(' ', array_slice($stats['errors'], 0, 3));
        }
        Session::getInstance()->flash('success', $msg);

        return $this->redirect($back);
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $this->mediaUsers->ensureTelegramChatIdColumn();
        try {
            $this->mediaUsers->scrubLiteralNullTelegram($tenantId);
            $this->mediaUsers->backfillTelegramChatIds($tenantId);
            $this->mediaUsers->backfillEmailsFromCustomers($tenantId);
        } catch (\Throwable) {
            // No bloquear el listado si el backfill falla (JSON/metadata ausente).
        }
        $this->dedupe->mergeDuplicatesForTenant($tenantId);
        $status = $request->input('status');
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $onServerFilter = $request->input('on_server');
        $onServer = null;
        if ($onServerFilter === '1' || $onServerFilter === '0') {
            $onServer = $onServerFilter === '1';
        }
        $sort = MediaUserRepository::normalizeSort($request->input('sort'));
        $dir = MediaUserRepository::normalizeDir($request->input('dir'));
        $emptyFilters = $this->parseEmptyFilters($request);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $totalCount = $this->mediaUsers->countFiltered($tenantId, $status, $serverId, $onServer, $emptyFilters);
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $users = $this->mediaUsers->paginate(
            $tenantId,
            $page,
            $perPage,
            $status,
            $serverId,
            $onServer,
            $sort,
            $dir,
            $emptyFilters
        );
        $defaultMaxStreams = (new StreamLimitSettingsService())->getDefaultMaxStreams($tenantId);

        return $this->view('media_users.index', [
            'title' => 'Usuarios Media',
            'users' => $users,
            'servers' => $this->servers->allByTenant($tenantId),
            'currentStatus' => $status,
            'currentServerId' => $serverId,
            'currentOnServer' => $onServer,
            'currentSort' => $sort,
            'currentDir' => $dir,
            'emptyFilters' => $emptyFilters,
            'defaultMaxStreams' => $defaultMaxStreams,
            'totalCount' => $totalCount,
            'showingCount' => count($users),
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ]);
    }

    public function search(Request $request): Response
    {
        try {
            $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
            $q = trim((string) $request->input('q', ''));
            $status = $request->input('status') ?: null;
            $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
            $onServerFilter = $request->input('on_server');
            $onServer = null;
            if ($onServerFilter === '1' || $onServerFilter === '0') {
                $onServer = $onServerFilter === '1';
            }
            $sort = MediaUserRepository::normalizeSort($request->input('sort'));
            $dir = MediaUserRepository::normalizeDir($request->input('dir') ?: 'asc');
            $emptyFilters = $this->parseEmptyFilters($request);
            $streamLimits = new StreamLimitSettingsService();
            $defaultMaxStreams = $streamLimits->getDefaultMaxStreams($tenantId);

            $users = $this->mediaUsers->search(
                $tenantId,
                $q,
                50,
                $status,
                $serverId,
                $onServer,
                $sort,
                $dir,
                $emptyFilters
            );

            return $this->json([
                'query' => $q,
                'count' => count($users),
                'total' => $this->mediaUsers->countFiltered($tenantId, $status, $serverId, $onServer, $emptyFilters),
                'users' => array_map(static function (MediaUser $u) use ($streamLimits, $tenantId, $defaultMaxStreams): array {
                    $tg = normalize_telegram_chat_id($u->telegram_chat_id ?? null);

                    return [
                        'id' => (int) $u->id,
                        'uuid' => (string) $u->uuid,
                        'username' => (string) ($u->username ?? ''),
                        'display_name' => (string) ($u->display_name ?? ''),
                        'email' => (string) ($u->email ?? ''),
                        'server_name' => (string) ($u->server_name ?? ''),
                        'server_uuid' => (string) ($u->server_uuid ?? ''),
                        'server_type' => (string) ($u->server_type ?? ''),
                        'status' => (string) $u->status,
                        'on_server' => isset($u->on_server) ? (int) $u->on_server : null,
                        'membership_synced_at' => $u->membership_synced_at ?? null,
                        'max_streams' => $streamLimits->resolveLimitForUser($tenantId, $u->max_streams ?? null),
                        'max_streams_raw' => $u->max_streams,
                        'default_max_streams' => $defaultMaxStreams,
                        'expires_at' => $u->expires_at ? substr((string) $u->expires_at, 0, 10) : '',
                        'telegram_chat_id' => $tg,
                    ];
                }, $users),
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'users' => [],
                'count' => 0,
            ], 500);
        }
    }

    public function create(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('media_users.create', array_merge(
            [
                'title' => 'Nuevo usuario',
                'defaultMaxStreams' => (new \App\Services\StreamLimitSettingsService())->getDefaultMaxStreams($tenantId),
            ],
            $this->serverFormDefaults($tenantId)
        ));
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'username' => 'required|max:100',
            'email' => 'nullable|email',
        ]);

        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $email = isset($data['email']) && $data['email'] !== '' ? mb_strtolower(trim((string) $data['email'])) : null;

        $duplicate = $this->mediaUsers->findDuplicate($tenantId, $data['username'], $email);
        if ($duplicate !== null) {
            $label = $duplicate->display_name ?? $duplicate->username;
            if ($duplicate->isExpired() || in_array($duplicate->status, ['expired', 'suspended'], true)) {
                $vence = $duplicate->expires_at ? substr((string) $duplicate->expires_at, 0, 10) : 'sin fecha';
                Session::getInstance()->flash('error', sprintf(
                    'Ya existe "%s" con ese email o usuario y está caducado/suspendido (venció: %s). No se ha creado un duplicado: edítalo desde su ficha para renovarlo.',
                    $label,
                    $vence
                ));
            } else {
                Session::getInstance()->flash('error', sprintf(
                    'Ya existe un usuario activo "%s" con ese email o usuario. No se han creado duplicados.',
                    $label
                ));
            }
            return $this->redirect('/media-users/' . $duplicate->uuid);
        }

        $password = $request->input('password') ?: $this->passwords->generate();
        $serverId = $request->input('server_id') ?: null;
        $server = $serverId ? Server::find((int) $serverId) : null;

        $username = (string) $data['username'];
        if ($server !== null && $server->type === 'jellyfin' && trim($username) === '') {
            $username = $this->provisioning->generateJellyfinUsername($email ?? 'user', $tenantId);
        }

        $user = new MediaUser([
            'tenant_id' => $tenantId,
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $serverId,
            'username' => $username,
            'email' => $email,
            'password' => $this->passwords->hash($password),
            'display_name' => $request->input('display_name') ?: $username,
            'status' => $request->input('status') ?? 'pending',
            'max_streams' => ($request->input('max_streams') === null || $request->input('max_streams') === '')
                ? null
                : max(1, min(50, (int) $request->input('max_streams'))),
            'max_devices' => (int) ($request->input('max_devices') ?? 5),
            'expires_at' => $request->input('expires_at') ?: null,
            'telegram_chat_id' => normalize_telegram_chat_id($request->input('telegram_chat_id', '')) ?: null,
            'notes' => $request->input('notes'),
        ]);

        $user->save();
        $this->audit->log('media_user.created', 'media_user', (int) $user->id);

        $serverName = $server !== null ? (string) $server->name : '';
        $expiresAt = $user->expires_at ? (string) $user->expires_at : null;
        $days = null;
        if ($expiresAt !== null) {
            $daysTs = strtotime(substr($expiresAt, 0, 10) . ' 12:00:00');
            if ($daysTs !== false) {
                $days = max(0, (int) ceil(($daysTs - time()) / 86400));
            }
        }
        $this->notifications->notifyMediaUserCreated(
            (string) ($user->email ?: $user->username ?: 'N/A'),
            $serverName,
            $days > 0 ? $days : null,
            $expiresAt,
            $tenantId,
            (string) $user->username
        );

        $flash = 'Usuario creado. Contraseña: ' . $password;
        $session = Session::getInstance();
        if ($server !== null) {
            $result = $this->provisioning->provision($user, $server, $password);
            $flash .= ' | ' . $result['message'];
            if ($server->type === 'jellyfin' && !empty($result['password'])) {
                $session->flash('jellyfin_credentials', [
                    'username' => (string) ($result['username'] ?? $user->username),
                    'password' => (string) $result['password'],
                    'text' => $this->provisioning->credentialsShareText($user, $server, (string) $result['password']),
                ]);
                $session->flash('success', $flash);
                return $this->redirect('/media-users/' . $user->uuid);
            }
        }

        $session->flash('success', $flash);
        return $this->redirect('/media-users');
    }

    public function regenerateJellyfinPassword(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        $server = $user->server_id ? Server::find((int) $user->server_id) : null;
        if ($server === null || $server->type !== 'jellyfin') {
            return $this->json(['success' => false, 'message' => 'Este usuario no está en un servidor Jellyfin.'], 422);
        }

        $result = $this->provisioning->regenerateJellyfinPassword($user, $server);
        if (!empty($result['success']) && !empty($result['password'])) {
            $result['credentials_text'] = $this->provisioning->credentialsShareText(
                $user,
                $server,
                (string) $result['password']
            );
            $this->audit->log('media_user.jellyfin_password_regenerated', 'media_user', (int) $user->id);
        }

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function sendJellyfinCredentials(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        $server = $user->server_id ? Server::find((int) $user->server_id) : null;
        if ($server === null || $server->type !== 'jellyfin') {
            return $this->json(['success' => false, 'message' => 'Este usuario no está en un servidor Jellyfin.'], 422);
        }

        $password = $this->provisioning->revealJellyfinPassword($user);
        if ($password === null || $password === '') {
            return $this->json(['success' => false, 'message' => 'No hay contraseña Jellyfin guardada. Regenera una primero.'], 422);
        }

        $text = $this->provisioning->credentialsShareText($user, $server, $password);
        $result = $this->management->sendTelegramMessage($user, 'Acceso Jellyfin', $text);

        return $this->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? 'No enviado'),
            'credentials_text' => $text,
            'username' => (string) $user->username,
            'password' => $password,
        ], !empty($result['success']) ? 200 : 422);
    }

    public function suspend(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $result = $this->management->suspend($user);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function activate(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $result = $this->management->activate($user);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function updateExpires(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $expiresAt = trim((string) $request->input('expires_at', ''));

        return $this->json($this->management->updateExpires($user, $expiresAt !== '' ? $expiresAt : null));
    }

    public function addDays(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $days = (int) $request->input('days', 0);
        $result = $this->management->addDays($user, $days);

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    public function updateNotes(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        return $this->json($this->management->updateNotes($user, trim((string) $request->input('notes', ''))));
    }

    public function updateProfile(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $result = $this->management->updateProfile($user, [
            'username' => $request->input('username', $user->username),
            'display_name' => $request->input('display_name', ''),
            'email' => $request->input('email', ''),
            'max_streams' => $request->input('max_streams', $user->max_streams),
            'max_home_streams' => $request->input('max_home_streams', $user->max_home_streams ?? null),
            'max_away_streams' => $request->input('max_away_streams', $user->max_away_streams ?? null),
            'max_devices' => $request->input('max_devices', $user->max_devices),
        ]);

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    public function setEndpointKind(Request $request, string $uuid, string $id): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        $ok = (new \App\Services\MediaUserEndpointService())->setKind(
            (int) ($user->tenant_id ?? 1),
            (int) $user->id,
            (int) $id,
            (string) $request->input('kind', 'unknown')
        );

        if (!$ok) {
            return $this->json(['success' => false, 'message' => 'No se pudo guardar.'], 422);
        }

        return $this->json(['success' => true, 'message' => 'Marcado.']);
    }

    public function sendMessage(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $title = trim((string) $request->input('title', 'Aviso'));
        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            return $this->json(['success' => false, 'message' => 'Mensaje vacío.'], 422);
        }

        $body = $this->management->personalizeMessage($body, $user);
        $result = $this->management->sendTelegramMessage($user, $title, $body);

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    public function stripeCheckout(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $amount = (float) $request->input('amount', 0);
        $days = (int) $request->input('days', 30);
        $currency = strtoupper(trim((string) $request->input('currency', 'EUR')));

        $result = $this->billing->createRenewalCheckout($user, $amount, $currency !== '' ? $currency : 'EUR', $days);

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    public function createPortalLink(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $purpose = (string) $request->input('purpose', 'home');
        $days = (int) $request->input('days', PortalLoginLinkService::DEFAULT_TTL_DAYS);
        $result = $this->portalLinks->create($user, $purpose, $days);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function revokePortalLink(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $n = $this->portalLinks->revokeActive((int) $user->id);
        AuditService::log('media_user.portal_link_revoked', 'media_user', (int) $user->id, null, [
            'revoked' => $n,
        ]);

        return $this->json([
            'success' => true,
            'message' => $n > 0 ? 'Enlace cancelado. Ya no se puede entrar con él.' : 'No había ningún enlace activo.',
        ]);
    }

    public function sendPortalLink(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $url = trim((string) $request->input('url', ''));
        $code = $this->extractPortalLinkCode($url);
        if ($code === '' || !$this->portalLinkBelongsToUser($user, $code)) {
            return $this->json([
                'success' => false,
                'message' => 'Genera el enlace primero y envíalo sin modificar la URL.',
            ], 422);
        }

        $expires = $this->portalLinks->activeInfo((int) $user->id)['expires_at'];
        $expiresLabel = $expires ? substr($expires, 0, 10) : '';
        $name = trim((string) ($user->display_name ?: $user->username ?: 'hola'));
        $body = "Hola {$name},\n\nEntra a tu cuenta sin contraseña"
            . ($expiresLabel !== '' ? " (válido hasta el {$expiresLabel})" : '')
            . ":\n{$url}\n\nAhí puedes ver tu ficha y contratar más tiempo.";

        $sent = $this->management->sendClientNotice($user, 'Tu acceso al portal', $body, 'portal_link');

        return $this->json($sent, !empty($sent['success']) ? 200 : 422);
    }

    private function extractPortalLinkCode(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (!preg_match('#/u/([A-Za-z0-9]{16,48})/?$#', $path, $m)) {
            return '';
        }

        return $m[1];
    }

    private function portalLinkBelongsToUser(MediaUser $user, string $code): bool
    {
        if (!PortalLoginLinkService::isValidCode($code)) {
            return false;
        }

        try {
            $row = \Core\Database::getInstance()->fetchOne(
                'SELECT media_user_id, revoked_at, expires_at FROM portal_login_links WHERE token_hash = ? LIMIT 1',
                [PortalLoginLinkService::hashCode($code)]
            );
        } catch (\Throwable) {
            return false;
        }

        if ($row === null || (int) ($row['media_user_id'] ?? 0) !== (int) $user->id) {
            return false;
        }
        if (!empty($row['revoked_at'])) {
            return false;
        }
        $expiresTs = strtotime((string) ($row['expires_at'] ?? ''));

        return $expiresTs !== false && $expiresTs >= time();
    }

    public function removeFromServer(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $result = $this->management->removeFromServer($user);

        return $this->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Fuerza reconsulta de la lista de usuarios del servidor de este media user
     * para saber si sigue en la biblioteca (on_server).
     */
    public function syncMembership(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $result = $this->serverSync->syncMediaUserMembership($user);
        $this->audit->log('media_user.membership_synced', 'media_user', (int) $user->id, null, [
            'on_server' => $result['on_server'] ?? null,
        ]);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    /**
     * Forzar sincronización de membresía en todos los servidores (o uno filtrado).
     */
    public function syncMembershipAll(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $redirect = $this->mediaUsersListRedirect($request);

        if ($serverId) {
            $server = Server::find($serverId);
            if ($server === null || (int) $server->tenant_id !== $tenantId) {
                Session::getInstance()->flash('error', 'Servidor no encontrado.');
                return $this->redirect($redirect);
            }
            $ok = $this->serverSync->sync($server);
            $stats = $this->serverSync->lastUserSyncStats();
            $recovered = $this->recoverPanelFieldsAfterSync($tenantId);
            $msg = $ok
                ? sprintf(
                    'Forzar sync (%s): %d nuevos, %d actualizados, %d ausentes, %d restaurados.%s Estado de biblioteca reaplicado (En biblioteca / No está).',
                    $server->name,
                    (int) ($stats['imported'] ?? 0),
                    (int) ($stats['updated'] ?? 0),
                    (int) ($stats['missing'] ?? 0),
                    (int) ($stats['restored'] ?? 0),
                    $recovered
                )
                : 'Sync fallido: ' . ($server->last_error ?? 'sin conexión');
            Session::getInstance()->flash($ok ? 'success' : 'error', $msg);
            return $this->redirect($redirect);
        }

        $synced = $this->serverSync->syncAll($tenantId);
        $total = count($this->servers->allByTenant($tenantId));
        $stats = $this->serverSync->lastUserSyncStats();
        $recovered = $this->recoverPanelFieldsAfterSync($tenantId);
        Session::getInstance()->flash('success', sprintf(
            'Forzar sincronización: %d/%d servidores. %d nuevos, %d actualizados, %d ausentes, %d restaurados.%s Estado de biblioteca reaplicado.',
            $synced,
            $total,
            (int) ($stats['imported'] ?? 0),
            (int) ($stats['updated'] ?? 0),
            (int) ($stats['missing'] ?? 0),
            (int) ($stats['restored'] ?? 0),
            $recovered
        ));

        return $this->redirect($redirect);
    }

    /**
     * Conserva filtros del listado tras sync (Sin fecha / Sin Telegram / Fuera del servidor…).
     */
    private function mediaUsersListRedirect(Request $request): string
    {
        $params = [];
        $status = trim((string) ($request->input('status') ?? ''));
        if ($status !== '') {
            $params['status'] = $status;
        }
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : 0;
        if ($serverId > 0) {
            $params['server_id'] = $serverId;
        }
        $onServer = $request->input('on_server');
        if ($onServer === '0' || $onServer === '1') {
            $params['on_server'] = $onServer;
        }
        $empty = trim((string) ($request->input('filter_empty') ?? ''));
        if ($empty !== '') {
            $params['filter_empty'] = $empty;
        }
        $sort = trim((string) ($request->input('sort') ?? ''));
        if ($sort !== '') {
            $params['sort'] = $sort;
            $dir = strtolower(trim((string) ($request->input('dir') ?? 'desc')));
            $params['dir'] = $dir === 'asc' ? 'asc' : 'desc';
        }

        return $params === [] ? '/media-users' : '/media-users?' . http_build_query($params);
    }

    /** Restaura email/telegram desde customers si un sync previo los dejó vacíos. */
    private function recoverPanelFieldsAfterSync(int $tenantId): string
    {
        $emails = 0;
        $telegrams = 0;
        try {
            $this->mediaUsers->scrubLiteralNullTelegram($tenantId);
            $emails = $this->mediaUsers->backfillEmailsFromCustomers($tenantId);
            $telegrams = $this->mediaUsers->backfillTelegramChatIds($tenantId);
        } catch (\Throwable) {
            return '';
        }

        if ($emails <= 0 && $telegrams <= 0) {
            return '';
        }

        return sprintf(' Recuperados: %d emails, %d Telegram desde clientes.', $emails, $telegrams);
    }

    /**
     * @return list<string>
     */
    private function parseEmptyFilters(Request $request): array
    {
        $raw = $request->input('filter_empty');
        if (is_array($raw)) {
            return MediaUserRepository::normalizeEmptyFilters($raw);
        }

        return MediaUserRepository::normalizeEmptyFilters([(string) ($raw ?? '')]);
    }

    public function updateTelegram(Request $request, string $uuid): Response
    {
        try {
            $user = $this->mediaUsers->findByUuid($uuid);
            if ($user === null) {
                return $this->json(['error' => 'Usuario no encontrado'], 404);
            }

            $chatId = normalize_telegram_chat_id($request->input('telegram_chat_id', ''));

            return $this->json($this->management->updateTelegram($user, $chatId !== '' ? $chatId : null));
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateWhatsapp(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $phone = trim((string) $request->input('whatsapp_phone', ''));

        return $this->json($this->management->updateWhatsapp($user, $phone !== '' ? $phone : null));
    }

    public function messages(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->redirect('/media-users');
        }

        $status = trim((string) $request->input('status', ''));
        $channel = trim((string) $request->input('channel', ''));
        if (!in_array($status, ['sent', 'failed'], true)) {
            $status = '';
        }
        if ($channel === '') {
            $channel = '';
        }

        return $this->view('media_users.messages', [
            'title' => 'Mensajes: ' . ($user->display_name ?? $user->username),
            // Must not be named "user": AuthMiddleware shares auth $user for the layout/navbar.
            'mediaUser' => $user,
            'messages' => $this->messages->listForUser(
                (int) $user->id,
                100,
                $status !== '' ? $status : null,
                $channel !== '' ? $channel : null
            ),
            'filterStatus' => $status,
            'filterChannel' => $channel,
        ]);
    }

    public function retryMessage(Request $request, string $uuid, string $id): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        $messageId = (int) $id;
        $row = $this->messages->findForUser((int) $user->id, $messageId);
        if ($row === null) {
            return $this->json(['success' => false, 'message' => 'Aviso no encontrado'], 404);
        }

        $channel = (string) ($row['channel'] ?? 'telegram');
        if ($channel !== 'telegram') {
            return $this->json([
                'success' => false,
                'message' => 'Solo se puede reintentar avisos de Telegram desde el panel.',
            ], 422);
        }

        $title = trim((string) ($row['title'] ?? 'Aviso'));
        $body = trim((string) ($row['body'] ?? ''));
        if ($body === '') {
            return $this->json(['success' => false, 'message' => 'El aviso no tiene cuerpo.'], 422);
        }

        $result = $this->management->sendTelegramMessage($user, $title !== '' ? $title : 'Aviso', $body);

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        $wantsJson = str_contains(strtolower((string) $request->header('Accept', '')), 'application/json')
            || str_contains(strtolower((string) $request->header('Content-Type', '')), 'application/json');

        if ($user === null) {
            if ($wantsJson) {
                return $this->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
            }
            return $this->redirect('/media-users');
        }

        $this->audit->log('media_user.deleted', 'media_user', (int) $user->id);
        $user->deleted_at = now()->format('Y-m-d H:i:s');
        $user->save();

        if ($wantsJson) {
            return $this->json([
                'success' => true,
                'message' => 'Usuario eliminado del panel (no se tocó Plex/Jellyfin).',
            ]);
        }

        Session::getInstance()->flash('success', 'Usuario eliminado del panel.');
        return $this->redirect('/media-users');
    }

    /**
     * Soft-delete masivo de usuarios marcados fuera del servidor (on_server=0).
     */
    public function softDeleteOffServer(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $rawUuids = $request->input('uuids');
        $uuids = null;
        if (is_array($rawUuids)) {
            $uuids = $rawUuids;
        } elseif (is_string($rawUuids) && trim($rawUuids) !== '') {
            $uuids = preg_split('/[\s,]+/', trim($rawUuids)) ?: [];
        }

        $deleted = $this->mediaUsers->softDeleteOffServer($tenantId, $uuids, $serverId);
        $this->audit->log('media_user.bulk_soft_deleted_off_server', 'media_user', null, null, [
            'deleted' => $deleted,
            'server_id' => $serverId,
        ]);

        Session::getInstance()->flash(
            'success',
            sprintf('Eliminados del panel: %d usuarios (marcados fuera del servidor). No se tocó Plex/Jellyfin.', $deleted)
        );

        return $this->redirect($this->mediaUsersListRedirect($request));
    }

    /**
     * Revisión one-by-one: usuarios sin fecha/Telegram (o fuera del servidor).
     */
    public function review(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $this->mediaUsers->ensureTelegramChatIdColumn();

        $emptyFilters = $this->parseEmptyFilters($request);
        if ($emptyFilters === [] && $request->input('filter_empty') === null && $request->input('on_server') === null) {
            // Por defecto: sin fecha o sin telegram (OR vía dos filtros AND — el listado usa AND;
            // aquí usamos expires+telegram juntos; el usuario puede afinar).
            $emptyFilters = ['expires', 'telegram'];
        }

        $onServerFilter = $request->input('on_server');
        $onServer = null;
        if ($onServerFilter === '1' || $onServerFilter === '0') {
            $onServer = $onServerFilter === '1';
        }
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $afterId = $request->input('after_id') ? (int) $request->input('after_id') : null;
        $focusUuid = trim((string) ($request->input('uuid') ?? ''));

        $remaining = $this->mediaUsers->countFiltered($tenantId, null, $serverId, $onServer, $emptyFilters);
        $user = null;
        if ($focusUuid !== '') {
            $focused = $this->mediaUsers->findByUuid($focusUuid);
            if ($focused !== null && (int) ($focused->tenant_id ?? 0) === $tenantId && empty($focused->deleted_at)) {
                $user = $focused;
            }
        }
        if ($user === null) {
            $user = $this->mediaUsers->findNextForReview($tenantId, $emptyFilters, $onServer, $serverId, $afterId);
        }

        return $this->view('media_users.review', [
            'title' => 'Revisar usuarios sin datos',
            'mediaUser' => $user,
            'remaining' => $remaining,
            'servers' => $this->servers->allByTenant($tenantId),
            'emptyFilters' => $emptyFilters,
            'currentOnServer' => $onServer,
            'currentServerId' => $serverId,
            'afterId' => $afterId,
        ]);
    }

    /** Acción de la cola de revisión: siguiente / soft-delete / sync. */
    public function reviewAction(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $action = trim((string) ($request->input('action') ?? 'next'));
        $uuid = trim((string) ($request->input('uuid') ?? ''));
        $query = $this->reviewQueryString($request);

        $user = $uuid !== '' ? $this->mediaUsers->findByUuid($uuid) : null;
        if ($user !== null && (int) ($user->tenant_id ?? 0) !== $tenantId) {
            $user = null;
        }

        if ($action === 'sync' && $user !== null) {
            $result = $this->serverSync->syncMediaUserMembership($user);
            Session::getInstance()->flash(
                !empty($result['success']) ? 'success' : 'error',
                (string) ($result['message'] ?? 'Sync completado')
            );
            $params = [];
            parse_str($query, $params);
            $params['uuid'] = $user->uuid;

            return $this->redirect('/media-users/revisar?' . http_build_query($params));
        }

        if ($action === 'save_contact' && $user !== null) {
            $emailRaw = trim((string) ($request->input('email') ?? ''));
            $tgRaw = trim((string) ($request->input('telegram_chat_id') ?? ''));

            $emailResult = $this->management->updateEmail($user, $emailRaw !== '' ? $emailRaw : null);
            if (empty($emailResult['success'])) {
                Session::getInstance()->flash('error', (string) ($emailResult['message'] ?? 'Email no válido'));
                $params = [];
                parse_str($query, $params);
                $params['uuid'] = $user->uuid;

                return $this->redirect('/media-users/revisar?' . http_build_query($params));
            }

            $this->management->updateTelegram($user, $tgRaw !== '' ? $tgRaw : null);
            Session::getInstance()->flash('success', 'Email y Telegram guardados.');
            $params = [];
            parse_str($query, $params);
            $params['uuid'] = $user->uuid;

            return $this->redirect('/media-users/revisar?' . http_build_query($params));
        }

        if ($action === 'soft_delete' && $user !== null) {
            $this->audit->log('media_user.deleted', 'media_user', (int) $user->id, null, ['via' => 'review']);
            $user->deleted_at = now()->format('Y-m-d H:i:s');
            $user->save();
            Session::getInstance()->flash('success', 'Eliminado del panel: ' . ($user->display_name ?: $user->username));
            $after = (int) $user->id;
            $params = [];
            parse_str($query, $params);
            $params['after_id'] = $after;
            return $this->redirect('/media-users/revisar?' . http_build_query($params));
        }

        // next / keep: avanza al siguiente tras este id
        if ($user !== null) {
            $params = [];
            parse_str($query, $params);
            $params['after_id'] = (int) $user->id;
            return $this->redirect('/media-users/revisar?' . http_build_query($params));
        }

        return $this->redirect('/media-users/revisar' . ($query !== '' ? '?' . $query : ''));
    }

    private function reviewQueryString(Request $request): string
    {
        $params = [];
        $empty = trim((string) ($request->input('filter_empty') ?? ''));
        if ($empty !== '') {
            $params['filter_empty'] = $empty;
        }
        $onServer = $request->input('on_server');
        if ($onServer === '0' || $onServer === '1') {
            $params['on_server'] = $onServer;
        }
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : 0;
        if ($serverId > 0) {
            $params['server_id'] = $serverId;
        }

        return http_build_query($params);
    }

    public function bulkCreate(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('media_users.bulk', array_merge(
            [
                'title' => 'Añadir usuarios por email',
                'periods' => SubscriptionPeriod::options(),
            ],
            $this->serverFormDefaults($tenantId)
        ));
    }

    /**
     * Servidores + IDs predeterminados para formularios de alta (uno Plex + uno Jellyfin).
     *
     * @return array{servers: array<int, Server>, preferredServerId: ?int, defaultPlexServerId: ?int, defaultJellyfinServerId: ?int}
     */
    private function serverFormDefaults(int $tenantId): array
    {
        $plex = $this->servers->findDefaultByTenant($tenantId, 'plex');
        $jelly = $this->servers->findDefaultByTenant($tenantId, 'jellyfin');
        $preferred = $this->servers->preferredDefaultForForms($tenantId);

        return [
            'servers' => $this->servers->allByTenant($tenantId),
            'preferredServerId' => $preferred?->id ? (int) $preferred->id : null,
            'defaultPlexServerId' => $plex?->id ? (int) $plex->id : null,
            'defaultJellyfinServerId' => $jelly?->id ? (int) $jelly->id : null,
        ];
    }

    public function bulkStore(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $serverId = (int) $request->input('server_id');
        $period = (string) $request->input('period', '1m');
        $emails = trim((string) $request->input('emails', ''));

        if ($serverId <= 0) {
            Session::getInstance()->flash('error', 'Selecciona un servidor.');
            return $this->redirect('/media-users/bulk');
        }

        if ($emails === '') {
            Session::getInstance()->flash('error', 'Introduce al menos un email.');
            return $this->redirect('/media-users/bulk');
        }

        $result = $this->bulk->addEmailsToServer($tenantId, $serverId, $period, $emails);

        $message = sprintf(
            'Proceso completado: %d creados, %d actualizados, %d omitidos.',
            $result['created'],
            $result['updated'],
            $result['skipped']
        );

        if ($result['errors'] !== []) {
            $message .= ' Errores: ' . implode('; ', array_slice($result['errors'], 0, 5));
        }

        Session::getInstance()->flash('success', $message);
        return $this->redirect('/media-users?server_id=' . $serverId);
    }
}
