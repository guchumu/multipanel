<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\MediaUser;
use App\Repositories\MediaUserRepository;
use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\PasswordService;
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
        private AuthService $auth = new AuthService(),
        private AuditService $audit = new AuditService(),
        private PasswordService $passwords = new PasswordService(),
        private NotificationService $notifications = new NotificationService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $status = $request->input('status');
        $page = max(1, (int) $request->input('page', 1));

        return $this->view('media_users.index', [
            'title' => 'Usuarios Media',
            'users' => $this->mediaUsers->paginate($tenantId, $page, 20, $status),
            'currentStatus' => $status,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('media_users.create', ['title' => 'Nuevo usuario']);
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
}
