<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use App\Repositories\MediaUserRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\PlexService;
use Core\Logger;

/**
 * Provisiona (da de alta) un usuario en el servidor Plex/Jellyfin real,
 * en lugar de dejarlo solo como un registro en base de datos.
 */
final class MediaUserProvisioningService
{
    public function __construct(
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
        private PasswordService $passwords = new PasswordService(),
        private SecretCrypt $crypt = new SecretCrypt(),
    ) {
    }

    /** @return array{success: bool, message: string, status: string, password?: string, username?: string} */
    public function provision(MediaUser $user, Server $server, ?string $plainPassword = null): array
    {
        try {
            return match ($server->type) {
                'plex' => $this->provisionPlex($user, $server),
                'jellyfin' => $this->provisionJellyfin($user, $server, $plainPassword),
                default => ['success' => false, 'message' => 'Tipo de servidor no soportado.', 'status' => $user->status],
            };
        } catch (\Throwable $e) {
            Logger::error('Provisioning failed', ['user_id' => $user->id, 'server_id' => $server->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Error al aprovisionar: ' . $e->getMessage(), 'status' => $user->status];
        }
    }

    /**
     * Genera un username usable en Jellyfin a partir del email (o base dada).
     */
    public function generateJellyfinUsername(string $emailOrBase, int $tenantId, ?int $excludeUserId = null): string
    {
        $base = strstr($emailOrBase, '@', true) ?: $emailOrBase;
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '', $base));
        if ($base === '' || strlen($base) < 3) {
            $base = 'user' . bin2hex(random_bytes(2));
        }
        $base = substr($base, 0, 24);

        $candidate = $base;
        for ($i = 0; $i < 12; $i++) {
            $dup = $this->mediaUsers->findDuplicate($tenantId, $candidate, null, $excludeUserId);
            if ($dup === null) {
                return $candidate;
            }
            $candidate = substr($base, 0, 20) . random_int(10, 99) . substr(bin2hex(random_bytes(1)), 0, 2);
        }

        return substr($base, 0, 16) . '_' . bin2hex(random_bytes(3));
    }

    public function storeJellyfinPassword(MediaUser $user, string $plainPassword): void
    {
        $this->mediaUsers->ensureJellyfinPasswordColumn();
        $user->jellyfin_password_encrypted = $this->crypt->encrypt($plainPassword);
        // También hash portal (si el cliente usa login del panel).
        $user->password = $this->passwords->hash($plainPassword);
        $user->save();
    }

    public function revealJellyfinPassword(MediaUser $user): ?string
    {
        $this->mediaUsers->ensureJellyfinPasswordColumn();
        $payload = $user->jellyfin_password_encrypted ?? null;
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        return $this->crypt->decrypt($payload);
    }

    /** @return array{success: bool, message: string, username?: string, password?: string} */
    public function regenerateJellyfinPassword(MediaUser $user, Server $server): array
    {
        if ($server->type !== 'jellyfin') {
            return ['success' => false, 'message' => 'Solo aplica a servidores Jellyfin.'];
        }

        $externalId = trim((string) ($user->external_id ?? ''));
        if ($externalId === '') {
            return ['success' => false, 'message' => 'El usuario no tiene ID en Jellyfin. Vuelve a aprovisionarlo.'];
        }

        $password = $this->passwords->generate(12);
        $jellyfin = new JellyfinService($server);
        if (!$jellyfin->updateUserPassword($externalId, $password)) {
            return ['success' => false, 'message' => 'No se pudo cambiar la contraseña en Jellyfin.'];
        }

        $this->storeJellyfinPassword($user, $password);

        return [
            'success' => true,
            'message' => 'Contraseña regenerada en Jellyfin y guardada en el panel.',
            'username' => (string) $user->username,
            'password' => $password,
        ];
    }

    /** Texto listo para copiar/enviar al cliente. */
    public function credentialsShareText(MediaUser $user, Server $server, string $password): string
    {
        $lines = [
            'Acceso Jellyfin',
            'Servidor: ' . $server->name,
            'URL: ' . $server->fullUrl(),
            'Usuario: ' . $user->username,
            'Contraseña: ' . $password,
        ];

        return implode("\n", $lines);
    }

