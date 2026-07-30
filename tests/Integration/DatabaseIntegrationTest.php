<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Database;
use Tests\TestCase;

/**
 * Integration tests — skipped when database is unavailable.
 */
final class DatabaseIntegrationTest extends TestCase
{
    private static ?bool $dbAvailable = null;

    public static function setUpBeforeClass(): void
    {
        if (self::$dbAvailable === null) {
            try {
                Database::getInstance()->fetchOne('SELECT 1 as ok');
                self::$dbAvailable = true;
            } catch (\Throwable) {
                self::$dbAvailable = false;
            }
        }
    }

    private function requireDatabase(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('Database not available for integration tests.');
        }
    }

    public function testDatabaseConnection(): void
    {
        $this->requireDatabase();
        $row = Database::getInstance()->fetchOne('SELECT 1 as ok');
        $this->assertSame(1, (int) ($row['ok'] ?? 0));
    }

    public function testTenantsTableExists(): void
    {
        $this->requireDatabase();
        $row = Database::getInstance()->fetchOne('SELECT COUNT(*) as c FROM tenants');
        $this->assertGreaterThanOrEqual(0, (int) ($row['c'] ?? -1));
    }

    public function testPermissionsSeeded(): void
    {
        $this->requireDatabase();
        $row = Database::getInstance()->fetchOne('SELECT COUNT(*) as c FROM permissions');
        $this->assertGreaterThan(0, (int) ($row['c'] ?? 0));
    }
}
