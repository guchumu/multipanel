-- Límite de streams por IPs distintas + columna de IPs en incumplimientos
ALTER TABLE `stream_limit_violations`
    ADD COLUMN `client_ips` JSON NULL AFTER `titles`;

-- Modo de conteo por tenant (distinct_ip | sessions). Default: distinct_ip
INSERT INTO `settings` (`tenant_id`, `group`, `key`, `value`, `type`)
SELECT t.`id`, 'streams', 'count_mode', 'distinct_ip', 'string'
FROM `tenants` t
WHERE NOT EXISTS (
    SELECT 1 FROM `settings` s
    WHERE s.`tenant_id` = t.`id` AND s.`group` = 'streams' AND s.`key` = 'count_mode'
);
