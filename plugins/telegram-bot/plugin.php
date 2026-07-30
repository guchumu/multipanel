<?php

declare(strict_types=1);

namespace Plugins\TelegramBot;

use App\Plugins\Plugin;
use Core\Database;
use Core\Logger;

/**
 * Example plugin: Telegram bot commands integration.
 */
class Plugin extends \App\Plugins\Plugin
{
    public function getName(): string
    {
        return 'Telegram Bot Commands';
    }

    public function getSlug(): string
    {
        return 'telegram-bot';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Permite gestionar usuarios media via comandos de Telegram (/users, /status, /suspend).';
    }

    public function registerHooks(): void
    {
        listen('user.login', function ($user) {
            Logger::info('Telegram plugin: user logged in', ['user_id' => $user->id ?? null]);
            return $user;
        });
    }

    /** Process incoming Telegram command */
    public function handleCommand(string $command, string $chatId): ?string
    {
        return match ($command) {
            '/status' => $this->cmdStatus(),
            '/users' => $this->cmdUsers(),
            default => 'Comando no reconocido. Disponibles: /status, /users',
        };
    }

    private function cmdStatus(): string
    {
        $online = Database::getInstance()->fetchOne("SELECT COUNT(*) as c FROM servers WHERE status = 'online'")['c'] ?? 0;
        $users = Database::getInstance()->fetchOne("SELECT COUNT(*) as c FROM media_users WHERE status = 'active'")['c'] ?? 0;
        return "Servidores online: {$online}\nUsuarios activos: {$users}";
    }

    private function cmdUsers(): string
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT username, status FROM media_users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 10"
        );
        if (empty($rows)) {
            return 'No hay usuarios.';
        }
        $lines = array_map(fn ($r) => "• {$r['username']} ({$r['status']})", $rows);
        return "Últimos usuarios:\n" . implode("\n", $lines);
    }
}
