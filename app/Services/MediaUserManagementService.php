<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use App\Repositories\MediaUserRepository;
use App\Repositories\ServerRepository;
use App\Services\Media\JellyfinService;
use App\Services\Media\PlexService;
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
    ) {
    }

    /** @return array{success: bool, message: string, server_sync?: bool} */
    public function suspend(MediaUser $user): array
    {
        $old = ['status' => $user->status];
        $user->status = 'suspended';
        $user->save();

        $sync = $this->syncServerAccess($user, disable: true);
        AuditService::log('media_user.suspended', 'media_user', (int) $user->id, $old, ['status' => 'suspended']);

        return [
            'success' => true,
            'message' => $sync
                ? 'Usuario suspendido. Acceso a la biblioteca cortado.'
                : 'Usuario suspendido en el panel, pero no se pudo cortar el acceso en el servidor (revisa la conexión/servidor).',
            'server_sync' => $sync,
        ];
    }

    /** @return array{success: bool, message: string, server_sync?: bool} */
    public function activate(MediaUser $user): array
    {
        $old = ['status' => $user->status];
        $user->status = 'active';
        $user->save();

        $sync = $this->syncServerAccess($user, disable: false);
        AuditService::log('media_user.activated', 'media_user', (int) $user->id, $old, ['status' => 'active']);

        return [
            'success' => true,
            'message' => $sync
                ? 'Usuario activado. Acceso a la biblioteca restaurado.'
                : 'Usuario activado en el panel, pero no se pudo restaurar el acceso en el servidor (revisa la conexión/servidor).',
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
        if ($user->status === 'suspended' || $user->status === 'expired') {
            $user->status = 'active';
        }
        $user->save();

        AuditService::log('media_user.days_added', 'media_user', (int) $user->id, $old, [
            'expires_at' => $newDate,
            'days_added' => $days,
        ]);

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
        $user->telegram_chat_id = $chatId !== '' && $chatId !== null ? $chatId : null;
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
            'message_type' => 'manual',
            'log_message' => true,
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
        $user->max_streams = max(1, (int) ($data['max_streams'] ?? $user->max_streams ?? 1));
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
        if (!$user->server_id) {
            return false;
        }

        $server = Server::find((int) $user->server_id);
        if ($server === null) {
            return false;
        }

        if ($server->type === 'jellyfin') {
            $externalId = trim((string) ($user->external_id ?? ''));
            if ($externalId === '') {
                return false;
            }
            $service = new JellyfinService($server);

            return $disable ? $service->disableUser($externalId) : $service->enableUser($externalId);
        }

        if ($server->type === 'plex') {
            return $disable
                ? $this->cutPlexAccess($user, $server)
                : $this->restorePlexAccess($user, $server);
        }

        return false;
    }

    /**
     * Corta el acceso de un usuario Plex quitándole las secciones de biblioteca compartidas,
     * conservando la amistad para poder restaurarlas después sin necesidad de re-aceptación.
     */
    private function cutPlexAccess(MediaUser $user, Server $server): bool
    {
        $plex = new PlexService($server);
        $sharedServerId = (int) ($user->metaGet('plex_shared_server_id') ?? 0);
        $share = $sharedServerId > 0 ? null : $plex->findSharedServerFor($user->email, $user->username);

        if ($sharedServerId <= 0) {
            if ($share === null) {
                return false;
            }
            $sharedServerId = (int) $share['id'];
        }

        $currentSections = $share['library_section_ids'] ?? $user->metaGet('plex_library_section_ids');
        if (!is_array($currentSections) || $currentSections === []) {
            $currentSections = $plex->allLibrarySectionIds();
        }

        $ok = $plex->updateSharedServerLibraries($sharedServerId, []);
        if ($ok) {
            $user->metaSet('plex_shared_server_id', $sharedServerId);
            $user->metaSet('plex_library_section_ids', array_values(array_map('intval', $currentSections)));
            $user->save();
        }

        return $ok;
    }

    /** Restaura el acceso de un usuario Plex a las bibliotecas que tenía antes de suspenderlo. */
    private function restorePlexAccess(MediaUser $user, Server $server): bool
    {
        $plex = new PlexService($server);
        $sharedServerId = (int) ($user->metaGet('plex_shared_server_id') ?? 0);

        if ($sharedServerId <= 0) {
            $share = $plex->findSharedServerFor($user->email, $user->username);
            if ($share === null) {
                return false;
            }
            $sharedServerId = (int) $share['id'];
        }

        $sections = $user->metaGet('plex_library_section_ids');
        if (!is_array($sections) || $sections === []) {
            $sections = $plex->allLibrarySectionIds();
        }

        $ok = $plex->updateSharedServerLibraries($sharedServerId, array_map('intval', $sections));
        if ($ok) {
            $user->metaSet('plex_shared_server_id', $sharedServerId);
            $user->save();
        }

        return $ok;
    }
}
