<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\MediaUser;
use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\MediaUserBulkService;
use App\Services\MediaUserMessageService;
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
    ) {
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
        $password = $request->input('password') ?: $this->passwords->generate();

        $user = new MediaUser([
            'tenant_id' => $tenantId,
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $request->input('server_id') ?: null,
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
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

        Session::getInstance()->flash('success', 'Usuario creado. Contraseña: ' . $password);
        return $this->redirect('/media-users');
    }

    public function suspend(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $user->status = 'suspended';
        $user->save();
        $this->audit->log('media_user.suspended', 'media_user', (int) $user->id);

        return $this->json(['success' => true, 'message' => 'Usuario suspendido.']);
    }

    public function activate(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $user->status = 'active';
        $user->save();
        $this->audit->log('media_user.activated', 'media_user', (int) $user->id);

        return $this->json(['success' => true, 'message' => 'Usuario activado.']);
    }

    public function updateExpires(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if ($user === null) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $expiresAt = trim((string) $request->input('expires_at', ''));
        $user->expires_at = $expiresAt !== '' ? $expiresAt : null;
        $user->save();
        $this->audit->log('media_user.expires_updated', 'media_user', (int) $user->id);

        return $this->json([
            'success' => true,
            'expires_at' => $user->expires_at,
            'message' => 'Fecha de expiración actualizada.',
        ]);
    }

    public function updateTelegram(Request $request, string $uuid): Response
    {
        try {
            $user = $this->mediaUsers->findByUuid($uuid);
            if ($user === null) {
                return $this->json(['error' => 'Usuario no encontrado'], 404);
            }

            $this->mediaUsers->ensureTelegramChatIdColumn();

            $chatId = trim((string) $request->input('telegram_chat_id', ''));
            $user->telegram_chat_id = $chatId !== '' ? $chatId : null;
            $user->save();

            return $this->json([
                'success' => true,
                'telegram_chat_id' => $user->telegram_chat_id,
                'message' => 'Telegram actualizado.',
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
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
