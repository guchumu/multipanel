<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\PlexService;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\TelegramChannel;

/**
 * User lifecycle: suspend, activate, dates, notes, Telegram, server sync.
 */
final class MediaUserManagementService
{
    public function __construct(
        private MediaUserRepository $users = new MediaUserRepository(),
        private ServerRepository $servers = new ServerRepository(),
        private AuditService $audit = new AuditService(),
        private TelegramChannel $telegram = new TelegramChannel(),
        private NotificationService $adminAlerts = new NotificationService(),
    ) {
    }

    /** @return array{success: bool, message: string, server_sync?: bool, detail?: array<string, mixed>} */
    public function suspend(MediaUser $user): array
    {
        $old = ['status' => $user->status];
        $user->status = 'suspended';
        $user->save();

        $syncResult = $this->syncServerAccessDetailed($user, disable: true);
        $sync = (bool) ($syncResult['ok'] ?? false);
        AuditService::log('media_user.suspended', 'media_user', (int) $user->id, $old, [
            'status' => 'suspended',
            'server_sync' => $sync,
            'revoke' => $syncResult,
        ]);

        $hasServer = (int) ($user->server_id ?? 0) > 0;

        return [
            // Si hay servidor asociado, el éxito real depende de cortar el acceso allí.
            'success' => !$hasServer || $sync,
            'message' => $sync
                ? 'Usuario suspendido. Acceso a la biblioteca cortado y sesiones activas terminadas.'
                : ($hasServer
                    ? 'Usuario marcado como suspendido en el panel, pero NO se pudo cortar el acceso en el servidor. '
                        . (string) ($syncResult['message'] ?? 'Revisa token, machine_id y que el usuario exista como friend en plex.tv.')
                    : 'Usuario suspendido (sin servidor asociado).'),
            'server_sync' => $sync,
            'detail' => $syncResult,
        ];
    }

    /** @return array{success: bool, message: string, server_sync?: bool} */
    public function activate(MediaUser $user): array
    {
        $old = ['status' => $user->status];
        $user->status = 'active';
        $user->save();

        $sync = $this->syncServerAccess($user, disable: false);
        AuditService::log('media_user.activated', 'media_user', (int) $user->id, $old, [
            'status' => 'active',
            'server_sync' => $sync,
        ]);

        $hasServer = (int) ($user->server_id ?? 0) > 0;

        return [
            'success' => !$hasServer || $sync,
            'message' => $sync
                ? 'Usuario activado. Acceso a la biblioteca restaurado.'
                : ($hasServer
                    ? 'Usuario marcado como activo en el panel, pero NO se pudo restaurar el acceso en el servidor. Revisa token/machine_id o vuelve a invitar.'
                    : 'Usuario activado (sin servidor asociado).'),
            'server_sync' => $sync,
        ];
    }

    /** @return array{success: bool, message: string, expires_at: ?string} */
    public function updateExpires(MediaUser $user, ?string $expiresAt): array
    {
        $old = ['expires_at' => $user->expires_at];
        $user->expires_at = $expiresAt;
        $user->save();
        AuditService::log('media_user.expires_updated', 'media_user', (int) $user->id, $old, ['expires_at' => $expiresAt]);

        return [
            'success' => true,
            'message' => 'Fecha actualizada.',
            'expires_at' => $user->expires_at,
        ];
    }

    /** @return array{success: bool, message: string, expires_at: string} */
    public function addDays(MediaUser $user, int $days): array
    {
        if ($days <= 0) {
            return ['success' => false, 'message' => 'Los días deben ser positivos.', 'expires_at' => (string) ($user->expires_at ?? '')];
        }

        $old = ['expires_at' => $user->expires_at];
        $newDate = SubscriptionPeriod::addDaysToExpires($user->expires_at, $days);
        $user->expires_at = $newDate;
        $wasInactive = in_array($user->status, ['suspended', 'expired'], true);
        if ($wasInactive) {
            $user->status = 'active';
        }
        $user->save();

        if ($wasInactive) {
            $this->syncServerAccess($user, disable: false);
        }

        AuditService::log('media_user.days_added', 'media_user', (int) $user->id, $old, [
            'expires_at' => $newDate,
            'days_added' => $days,
        ]);

        $serverName = '';
        if (!empty($user->server_id)) {
            $server = Server::find((int) $user->server_id);
            $serverName = $server ? (string) $server->name : '';
        }
        $this->adminAlerts->notifyMediaUserRenewed(
            (string) ($user->email ?: $user->username ?: ''),
            $serverName,
            $days,
            $newDate,
            (int) ($user->tenant_id ?? 1),
            (string) ($user->username ?? '')
        );

        return [
            'success' => true,
            'message' => sprintf('+%d días aplicados. Nueva fecha: %s', $days, substr($newDate, 0, 10)),
            'expires_at' => $newDate,
        ];
    }

