<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Session;

/**
 * Resuelve token/chat de Telegram desde settings del tenant (Configuración)
 * con fallback a .env / config/telegram.php.
 */
final class TelegramConfig
{
    /**
     * @return array{
     *   bot_token: string,
     *   admin_chat_id: string,
     *   sandbox_enabled: bool,
     *   sandbox_chat_id: string,
     *   sandbox_copy_real: bool
     * }
     */
    public static function forTenant(?int $tenantId = null): array
    {
        $tenantId ??= (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $stored = self::loadGroup($tenantId, 'telegram');

        $botToken = trim((string) ($stored['telegram_bot_token'] ?? ''));
        if ($botToken === '') {
            $botToken = trim((string) config('telegram.bot_token', env('TELEGRAM_BOT_TOKEN', '')));
        }

        $adminChatId = trim((string) ($stored['telegram_chat_id'] ?? ''));
        if ($adminChatId === '') {
            $adminChatId = trim((string) config('telegram.chat_id', env('TELEGRAM_CHAT_ID', '')));
        }

        $sandboxEnabled = in_array(
            strtolower(trim((string) ($stored['telegram_sandbox_enabled'] ?? '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        $sandboxChatId = trim((string) ($stored['telegram_sandbox_chat_id'] ?? ''));
        $sandboxCopyReal = in_array(
            strtolower(trim((string) ($stored['telegram_sandbox_copy_real'] ?? '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );

        return [
            'bot_token' => $botToken,
            'admin_chat_id' => $adminChatId,
            'sandbox_enabled' => $sandboxEnabled,
            'sandbox_chat_id' => $sandboxChatId,
            'sandbox_copy_real' => $sandboxCopyReal,
        ];
    }

    /**
     * Destinos reales de un mensaje a un usuario.
     * En sandbox: va al chat de prueba (y opcionalmente también al usuario real).
     *
     * @return array<int, string>
     */
    public static function resolveOutboundChatIds(string $intendedChatId, ?int $tenantId = null): array
    {
        $cfg = self::forTenant($tenantId);
        $intended = trim($intendedChatId);

        if ($cfg['sandbox_enabled'] && $cfg['sandbox_chat_id'] !== '') {
            $targets = [$cfg['sandbox_chat_id']];
            if ($cfg['sandbox_copy_real'] && $intended !== '' && $intended !== $cfg['sandbox_chat_id']) {
                $targets[] = $intended;
            }

            return array_values(array_unique($targets));
        }

        return $intended !== '' ? [$intended] : [];
    }

    /** @return array<string, string> */
    private static function loadGroup(int $tenantId, string $group): array
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT `key`, `value` FROM settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND `group` = ?',
                [$tenantId, $group]
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        return $out;
    }
}
