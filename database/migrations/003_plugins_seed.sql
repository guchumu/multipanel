-- Register plugin and integration migration marker
INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES ('002_integrations_payments.sql', 1);
