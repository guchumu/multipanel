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
use App\Services\PasswordService;
use App\Services\ServerSyncService;
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
            'servicioLabels' => config('import_servicio.labels', [1 => 'Servitron', 5 => 'NucBox']),
            'servicioMap' => config('import_servicio.map', []),
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
        $days = max(1, (int) $request->input('days', 15));
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;

        $users = $this->mediaUsers->findExpiringSoon($tenantId, $days, $serverId);

        return $this->view('media_users.expiring', [
            'title' => 'Próximos vencimientos',
            'users' => $users,
            'servers' => $this->servers->allByTenant($tenantId),
            'currentDays' => $days,
            'currentServerId' => $serverId,
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
            'nowPlaying' => $nowPlaying,
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
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $totalCount = $this->mediaUsers->countFiltered($tenantId, $status, $serverId, $onServer);
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $users = $this->mediaUsers->paginate($tenantId, $page, $perPage, $status, $serverId, $onServer);

        return $this->view('media_users.index', [
            'title' => 'Usuarios Media',
            'users' => $users,
            'servers' => $this->servers->allByTenant($tenantId),
            'currentStatus' => $status,
            'currentServerId' => $serverId,
            'currentOnServer' => $onServer,
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

            $users = $this->mediaUsers->search($tenantId, $q, 50, $status, $serverId, $onServer);

            return $this->json([
                'query' => $q,
                'count' => count($users),
                'total' => $this->mediaUsers->countFiltered($tenantId, $status, $serverId, $onServer),
                'users' => array_map(static fn (MediaUser $u): array => [
                    'id' => (int) $u->id,
                    'uuid' => (string) $u->uuid,
                    'username' => (string) ($u->username ?? ''),
                    'display_name' => (string) ($u->display_name ?? ''),
                    'email' => (string) ($u->email ?? ''),
                    'server_name' => (string) ($u->server_name ?? ''),
                    'status' => (string) $u->status,
                    'on_server' => isset($u->on_server) ? (int) $u->on_server : null,
                    'membership_synced_at' => $u->membership_synced_at ?? null,
                    'max_streams' => (int) $u->max_streams,
                    'expires_at' => $u->expires_at ? substr((string) $u->expires_at, 0, 10) : '',
                    'telegram_chat_id' => (string) ($u->telegram_chat_id ?? ''),
                ], $users),
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
            'telegram_chat_id' => trim((string) $request->input('telegram_chat_id', '')) ?: null,
            'notes' => $request->input('notes'),
        ]);

        $user->save();
        $this->audit->log('media_user.created', 'media_user', (int) $user->id);
        $this->notifications->notifyUserCreated($user->username, $user->email ?? 'N/A');

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
            'max_devices' => $request->input('max_devices', $user->max_devices),
        ]);

        return $this->json($result, $result['success'] ? 200 : 422);
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

        if ($serverId) {
            $server = Server::find($serverId);
            if ($server === null || (int) $server->tenant_id !== $tenantId) {
                Session::getInstance()->flash('error', 'Servidor no encontrado.');
                return $this->redirect('/media-users');
            }
            $ok = $this->serverSync->sync($server);
            $stats = $this->serverSync->lastUserSyncStats();
            $recovered = $this->recoverPanelFieldsAfterSync($tenantId);
            $msg = $ok
                ? sprintf(
                    'Forzar sync (%s): %d nuevos, %d actualizados, %d ausentes, %d restaurados.%s',
                    $server->name,
                    (int) ($stats['imported'] ?? 0),
                    (int) ($stats['updated'] ?? 0),
                    (int) ($stats['missing'] ?? 0),
                    (int) ($stats['restored'] ?? 0),
                    $recovered
                )
                : 'Sync fallido: ' . ($server->last_error ?? 'sin conexión');
            Session::getInstance()->flash($ok ? 'success' : 'error', $msg);
            $redirect = '/media-users?server_id=' . $serverId;
            return $this->redirect($redirect);
        }

        $synced = $this->serverSync->syncAll($tenantId);
        $total = count($this->servers->allByTenant($tenantId));
        $stats = $this->serverSync->lastUserSyncStats();
        $recovered = $this->recoverPanelFieldsAfterSync($tenantId);
        Session::getInstance()->flash('success', sprintf(
            'Forzar sincronización: %d/%d servidores. %d nuevos, %d actualizados, %d ausentes, %d restaurados.%s',
            $synced,
            $total,
            (int) ($stats['imported'] ?? 0),
            (int) ($stats['updated'] ?? 0),
            (int) ($stats['missing'] ?? 0),
            (int) ($stats['restored'] ?? 0),
            $recovered
        ));

        return $this->redirect('/media-users');
    }

    /** Restaura email/telegram desde customers si un sync previo los dejó vacíos. */
    private function recoverPanelFieldsAfterSync(int $tenantId): string
    {
        $emails = 0;
        $telegrams = 0;
        try {
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

    public function updateTelegram(Request $request, string $uuid): Response
    {
        try {
            $user = $this->mediaUsers->findByUuid($uuid);
            if ($user === null) {
                return $this->json(['error' => 'Usuario no encontrado'], 404);
            }

            $chatId = trim((string) $request->input('telegram_chat_id', ''));

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

        return $this->view('media_users.messages', [
            'title' => 'Mensajes: ' . ($user->display_name ?? $user->username),
            // Must not be named "user": AuthMiddleware shares auth $user for the layout/navbar.
            'mediaUser' => $user,
            'messages' => $this->messages->listForUser((int) $user->id),
        ]);
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->redirect('/media-users');
        }

        $this->audit->log('media_user.deleted', 'media_user', (int) $user->id);
        $user->deleted_at = now()->format('Y-m-d H:i:s');
        $user->save();

        Session::getInstance()->flash('success', 'Usuario eliminado.');
        return $this->redirect('/media-users');
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
