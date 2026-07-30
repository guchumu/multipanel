<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\MediaUser;
use App\Repositories\MediaUserRepository;
use App\Services\PasswordService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Exceptions\NotFoundException;
use Ramsey\Uuid\Uuid;

/**
 * REST API media user endpoints.
 */
class MediaUserApiController extends Controller
{
    public function __construct(
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
        private PasswordService $passwords = new PasswordService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = 1;
        $status = $request->input('status');
        $page = max(1, (int) $request->input('page', 1));

        $users = $this->mediaUsers->paginate($tenantId, $page, 20, $status);

        return $this->json([
            'data' => array_map(fn ($u) => $this->format($u), $users),
            'meta' => [
                'page' => $page,
                'total' => $this->mediaUsers->countTotal($tenantId),
            ],
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if (!$user) {
            throw new NotFoundException('Usuario no encontrado.');
        }

        return $this->json(['data' => $this->format($user)]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'username' => 'required|max:100',
            'email' => 'nullable|email',
        ]);

        $password = $request->input('password') ?: $this->passwords->generate();

        $user = new MediaUser([
            'tenant_id' => 1,
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $request->input('server_id') ?: null,
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'password' => $this->passwords->hash($password),
            'status' => $request->input('status') ?? 'pending',
            'max_streams' => (int) ($request->input('max_streams') ?? 1),
            'max_devices' => (int) ($request->input('max_devices') ?? 5),
            'expires_at' => $request->input('expires_at') ?: null,
        ]);

        $user->save();

        $formatted = $this->format($user);
        $formatted['generated_password'] = $password;

        return $this->json(['data' => $formatted], 201);
    }

    public function update(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if (!$user) {
            throw new NotFoundException('Usuario no encontrado.');
        }

        $allowed = ['username', 'email', 'status', 'max_streams', 'max_devices', 'expires_at', 'notes'];
        foreach ($allowed as $field) {
            $value = $request->input($field);
            if ($value !== null) {
                $user->$field = $value;
            }
        }

        $user->save();
        return $this->json(['data' => $this->format($user)]);
    }

    public function destroy(Request $request, string $uuid): Response
    {
        $user = $this->mediaUsers->findByUuid($uuid);
        if (!$user) {
            throw new NotFoundException('Usuario no encontrado.');
        }

        $user->deleted_at = date('Y-m-d H:i:s');
        $user->status = 'deleted';
        $user->save();

        return $this->json(['message' => 'Usuario eliminado.']);
    }

    /** @return array<string, mixed> */
    private function format(MediaUser $user): array
    {
        return [
            'uuid' => $user->uuid,
            'username' => $user->username,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'status' => $user->status,
            'max_streams' => (int) $user->max_streams,
            'max_devices' => (int) $user->max_devices,
            'expires_at' => $user->expires_at,
            'last_login_at' => $user->last_login_at,
            'total_plays' => (int) $user->total_plays,
            'created_at' => $user->created_at ?? null,
        ];
    }
}
