-- Ensure expiry notice tracking table exists (006 may have been marked applied
-- after only telegram_chat_id was present, leaving this table missing).
CREATE TABLE IF NOT EXISTS `media_user_expiry_notices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `media_user_id` BIGINT UNSIGNED NOT NULL,
    `milestone` VARCHAR(10) NOT NULL COMMENT 'Days before expiry: 10,5,3,2,1,0,-1',
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_expiry_notice_user_milestone` (`media_user_id`, `milestone`),
    CONSTRAINT `fk_expiry_notice_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
