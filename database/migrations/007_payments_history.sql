-- Legacy payment registration history (guarda-registro.php compatible)
CREATE TABLE IF NOT EXISTS `payments_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `client_id` VARCHAR(50) NOT NULL,
    `telegram_chat_id` VARCHAR(50) NULL,
    `email` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `payment_type` VARCHAR(50) NOT NULL,
    `months_added` DECIMAL(4,2) NOT NULL DEFAULT 1,
    `service` VARCHAR(50) NOT NULL DEFAULT 'plex',
    `media_user_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payments_client` (`client_id`),
    KEY `idx_payments_email` (`email`),
    KEY `idx_payments_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