    /** @return array{success: bool, message: string} */
    public function updateNotes(MediaUser $user, ?string $notes): array
    {
        $old = ['notes' => $user->notes];
        $user->notes = $notes !== '' ? $notes : null;
        $user->save();
        AuditService::log('media_user.notes_updated', 'media_user', (int) $user->id, $old, ['notes' => $user->notes]);

        return ['success' => true, 'message' => 'Notas guardadas.'];
    }

    /** @return array{success: bool, message: string, telegram_chat_id: ?string} */
    public function updateTelegram(MediaUser $user, ?string $chatId): array
    {
        $this->users->ensureTelegramChatIdColumn();
        $old = ['telegram_chat_id' => $user->telegram_chat_id ?? null];
        $user->telegram_chat_id = $chatId !== null
            ? (normalize_telegram_chat_id($chatId) ?: null)
            : null;
        $user->save();
        AuditService::log('media_user.telegram_updated', 'media_user', (int) $user->id, $old, [
            'telegram_chat_id' => $user->telegram_chat_id,
        ]);

        return [
            'success' => true,
            'message' => 'Telegram actualizado.',
            'telegram_chat_id' => $user->telegram_chat_id,
        ];
    }

    /** @return array{success: bool, message: string, email: ?string} */
    public function updateEmail(MediaUser $user, ?string $email): array
    {
        $clean = $email !== null ? trim($email) : '';
        if ($clean !== '' && !filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Email no válido.',
                'email' => $user->email !== null ? (string) $user->email : null,
            ];
        }

        $old = ['email' => $user->email ?? null];
        $user->email = $clean !== '' ? $clean : null;
        $user->save();
        AuditService::log('media_user.email_updated', 'media_user', (int) $user->id, $old, [
            'email' => $user->email,
        ]);

