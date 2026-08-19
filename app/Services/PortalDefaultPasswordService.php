<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
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

        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM `media_users`
             WHERE LOWER(`email`) = ? AND `deleted_at` IS NULL
             ORDER BY `id` DESC
             LIMIT 1',
            [$email]
        );

        // Respuesta genérica si no existe (no filtrar emails).
        if ($row === null) {
            return [
                'success' => true,
                'message' => 'Si ese email está registrado y tiene Telegram vinculado, recibirás la contraseña en unos segundos.',
            ];
        }

        $user = new MediaUser($row);
        $chatId = normalize_telegram_chat_id($user->telegram_chat_id ?? null);
        if ($chatId === '') {
            return [
                'success' => false,
                'message' => 'Esa cuenta no tiene Telegram vinculado. Contacta con el administrador.',
            ];
        }

        // Reaplicar siempre la contraseña por defecto y enviarla.
        $plain = self::DEFAULT_PASSWORD;
        $user->password = $this->passwords->hash($plain);
        $user->save();

        $body = "Tu acceso al portal MultiPanel:\n"
            . "Email: {$email}\n"
            . "Contraseña: {$plain}\n\n"
            . "Entra en /portal/login y cámbiala en Perfil si quieres.";

        $sent = $this->management->sendTelegramMessage($user, 'Contraseña del portal', $body);
        if (empty($sent['sent'])) {
            return [
                'success' => false,
                'message' => 'No se pudo enviar por Telegram. Prueba más tarde o contacta con soporte.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Contraseña enviada a tu Telegram. Revisa el chat del bot.',
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
