-- Enlaces de corte múltiple (Cortar todas) desde avisos admin
ALTER TABLE `session_kill_links`
    ADD COLUMN `batch_sessions` JSON NULL AFTER `session_id`;
