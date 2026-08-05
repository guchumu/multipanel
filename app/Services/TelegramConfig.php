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

        $sandboxEnabled = self::truthy(
            $stored['telegram_sandbox_enabled'] ?? null,
            env('TELEGRAM_SANDBOX', env('TELEGRAM_SANDBOX_ENABLED', false))
        );

        $sandboxChatId = trim((string) ($stored['telegram_sandbox_chat_id'] ?? ''));
        if ($sandboxChatId === '') {
            $sandboxChatId = trim((string) config('telegram.sandbox_chat_id', env('TELEGRAM_SANDBOX_CHAT_ID', '')));
        }

        $sandboxCopyReal = self::truthy(
            $stored['telegram_sandbox_copy_real'] ?? null,
            env('TELEGRAM_SANDBOX_COPY_REAL', false)
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
        return self::resolveTargets($intendedChatId, self::forTenant($tenantId));
    }

    /**
     * Resolución pura (útil en tests) a partir de una config ya resuelta.
     *
     * @param array{
     *   sandbox_enabled?: bool,
     *   sandbox_chat_id?: string,
     *   sandbox_copy_real?: bool
     * } $cfg
     * @return array<int, string>
     */
    public static function resolveTargets(string $intendedChatId, array $cfg): array
    {
        $intended = trim($intendedChatId);
        $sandboxEnabled = (bool) ($cfg['sandbox_enabled'] ?? false);
        $sandboxChatId = trim((string) ($cfg['sandbox_chat_id'] ?? ''));
        $sandboxCopyReal = (bool) ($cfg['sandbox_copy_real'] ?? false);

        if ($sandboxEnabled && $sandboxChatId !== '') {
            $targets = [$sandboxChatId];
            if ($sandboxCopyReal && $intended !== '' && $intended !== $sandboxChatId) {
                $targets[] = $intended;
            }

            return array_values(array_unique($targets));
        }

        return $intended !== '' ? [$intended] : [];
    }

    private static function truthy(mixed $stored, mixed $fallback): bool
    {
        if ($stored !== null && trim((string) $stored) !== '') {
            return in_array(strtolower(trim((string) $stored)), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_bool($fallback)) {
            return $fallback;
        }

        return in_array(strtolower(trim((string) $fallback)), ['1', 'true', 'yes', 'on'], true);
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
