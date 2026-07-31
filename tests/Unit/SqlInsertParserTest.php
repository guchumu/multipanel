<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\SqlInsertParser;
use PHPUnit\Framework\TestCase;

final class SqlInsertParserTest extends TestCase
{
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
