-- Predefined messages shown when stopping/pausing playback in En directo
CREATE TABLE IF NOT EXISTS `playback_stop_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `body` TEXT NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_playback_stop_messages_tenant` (`tenant_id`),
    KEY `idx_playback_stop_messages_default` (`tenant_id`, `is_default`),
    CONSTRAINT `fk_playback_stop_messages_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default most-used message for tenant 1 (idempotent)
INSERT INTO `playback_stop_messages` (`tenant_id`, `title`, `body`, `is_default`, `sort_order`)
SELECT 1,
       'Configuración mal configurada',
       'Ajustes de configuración mal configurados. Para evitar cortes revise la configuración obligatoria o contacte con soporte.',
       1,
       0
WHERE EXISTS (SELECT 1 FROM `tenants` WHERE `id` = 1)
  AND NOT EXISTS (
      SELECT 1 FROM `playback_stop_messages` WHERE `tenant_id` = 1
  );