        return [
            'success' => true,
            'message' => 'Email actualizado.',
            'email' => $user->email !== null ? (string) $user->email : null,
        ];
    }

    /** @return array{success: bool, message: string, whatsapp_phone: ?string} */
    public function updateWhatsapp(MediaUser $user, ?string $phone): array
    {
        $old = ['whatsapp_phone' => $user->metaGet('whatsapp_phone')];
        $digits = $phone !== null ? preg_replace('/\D+/', '', $phone) : '';
        $clean = $digits !== '' ? $digits : null;
        $user->metaSet('whatsapp_phone', $clean);
        $user->save();

        AuditService::log('media_user.whatsapp_updated', 'media_user', (int) $user->id, $old, [
            'whatsapp_phone' => $clean,
        ]);

        return [
            'success' => true,
            'message' => 'WhatsApp actualizado.',
            'whatsapp_phone' => $clean,
        ];
    }

    /** @return array{success: bool, message: string, sent: bool} */
    public function sendTelegramMessage(MediaUser $user, string $title, string $body): array
    {
        $chatId = trim((string) ($user->telegram_chat_id ?? ''));
        if ($chatId === '') {
            return ['success' => false, 'message' => 'El usuario no tiene Chat ID de Telegram.', 'sent' => false];
        }

        $sent = $this->telegram->send($title, $body, [
            'chat_id' => $chatId,
            'media_user_id' => (int) $user->id,
            'tenant_id' => (int) ($user->tenant_id ?? 1),
            'message_type' => 'manual',
            'log_message' => true,
            'user_message' => true,
        ]);

        AuditService::log('media_user.message_sent', 'media_user', (int) $user->id, null, [
            'title' => $title,
            'sent' => $sent,
        ]);

        return [
            'success' => $sent,
            'message' => $sent ? 'Mensaje enviado.' : 'No se pudo enviar el mensaje.',
            'sent' => $sent,
        ];
    }

    /** @return array{success: bool, message: string, removed: bool} */
    public function removeFromServer(MediaUser $user): array
    {
        $server = $user->server_id ? Server::find((int) $user->server_id) : null;
        if ($server === null) {
            return ['success' => false, 'message' => 'No hay servidor asociado.', 'removed' => false];
        }

        $removed = false;

        if ($server->type === 'plex') {
            $plex = new PlexService($server);
            $sharedServerId = (int) ($user->metaGet('plex_shared_server_id') ?? 0);
            $share = $sharedServerId > 0
                ? ['id' => $sharedServerId]
                : $plex->findSharedServerFor($user->email, $user->username);

            if ($share !== null && (int) $share['id'] > 0) {
                $removed = $plex->removeSharedServer((int) $share['id']);
            }

            $externalId = trim((string) ($user->external_id ?? ''));
            if (!$removed && $externalId !== '') {
                // Fallback: cuenta gestionada (Plex Home), no un "friend" compartido.
                $removed = $plex->deleteUser($externalId);
            }
        } elseif ($server->type === 'jellyfin') {
            $externalId = trim((string) ($user->external_id ?? ''));
            if ($externalId !== '') {
                $removed = (new JellyfinService($server))->deleteUser($externalId);
            }
        }

        if ($removed) {
            $user->status = 'suspended';
            $user->on_server = 0;
            $user->membership_synced_at = now()->format('Y-m-d H:i:s');
            $user->metaSet('plex_shared_server_id', null);
            $user->metaSet('plex_library_section_ids', null);
            $user->save();
            AuditService::log('media_user.removed_from_server', 'media_user', (int) $user->id, null, [
                'server_id' => (int) $server->id,
                'external_id' => (string) ($user->external_id ?? ''),
            ]);
        }

        return [
            'success' => $removed,
            'message' => $removed ? 'Usuario eliminado del servidor.' : 'No se pudo quitar del servidor. Revisa el token/permisos del servidor.',
            'removed' => $removed,
        ];
    }

    /**
     * Aplica un pago confirmado (Stripe, etc.) a un usuario media: suma los días
     * pagados, reactiva el acceso si estaba suspendido/caducado y avisa por Telegram.
     *
     * @return array{success: bool, message: string, expires_at: string}
     */
    public function applyPayment(MediaUser $user, int $days, float $amount, string $currency): array
    {
        $result = $this->addDays($user, $days);

        AuditService::log('media_user.payment_renewed', 'media_user', (int) $user->id, null, [
            'days' => $days,
            'amount' => $amount,
            'currency' => $currency,
            'expires_at' => $result['expires_at'] ?? null,
        ]);

        $chatId = trim((string) ($user->telegram_chat_id ?? ''));
        if ($chatId !== '' && ($result['expires_at'] ?? '') !== '') {
            $body = sprintf(
                "✅ Hemos recibido tu pago de %s %s.\nTu suscripción ha sido renovada hasta el %s.\n¡Gracias por confiar en nosotros!",
                number_format($amount, 2, ',', '.'),
                strtoupper($currency),
                substr((string) $result['expires_at'], 0, 10)
            );
            $this->sendTelegramMessage($user, 'Pago recibido ✅', $body);
        }

        return $result;
    }

    /** @return array{success: bool, message: string} */
    public function updateProfile(MediaUser $user, array $data): array
    {
        $old = [
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'max_streams' => $user->max_streams,
            'max_devices' => $user->max_devices,
        ];

        $username = trim((string) ($data['username'] ?? $user->username));
        if ($username === '') {
            return ['success' => false, 'message' => 'El nombre de usuario no puede estar vacío.'];
        }

        $user->username = $username;
        $user->display_name = trim((string) ($data['display_name'] ?? '')) ?: null;
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'El email no es válido.'];
        }
        $user->email = $email ?: null;
        // Vacío / null = usar default del tenant (settings.streams.default_max_streams)
        if (array_key_exists('max_streams', $data)) {
            $rawStreams = $data['max_streams'];
            $user->max_streams = ($rawStreams === null || $rawStreams === '')
                ? null
                : max(1, min(50, (int) $rawStreams));
        }
        $user->max_devices = max(1, (int) ($data['max_devices'] ?? $user->max_devices ?? 5));
        $user->save();

        AuditService::log('media_user.profile_updated', 'media_user', (int) $user->id, $old, [
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'max_streams' => $user->max_streams,
            'max_devices' => $user->max_devices,
        ]);

        return ['success' => true, 'message' => 'Datos del usuario actualizados.'];
    }

    /**
     * @param array<int, MediaUser> $users
     * @return array{sent: int, failed: int, skipped: int, errors: array<int, string>}
     */
    public function broadcastTelegram(array $users, string $title, string $body): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($users as $user) {
            $chatId = trim((string) ($user->telegram_chat_id ?? ''));
            if ($chatId === '') {
                $stats['skipped']++;
                continue;
            }

            $personalized = $this->personalizeMessage($body, $user);
            $result = $this->sendTelegramMessage($user, $title, $personalized);
            if ($result['sent']) {
                $stats['sent']++;
            } else {
                $stats['failed']++;
                $stats['errors'][] = ($user->email ?? $user->username) . ': ' . $result['message'];
            }
        }

        return $stats;
    }

    public function personalizeMessage(string $template, MediaUser $user, ?string $serverName = null): string
    {
        $expires = $user->expires_at ? substr((string) $user->expires_at, 0, 10) : '';
        $daysLeft = '';
        if ($expires !== '') {
            $today = new \DateTimeImmutable('today');
            $expiresDate = new \DateTimeImmutable($expires);
            $daysLeft = (string) (int) floor(($expiresDate->getTimestamp() - $today->getTimestamp()) / 86400);
        }

        $replacements = [
            '{username}' => (string) $user->username,
            '{email}' => (string) ($user->email ?? ''),
            '{display_name}' => (string) ($user->display_name ?? $user->username),
            '{expires_at}' => $expires,
            '{expires_date}' => $expires,
            '{end_date}' => $expires,
            '{days_left}' => $daysLeft,
            '{server_name}' => (string) ($serverName ?? $user->server_name ?? ''),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function syncServerAccess(MediaUser $user, bool $disable): bool
    {
        return (bool) ($this->syncServerAccessDetailed($user, $disable)['ok'] ?? false);
    }

    /**
     * @return array{ok: bool, message?: string, method?: ?string, attempts?: array<int, mixed>, sessions_killed?: int}
     */
    private function syncServerAccessDetailed(MediaUser $user, bool $disable): array
    {
        if (!$user->server_id) {
            return ['ok' => false, 'message' => 'Usuario sin servidor asociado.'];
        }

        $server = Server::find((int) $user->server_id);
        if ($server === null) {
            return ['ok' => false, 'message' => 'Servidor no encontrado en la base de datos.'];
        }

        if ($server->type === 'jellyfin') {
            $externalId = trim((string) ($user->external_id ?? ''));
            if ($externalId === '') {
                return ['ok' => false, 'message' => 'Falta external_id de Jellyfin.'];
            }
            $service = new JellyfinService($server);
            $ok = $disable ? $service->disableUser($externalId) : $service->enableUser($externalId);
            $killed = 0;
            if ($ok && $disable) {
                $killed = $service->terminateSessionsForUser(
                    (string) $user->username,
                    (string) ($user->display_name ?? ''),
                );
            }

            return [
                'ok' => $ok,
                'method' => $disable ? 'jellyfin_disable' : 'jellyfin_enable',
                'sessions_killed' => $killed,
                'message' => $ok ? null : 'Jellyfin rechazó desactivar/activar al usuario.',
            ];
        }

        if ($server->type === 'plex') {
            return $disable
                ? $this->cutPlexAccess($user, $server)
                : ['ok' => $this->restorePlexAccess($user, $server), 'method' => 'plex_restore'];
        }

        return ['ok' => false, 'message' => 'Tipo de servidor no soportado.'];
    }

    /**
     * Corta el acceso Plex como SERVEROLD (varios DELETE + verificación real).
     *
     * @return array{ok: bool, message?: string, method?: ?string, attempts?: array<int, mixed>, sessions_killed?: int, verified?: bool}
     */
    private function cutPlexAccess(MediaUser $user, Server $server): array
    {
        $plex = new PlexService($server);
        $email = trim((string) ($user->email ?? ''));
        $username = trim((string) ($user->username ?? ''));
        $externalId = trim((string) ($user->external_id ?? ''));

        $share = $plex->findSharedServerFor($email, $username, $externalId);
        $cachedId = (int) ($user->metaGet('plex_shared_server_id') ?? 0);

        // Guardar secciones actuales para poder restaurar al reactivar.
        $currentSections = [];
        if ($share !== null) {
            $currentSections = $share['library_section_ids'] ?? [];
        }
        if (!is_array($currentSections) || $currentSections === []) {
            $currentSections = $user->metaGet('plex_library_section_ids');
        }
        if (!is_array($currentSections) || $currentSections === []) {
            $currentSections = $plex->allLibrarySectionIds();
        }
        $currentSections = array_values(array_map('intval', (array) $currentSections));

        if ($share === null && $cachedId <= 0) {
            $killed = $plex->terminateSessionsForUser($username, (string) ($user->display_name ?? ''), $email);

            return [
                'ok' => false,
                'verified' => false,
                'sessions_killed' => $killed,
                'message' => 'No aparece en shared_servers de plex.tv (¿falta machine_id/token del servidor, o es usuario Home?). '
                    . 'Se intentó matar sesiones (' . $killed . ').',
                'attempts' => [],
            ];
        }

        $revoke = $plex->revokeFriendAccess($email, $username, $externalId !== '' ? $externalId : (string) $cachedId);

        $killed = $plex->terminateSessionsForUser(
            $username,
            (string) ($user->display_name ?? ''),
            $email,
            (string) ($share['username'] ?? ''),
        );

        if ($revoke['ok'] && ($revoke['verified'] ?? false)) {
            $user->metaSet('plex_library_section_ids', $currentSections);
            $user->metaSet('plex_share_removed', ($revoke['method'] ?? '') !== 'zero_libraries');
            $user->metaSet('plex_shared_server_id', null);
            $user->save();

            return [
                'ok' => true,
                'verified' => true,
                'method' => $revoke['method'],
                'attempts' => $revoke['attempts'],
                'sessions_killed' => $killed,
            ];
        }

        // Resumen legible de los intentos fallidos para el toast.
        $attemptSummary = [];
        foreach ($revoke['attempts'] ?? [] as $attempt) {
            if (isset($attempt['http'])) {
                $attemptSummary[] = ($attempt['type'] ?? '?') . '=' . $attempt['http'];
            } elseif (isset($attempt['status'])) {
                $attemptSummary[] = ($attempt['type'] ?? '?') . '=' . $attempt['status'];
            } elseif (isset($attempt['detail'])) {
                $attemptSummary[] = ($attempt['type'] ?? '?') . ': ' . $attempt['detail'];
            }
        }

        $error = trim((string) ($revoke['error'] ?? ''));

        return [
            'ok' => false,
            'verified' => false,
            'method' => $revoke['method'] ?? null,
            'attempts' => $revoke['attempts'] ?? [],
            'sessions_killed' => $killed,
            'message' => ($error !== '' ? $error . ' ' : 'Plex.tv no confirmó el corte. ')
                . 'Intentos: ' . ($attemptSummary !== [] ? implode(', ', $attemptSummary) : 'ninguno')
                . '. Comprueba machine_id y token del servidor en Servidores.',
        ];
    }

    /** Restaura el acceso Plex: PUT de bibliotecas o re-invitación si el share se eliminó. */
    private function restorePlexAccess(MediaUser $user, Server $server): bool
    {
        $plex = new PlexService($server);
        $sections = $user->metaGet('plex_library_section_ids');
        if (!is_array($sections) || $sections === []) {
            $sections = $plex->allLibrarySectionIds();
        }
        $sections = array_values(array_map('intval', $sections));

        $share = $plex->findSharedServerFor(
            $user->email,
            $user->username,
            trim((string) ($user->external_id ?? ''))
        );

        $shareRemoved = (bool) $user->metaGet('plex_share_removed', false);

        if ($share !== null) {
            $sharedServerId = (int) $share['id'];
            $ok = $plex->updateSharedServerLibraries($sharedServerId, $sections);
            if ($ok) {
                $user->metaSet('plex_shared_server_id', $sharedServerId);
                $user->metaSet('plex_share_removed', false);
                $user->metaSet('plex_library_section_ids', $sections);
                $user->save();
            }

            return $ok;
        }

        // Share eliminado en la pausa (o nunca existió): re-compartir por email.
        $email = trim((string) ($user->email ?? ''));
        if ($email === '' || $sections === []) {
            return false;
        }

        $ok = $plex->inviteUserByEmail($email, $sections);
        if ($ok) {
            $user->metaSet('plex_share_removed', false);
            $user->metaSet('plex_library_section_ids', $sections);
            // Tras reinvitar, el shared_server_id se refrescará en la próxima sync.
            $fresh = $plex->findSharedServerFor($email, $user->username);
            if ($fresh !== null) {
                $user->metaSet('plex_shared_server_id', (int) $fresh['id']);
                $user->status = 'active';
            } else {
                $user->status = $shareRemoved ? 'invited' : 'active';
            }
            $user->save();
        }

        return $ok;
    }
}
