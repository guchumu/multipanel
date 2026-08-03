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
use App\Services\MediaUserMessageService;
use App\Services\MediaUserManagementService;
use App\Services\MediaUserActivityService;
use App\Services\MediaUserProvisioningService;
use App\Services\PasswordService;
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
    ) {
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

        if ($user->server_id) {
            $server = Server::find((int) $user->server_id);
            if ($server) {
                $user->server_name = $server->name;
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

        return $this->view('media_users.show', [
            'title' => $user->display_name ?? $user->username,
            'user' => $user,
            'timeline' => $this->activity->timeline((int) $user->id),
            'messages' => $this->messages->listForUser((int) $user->id, 20),
            'renewalPresets' => $this->billingSettings->getRenewalPresets((int) ($user->tenant_id ?? 1)),
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

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $status = $request->input('status');
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $users = $this->mediaUsers->paginate($tenantId, $page, $perPage, $status, $serverId);
        $totalCount = $this->mediaUsers->countFiltered($tenantId, $status, $serverId);

        return $this->view('media_users.index', [
            'title' => 'Usuarios Media',
            'users' => $users,
            'servers' => $this->servers->allByTenant($tenantId),
            'currentStatus' => $status,
            'currentServerId' => $serverId,
            'totalCount' => $totalCount,
            'showingCount' => count($users),
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function search(Request $request): Response
    {
        try {
            $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
            $q = trim((string) $request->input('q', ''));
            $status = $request->input('status') ?: null;
            $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;

            $users = $this->mediaUsers->search($tenantId, $q, 50, $status, $serverId);

            return $this->json([
                'query' => $q,
                'count' => count($users),
                'total' => $this->mediaUsers->countFiltered($tenantId, $status, $serverId),
                'users' => array_map(static fn (MediaUser $u): array => [
                    'id' => (int) $u->id,
                    'uuid' => (string) $u->uuid,
                    'username' => (string) ($u->display_name ?? $u->username),
                    'email' => (string) ($u->email ?? ''),
                    'server_name' => (string) ($u->server_name ?? ''),
                    'status' => (string) $u->status,
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

        return $this->view('media_users.create', [
            'title' => 'Nuevo usuario',
            'servers' => $this->servers->allByTenant($tenantId),
        ]);
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

        $user = new MediaUser([
            'tenant_id' => $tenantId,
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $request->input('server_id') ?: null,
            'username' => $data['username'],
            'email' => $email,
            'password' => $this->passwords->hash($password),
            'display_name' => $request->input('display_name'),
            'status' => $request->input('status') ?? 'pending',
            'max_streams' => (int) ($request->input('max_streams') ?? 1),
            'max_devices' => (int) ($request->input('max_devices') ?? 5),
            'expires_at' => $request->input('expires_at') ?: null,
            'telegram_chat_id' => trim((string) $request->input('telegram_chat_id', '')) ?: null,
            'notes' => $request->input('notes'),
        ]);

        $user->save();
        $this->audit->log('media_user.created', 'media_user', (int) $user->id);
        $this->notifications->notifyUserCreated($user->username, $user->email ?? 'N/A');

        $flash = 'Usuario creado. Contraseña: ' . $password;
        if ($user->server_id) {
            $server = Server::find((int) $user->server_id);
            if ($server !== null) {
                $result = $this->provisioning->provision($user, $server, $password);
                $flash .= ' | ' . $result['message'];
            }
        }

        Session::getInstance()->flash('success', $flash);
        return $this->redirect('/media-users');
    }

    public function suspend(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        return $this->json($this->management->suspend($user));
    }

    public function activate(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        return $this->json($this->management->activate($user));
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
            'user' => $user,
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

        return $this->view('media_users.bulk', [
            'title' => 'Añadir usuarios por email',
            'servers' => $this->servers->allByTenant($tenantId),
            'periods' => SubscriptionPeriod::options(),
        ]);
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
