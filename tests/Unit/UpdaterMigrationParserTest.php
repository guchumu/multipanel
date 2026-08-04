<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Updater;
use PHPUnit\Framework\TestCase;

final class UpdaterMigrationParserTest extends TestCase
{
    public function test_strips_leading_sql_comments_and_keeps_create_table(): void
    {
        $sql = <<<'SQL'
-- Message history per media user (Telegram, etc.)
CREATE TABLE IF NOT EXISTS `media_user_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

        $statements = Updater::parseMigrationStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `media_user_messages`', $statements[0]);
        $this->assertStringNotContainsString('-- Message history', $statements[0]);
    }

    public function test_keeps_alter_after_header_comment(): void
    {
        $sql = <<<'SQL'
-- Default server star
ALTER TABLE `servers`
    ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `settings`;
SQL;

        $statements = Updater::parseMigrationStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertStringStartsWith('ALTER TABLE', $statements[0]);
    }

    public function test_handles_multiple_statements_and_block_comments(): void
    {
        $sql = <<<'SQL'
/* block */
CREATE TABLE IF NOT EXISTS `a` (`id` INT);
-- mid
CREATE TABLE IF NOT EXISTS `b` (`id` INT);
SQL;

        $statements = Updater::parseMigrationStatements($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('`a`', $statements[0]);
        $this->assertStringContainsString('`b`', $statements[1]);
    }
}
