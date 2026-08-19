<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Services\Notifications\ClientWhatsAppChannel;
use Core\Cache;
use Core\Database;
use Core\Logger;

/**
 * Contraseña de portal por defecto y recuperación por Telegram (email).
 */
final class PortalDefaultPasswordService
{
    /** Contraseña inicial / recuperación para todos los clientes del portal. */
    public const DEFAULT_PASSWORD = '123456@';

    private const RECOVER_EMAIL_TTL = 3600;
    private const RECOVER_EMAIL_MAX = 3;
    private const RECOVER_IP_TTL = 3600;
    private const RECOVER_IP_MAX = 12;

    public function __construct(
        private PasswordService $passwords = new PasswordService(),
        private MediaUserManagementService $management = new MediaUserManagementService(),
        private ClientWhatsAppChannel $whatsapp = new ClientWhatsAppChannel(),
    ) {
    }

    public function defaultPassword(): string
    {
        return self::DEFAULT_PASSWORD;
    }

    public function hashDefault(): string
    {
        return $this->passwords->hash(self::DEFAULT_PASSWORD);
    }

    /**
     * Asigna la contraseña por defecto a todos los media_users activos del tenant.
     *
     * @return array{success: bool, message: string, updated: int}
     */
    public function setDefaultForAllUsers(int $tenantId): array
    {
        $hash = $this->hashDefault();
        $db = Database::getInstance();

        try {
            $stmt = $db->query(
                'UPDATE `media_users`
                 SET `password` = ?
                 WHERE `tenant_id` = ? AND `deleted_at` IS NULL',
                [$hash, $tenantId]
            );
            $updated = $stmt->rowCount();
        } catch (\Throwable $e) {
            Logger::error('Portal default password bulk update failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'No se pudo actualizar la BD: ' . $e->getMessage(),
                'updated' => 0,
            ];
        }

        AuditService::log(
            'media_user.portal_password_bulk_reset',
            'media_user',
            null,
            null,
            ['tenant_id' => $tenantId, 'updated' => $updated, 'default_password_set' => true],
            null,
            $tenantId
        );

        return [
            'success' => true,
            'message' => sprintf(
                'Contraseña del portal actualizada a «%s» para %d usuario(s). Login: email + esa contraseña.',
                self::DEFAULT_PASSWORD,
                $updated
            ),
            'updated' => $updated,
        ];
    }

    /**
     * Busca por email, asegura hash por defecto y envía la contraseña por Telegram.
     *
     * @return array{success: bool, message: string}
     */
    public function sendPasswordByEmail(string $email, ?string $clientIp = null): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Introduce un email válido.'];
        }

        if (!$this->allowRecoverAttempt($email, $clientIp)) {
            return [
                'success' => false,
                'message' => 'Demasiados intentos. Espera un rato e inténtalo de nuevo.',
            ];
        }

        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM `media_users`
             WHERE LOWER(TRIM(`email`)) = ? AND `deleted_at` IS NULL
             ORDER BY `id` DESC
             LIMIT 20',
            [$email]
        );

        // Respuesta genérica si no existe (no filtrar emails).
        if ($rows === []) {
            return [
                'success' => true,
                'message' => 'Si ese email está registrado y tiene Telegram o WhatsApp, recibirás la contraseña en unos segundos.',
            ];
        }

        $plain = self::DEFAULT_PASSWORD;
        $hash = $this->passwords->hash($plain);

        // Misma contraseña en TODAS las filas con ese email (evita login con fila antigua).
        Database::getInstance()->query(
            'UPDATE `media_users`
             SET `password` = ?
             WHERE LOWER(TRIM(`email`)) = ? AND `deleted_at` IS NULL',
            [$hash, $email]
        );

        $user = null;
        foreach ($rows as $row) {
            $candidate = new MediaUser($row);
            $tg = normalize_telegram_chat_id($candidate->telegram_chat_id ?? null);
            if ($tg !== '') {
                $user = $candidate;
                break;
            }
        }
        if ($user === null) {
            foreach ($rows as $row) {
                $candidate = new MediaUser($row);
                if ($this->whatsapp->canSend($candidate)) {
                    $user = $candidate;
                    break;
                }
            }
        }
        $user ??= new MediaUser($rows[0]);

        $chatId = normalize_telegram_chat_id($user->telegram_chat_id ?? null);
        $canWa = $this->whatsapp->canSend($user);
        if ($chatId === '' && !$canWa) {
            return [
                'success' => false,
                'message' => 'Esa cuenta no tiene Telegram ni WhatsApp vinculados. Entra al portal o contacta con el administrador.',
            ];
        }

        $body = "Tu acceso al portal MultiPanel:\n"
            . "Email: {$email}\n"
            . "Contraseña: {$plain}\n\n"
            . "Entra en /portal/login con tu email y esa contraseña.";

        $sent = $this->management->sendClientNotice($user, 'Contraseña del portal', $body, 'portal_password');
        if (empty($sent['sent'])) {
            return [
                'success' => false,
                'message' => 'No se pudo enviar. Prueba más tarde o contacta con soporte.',
            ];
        }

        $via = [];
        if (!empty($sent['telegram'])) {
            $via[] = 'Telegram';
        }
        if (!empty($sent['whatsapp'])) {
            $via[] = 'WhatsApp';
        }

        return [
            'success' => true,
            'message' => 'Contraseña enviada a tu ' . implode(' y ', $via) . '. Entra con tu email y esa contraseña.',
        ];
    }

    private function allowRecoverAttempt(string $email, ?string $clientIp): bool
    {
        $emailKey = 'portal_pw_recover_email_' . hash('sha256', $email);
        $emailCount = (int) (Cache::get($emailKey) ?? 0);
        if ($emailCount >= self::RECOVER_EMAIL_MAX) {
            return false;
        }
        Cache::set($emailKey, $emailCount + 1, self::RECOVER_EMAIL_TTL);

        $ip = trim((string) $clientIp);
        if ($ip !== '') {
            $ipKey = 'portal_pw_recover_ip_' . hash('sha256', $ip);
            $ipCount = (int) (Cache::get($ipKey) ?? 0);
            if ($ipCount >= self::RECOVER_IP_MAX) {
                return false;
            }
            Cache::set($ipKey, $ipCount + 1, self::RECOVER_IP_TTL);
        }

        return true;
    }
}
