<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\SqlInsertParser;
use PHPUnit\Framework\TestCase;

final class SqlInsertParserTest extends TestCase
{
    public function test_probe_detects_markers_in_fixture(): void
    {
        $sql = file_get_contents(base_path('tests/fixtures/plex_manager_sample.sql'));
        $this->assertNotFalse($sql);

        $probe = SqlInsertParser::probe($sql);
        $this->assertTrue($probe['has_servers_marker']);
        $this->assertTrue($probe['has_users_marker']);
        $this->assertSame('3.1', $probe['parser']);
    }

    public function test_extract_fixture_file(): void
    {
        $sql = file_get_contents(base_path('tests/fixtures/plex_manager_sample.sql'));
        $this->assertNotFalse($sql);

        $servers = SqlInsertParser::extractTable($sql, 'servers');
        $users = SqlInsertParser::extractTable($sql, 'users');

        $this->assertCount(2, $servers);
        $this->assertCount(2, $users);
        $this->assertSame('Nucbox', $servers[0]['server_name']);
        $this->assertSame('guchumu@gmail.com', $users[0]['email']);
    }

    public function test_extract_table_from_phpmyadmin_dump(): void
    {
        $sql = <<<'SQL'
INSERT INTO `servers` (`id`, `server_name`, `public_ip`, `token`, `machine_id`) VALUES
(1, 'Test', 'http://example.com:32400', 'tok123', 'abc');

-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL
);

INSERT INTO `users` (`id`, `server_id`, `email`, `status`) VALUES
(1, 1, 'user@test.com', 'active');

-- --------------------------------------------------------
SQL;

        $servers = SqlInsertParser::extractTable($sql, 'servers');
        $users = SqlInsertParser::extractTable($sql, 'users');

        $this->assertCount(1, $servers);
        $this->assertSame('Test', $servers[0]['server_name']);
        $this->assertCount(1, $users);
        $this->assertSame('user@test.com', $users[0]['email']);
    }

    public function test_extract_real_phpmyadmin_servers_block(): void
    {
        $sql = <<<'SQL'
INSERT INTO `servers` (`id`, `server_name`, `local_ip`, `public_ip`, `admin_user`, `admin_pass`, `token`, `machine_id`, `is_default`, `server_version`, `last_connection_test`, `created_at`, `updated_at`) VALUES
(1, 'Nucbox', '192.168.1.100:32400', 'http://lunasea.mooo.com:32500', 'admin@test.com', 'secret', 'tok1', 'abc111', 1, NULL, NULL, '2025-09-08 14:45:31', NULL),
(2, 'Servitron', '192.168.1.147:32400', 'http://lunasea.mooo.com:32400', 'admin2@test.com', 'secret2', 'tok2', 'abc222', 0, NULL, NULL, '2025-09-08 14:46:14', NULL);

-- --------------------------------------------------------
SQL;

        $servers = SqlInsertParser::extractTable($sql, 'servers');

        $this->assertCount(2, $servers);
        $this->assertSame('Nucbox', $servers[0]['server_name']);
        $this->assertSame('http://lunasea.mooo.com:32500', $servers[0]['public_ip']);
        $this->assertSame('Servitron', $servers[1]['server_name']);
    }

    public function test_extract_users_with_blank_lines_before_comment(): void
    {
        $sql = <<<'SQL'
INSERT INTO `users` (`id`, `server_id`, `email`, `email_type`, `telegram_id`, `telegram_chat_id`, `plex_user_id`, `plex_username`, `start_date`, `end_date`, `private_notes`, `status`, `last_sync_date`, `created_at`, `updated_at`) VALUES
(1, 1, 'guchumu@gmail.com', 'real', NULL, '2023182976', '1306781', 'guchumu', '2025-09-08', NULL, '', 'active', '2026-07-25 15:34:29', '2025-09-08 14:45:32', '2026-07-25 15:34:29'),
(2, 1, 'other@test.com', 'real', NULL, '', '297519392', 'other', '2025-09-08', NULL, NULL, 'active', NULL, '2025-09-08 14:45:32', NULL);

-- --------------------------------------------------------
SQL;

        $users = SqlInsertParser::extractTable($sql, 'users');

        $this->assertCount(2, $users);
        $this->assertSame('guchumu@gmail.com', $users[0]['email']);
        $this->assertSame('other@test.com', $users[1]['email']);
    }

    public function test_parse_row_matches_column_count_for_server_row(): void
    {
        $row = "1, 'Nucbox', '192.168.1.100:32400', 'http://lunasea.mooo.com:32500', 'admin@test.com', 'secret', 'tok1', 'abc111', 1, NULL, NULL, '2025-09-08 14:45:31', NULL";
        $values = SqlInsertParser::parseRow($row);

        $this->assertCount(13, $values);
        $this->assertSame(1, $values[0]);
        $this->assertSame('Nucbox', $values[1]);
        $this->assertSame('tok1', $values[6]);
        $this->assertNull($values[10]);
    }

    public function test_parse_row_with_nulls_and_strings(): void
    {
        $row = "1, 1, 'test@email.com', 'real', NULL, '2023182976', '1306781', 'testuser', '2025-09-08', NULL, '', 'active', NULL, '2025-09-08 14:45:32', NULL";
        $values = SqlInsertParser::parseRow($row);

        $this->assertSame(1, $values[0]);
        $this->assertSame('test@email.com', $values[2]);
        $this->assertNull($values[4]);
        $this->assertSame('2023182976', $values[5]);
        $this->assertNull($values[9]);
        $this->assertSame('active', $values[11]);
    }
}
