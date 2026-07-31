-- Message history per media user (Telegram, etc.)
CREATE TABLE IF NOT EXISTS `media_user_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `media_user_id` BIGINT UNSIGNED NULL,
    `channel` VARCHAR(30) NOT NULL DEFAULT 'telegram',
    `message_type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NULL,
    `body` TEXT NOT NULL,
    `telegram_chat_id` VARCHAR(50) NULL,
    `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_messages_user` (`media_user_id`),
    KEY `idx_messages_chat` (`telegram_chat_id`),
    KEY `idx_messages_sent` (`sent_at`),
    CONSTRAINT `fk_messages_media_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
