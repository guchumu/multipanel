-- Sample excerpt from plex_manager phpMyAdmin dump
INSERT INTO `servers` (`id`, `server_name`, `local_ip`, `public_ip`, `admin_user`, `admin_pass`, `token`, `machine_id`, `is_default`, `server_version`, `last_connection_test`, `created_at`, `updated_at`) VALUES
(1, 'Nucbox', '192.168.1.100:32400', 'http://lunasea.mooo.com:32500', 'admin@test.com', 'secret1', 'tok1', 'abc111', 1, NULL, NULL, '2025-09-08 14:45:31', NULL),
(2, 'Servitron', '192.168.1.147:32400', 'http://lunasea.mooo.com:32400', 'admin2@test.com', 'secret2', 'tok2', 'abc222', 0, NULL, NULL, '2025-09-08 14:46:14', NULL);

-- --------------------------------------------------------

INSERT INTO `users` (`id`, `server_id`, `email`, `email_type`, `telegram_id`, `telegram_chat_id`, `plex_user_id`, `plex_username`, `start_date`, `end_date`, `private_notes`, `status`, `last_sync_date`, `created_at`, `updated_at`) VALUES
(1, 1, 'guchumu@gmail.com', 'real', NULL, '2023182976', '1306781', 'guchumu', '2025-09-08', NULL, '', 'active', '2026-07-25 15:34:29', '2025-09-08 14:45:32', '2026-07-25 15:34:29'),
(2, 1, 'other@test.com', 'real', NULL, '', '297519392', 'other', '2025-09-08', NULL, NULL, 'active', NULL, '2025-09-08 14:45:32', NULL);

-- --------------------------------------------------------
