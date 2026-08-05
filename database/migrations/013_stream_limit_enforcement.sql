-- Concurrent stream limits: nullable per-user override + violation log
-- NULL max_streams = use tenant default (settings.streams.default_max_streams)

ALTER TABLE `media_users`
    MODIFY COLUMN `max_streams` TINYINT UNSIGNED NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `stream_limit_violations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `media_user_id` BIGINT UNSIGNED NULL,
    `server_id` BIGINT UNSIGNED NULL,
    `username` VARCHAR(255) NULL,
    `stream_count` INT UNSIGNED NOT NULL,
    `stream_limit` INT UNSIGNED NOT NULL,
    `session_ids` JSON NULL,
    `killed_session_ids` JSON NULL,
    `titles` JSON NULL,
    `action` VARCHAR(40) NOT NULL DEFAULT 'kill_newest',
    `message` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_stream_limit_violations_tenant` (`tenant_id`, `created_at`),
    KEY `idx_stream_limit_violations_user` (`media_user_id`, `created_at`),
    KEY `idx_stream_limit_violations_server` (`server_id`),
    CONSTRAINT `fk_stream_limit_violations_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_stream_limit_violations_user` FOREIGN KEY (`media_user_id`) REFERENCES `media_users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_stream_limit_violations_server` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed tenant defaults in settings (idempotent)
INSERT INTO `settings` (`tenant_id`, `group`, `key`, `value`, `type`)
SELECT t.`id`, 'streams', 'enforcement_enabled', '0', 'boolean'
FROM `tenants` t
WHERE NOT EXISTS (
    SELECT 1 FROM `settings` s
    WHERE s.`tenant_id` = t.`id` AND s.`group` = 'streams' AND s.`key` = 'enforcement_enabled'
);

INSERT INTO `settings` (`tenant_id`, `group`, `key`, `value`, `type`)
SELECT t.`id`, 'streams', 'default_max_streams', '2', 'integer'
FROM `tenants` t
WHERE NOT EXISTS (
    SELECT 1 FROM `settings` s
    WHERE s.`tenant_id` = t.`id` AND s.`group` = 'streams' AND s.`key` = 'default_max_streams'
);

-- Si ya se sembró 1 en un deploy previo de esta migración, subir al default de producto (2)
UPDATE `settings`
SET `value` = '2'
WHERE `group` = 'streams' AND `key` = 'default_max_streams' AND `value` = '1';
