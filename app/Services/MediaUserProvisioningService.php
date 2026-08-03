<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use App\Services\Media\JellyfinService;
use App\Services\Media\PlexService;
use Core\Logger;

/**
 * Provisiona (da de alta) un usuario en el servidor Plex/Jellyfin real,
 * en lugar de dejarlo solo como un registro en base de datos.
 */
final class MediaUserProvisioningService
{
    /** @return array{success: bool, message: string, status: string, password?: string} */
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

    /** @return array{success: bool, message: string, status: string, password?: string} */
    private function provisionJellyfin(MediaUser $user, Server $server, ?string $plainPassword): array
    {
        $password = $plainPassword !== null && $plainPassword !== '' ? $plainPassword : bin2hex(random_bytes(6));

        $jellyfin = new JellyfinService($server);
        $created = $jellyfin->createUser($user->username, $password);

        $externalId = (string) ($created['Id'] ?? '');
        if ($externalId === '') {
            return ['success' => false, 'message' => 'No se pudo crear el usuario en Jellyfin (revisa la conexión/API key).', 'status' => $user->status];
        }

        $user->external_id = $externalId;
        $user->status = 'active';
        $user->save();

        return [
            'success' => true,
            'message' => "Usuario creado en Jellyfin. Contraseña: {$password}",
            'status' => 'active',
            'password' => $password,
        ];
    }
}
