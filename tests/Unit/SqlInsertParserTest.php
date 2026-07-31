<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\SqlInsertParser;
use PHPUnit\Framework\TestCase;

final class SqlInsertParserTest extends TestCase
{
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