    /** @return array{success: bool, message: string, status: string} */
    private function provisionPlex(MediaUser $user, Server $server): array
    {
        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return ['success' => false, 'message' => 'Los usuarios Plex necesitan un email para ser invitados.', 'status' => $user->status];
        }

        $plex = new PlexService($server);

        // Si ya existe una relación de compartición (friend previo), solo hay que restaurar/asegurar acceso.
        $existingShare = $plex->findSharedServerFor($email, $user->username);
        if ($existingShare !== null) {
            $sections = $existingShare['library_section_ids'] !== [] ? $existingShare['library_section_ids'] : $plex->allLibrarySectionIds();
            $plex->updateSharedServerLibraries((int) $existingShare['id'], $sections);
            $user->metaSet('plex_shared_server_id', (int) $existingShare['id']);
            $user->metaSet('plex_library_section_ids', $sections);
            $user->status = 'active';
            $user->save();

            return ['success' => true, 'message' => 'El usuario ya era "friend" de Plex: acceso confirmado.', 'status' => 'active'];
        }

        $sectionIds = $plex->allLibrarySectionIds();
        if ($sectionIds === []) {
            return ['success' => false, 'message' => 'No se pudieron obtener las bibliotecas del servidor Plex (revisa la conexión).', 'status' => $user->status];
        }

        $ok = $plex->inviteUserByEmail($email, $sectionIds);
        if (!$ok) {
            return ['success' => false, 'message' => 'No se pudo enviar la invitación de Plex a ' . $email . '.', 'status' => $user->status];
        }

        $user->status = 'invited';
        $user->metaSet('plex_library_section_ids', $sectionIds);
        $user->save();

        return [
            'success' => true,
            'message' => "Invitación de Plex enviada a {$email}. Quedará activo en cuanto la acepte (se detecta automáticamente en la próxima sincronización).",
            'status' => 'invited',
        ];
    }

    /** @return array{success: bool, message: string, status: string, password?: string, username?: string} */
    private function provisionJellyfin(MediaUser $user, Server $server, ?string $plainPassword): array
    {
        $password = $plainPassword !== null && $plainPassword !== ''
            ? $plainPassword
            : $this->passwords->generate(12);

        $username = trim((string) ($user->username ?? ''));
        if ($username === '') {
            $username = $this->generateJellyfinUsername((string) ($user->email ?? 'user'), (int) $user->tenant_id, (int) $user->id);
            $user->username = $username;
            if (trim((string) ($user->display_name ?? '')) === '') {
                $user->display_name = $username;
            }
        }

        $jellyfin = new JellyfinService($server);
        $externalId = trim((string) ($user->external_id ?? ''));

        if ($externalId === '') {
            $created = $jellyfin->createUser($username, $password);
            $externalId = (string) ($created['Id'] ?? '');

            if ($externalId === '') {
                // Puede existir ya en Jellyfin: reutilizar y actualizar contraseña.
                $existing = $jellyfin->findUserByName($username);
                if ($existing === null) {
                    return [
                        'success' => false,
                        'message' => 'No se pudo crear el usuario en Jellyfin (revisa la conexión/API key o si el nombre ya existe).',
                        'status' => $user->status,
                    ];
                }
                $externalId = $existing['external_id'];
                if (!$jellyfin->updateUserPassword($externalId, $password)) {
                    return [
                        'success' => false,
                        'message' => 'El usuario ya existía en Jellyfin pero no se pudo actualizar la contraseña.',
                        'status' => $user->status,
                    ];
                }
            }
        } else {
            if (!$jellyfin->updateUserPassword($externalId, $password)) {
                return [
                    'success' => false,
                    'message' => 'No se pudo actualizar la contraseña del usuario Jellyfin existente.',
                    'status' => $user->status,
                ];
            }
        }

        $user->external_id = $externalId;
        $user->status = 'active';
        $user->on_server = 1;
        $this->storeJellyfinPassword($user, $password);

        return [
            'success' => true,
            'message' => "Usuario Jellyfin listo. Usuario: {$username} · Contraseña: {$password}",
            'status' => 'active',
            'username' => $username,
            'password' => $password,
        ];
    }
}
