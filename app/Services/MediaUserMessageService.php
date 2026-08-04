<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Stores outbound messages sent to media users (Telegram, etc.).
 */
final class MediaUserMessageService
{
    public function log(
        ?int $mediaUserId,
        string $messageType,
        string $body,
        ?string $title = null,
        ?string $telegramChatId = null,
        string $channel = 'telegram',
        bool $sent = true,
    ): int {
        self::ensureMediaUserMessagesTable();

        return Database::getInstance()->insert('media_user_messages', [
            'media_user_id' => $mediaUserId,
            'channel' => $channel,
            'message_type' => $messageType,
            'title' => $title,
            'body' => $body,
            'telegram_chat_id' => $telegramChatId,
            'status' => $sent ? 'sent' : 'failed',
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $mediaUserId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        self::ensureMediaUserMessagesTable();

        try {
            // LIMIT must be inlined: native PDO prepares reject bound LIMIT params.
            return Database::getInstance()->fetchAll(
                "SELECT * FROM media_user_messages WHERE media_user_id = ? ORDER BY sent_at DESC LIMIT {$limit}",
                [$mediaUserId]
            );
        } catch (\Throwable) {
            // Table may not exist yet on older installs.
            return [];
        }
    }

    /**
     * Prefer the official Updater/migrations path; fall back to CREATE IF NOT EXISTS
     * only if the table is still missing (AUTO_MIGRATE off or migrate failed).
     */
    public static function ensureMediaUserMessagesTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if (self::tableExists('media_user_messages')) {
            $ensured = true;
            return;
        }

        try {
            (new \Core\Updater())->runMigrations();
        } catch (\Throwable) {
            // Fall through to direct CREATE.
        }

        if (self::tableExists('media_user_messages')) {
            $ensured = true;
            return;
        }

        try {
            Database::getInstance()->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `media_user_messages` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `media_user_id` BIGINT UNSIGNED NULL,
                    `channel` VARCHAR(30) NOT NULL DEFAULT \'telegram\',
                    `message_type` VARCHAR(50) NOT NULL,
                    `title` VARCHAR(255) NULL,
                    `body` TEXT NOT NULL,
                    `telegram_chat_id` VARCHAR(50) NULL,
                    `status` ENUM(\'sent\',\'failed\') NOT NULL DEFAULT \'sent\',
                    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_messages_user` (`media_user_id`),
                    KEY `idx_messages_chat` (`telegram_chat_id`),
                    KEY `idx_messages_sent` (`sent_at`),
                    CONSTRAINT `fk_messages_media_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable) {
            try {
                Database::getInstance()->pdo()->exec(
                    'CREATE TABLE IF NOT EXISTS `media_user_messages` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `media_user_id` BIGINT UNSIGNED NULL,
                        `channel` VARCHAR(30) NOT NULL DEFAULT \'telegram\',
                        `message_type` VARCHAR(50) NOT NULL,
                        `title` VARCHAR(255) NULL,
                        `body` TEXT NOT NULL,
                        `telegram_chat_id` VARCHAR(50) NULL,
                        `status` ENUM(\'sent\',\'failed\') NOT NULL DEFAULT \'sent\',
                        `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_messages_user` (`media_user_id`),
                        KEY `idx_messages_chat` (`telegram_chat_id`),
                        KEY `idx_messages_sent` (`sent_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } catch (\Throwable) {
                return;
            }
        }

        $ensured = true;
    }

    private static function tableExists(string $table): bool
    {
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT COUNT(*) AS total
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?',
                [$table]
            );

            return ((int) ($row['total'] ?? 0)) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
