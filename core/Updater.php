<?php

declare(strict_types=1);

namespace Core;

/**
 * Application updater with migration runner.
 *
 * Scans database/migrations/*.sql, tracks applied files in `migrations`,
 * and applies pending (or stale/incomplete) migrations in order.
 */
final class Updater
{
    private string $migrationsPath;

    /**
     * Integrity checks used to detect migrations that were recorded as
     * executed but never actually applied (e.g. SQL skipped due to header comments).
     *
     * @var array<string, array{table?: string, column?: array{0: string, 1: string}}>
     */
    private const INTEGRITY_CHECKS = [
        '002_integrations_payments.sql' => ['table' => 'integrations'],
        '004_oauth_events.sql' => ['table' => 'oauth_accounts'],
        '005_crm_webhooks_gdpr.sql' => ['table' => 'webhook_endpoints'],
        '006_expiry_notifications.sql' => ['column' => ['media_users', 'telegram_chat_id']],
        '007_payments_history.sql' => ['table' => 'payments_history'],
        '008_user_messages_and_registro.sql' => ['table' => 'media_user_messages'],
        '009_server_is_default.sql' => ['column' => ['servers', 'is_default']],
        '010_media_user_server_membership.sql' => ['column' => ['media_users', 'on_server']],
        '011_media_user_jellyfin_password.sql' => ['column' => ['media_users', 'jellyfin_password_encrypted']],
    ];

    public function __construct()
    {
        $this->migrationsPath = base_path('database/migrations');
    }

    public function getCurrentVersion(): string
    {
        return config('app.version', '1.0.0');
    }

    /** @return array<int, string> */
    public function getPendingMigrations(): array
    {
        $this->ensureMigrationsTable();
        $this->releaseStaleMigrationMarks();

        $executed = $this->getExecutedMigrations();
        $pending = [];

        foreach (glob($this->migrationsPath . '/*.sql') ?: [] as $file) {
            $name = basename($file);
            if (!in_array($name, $executed, true)) {
                $pending[] = $name;
            }
        }

        sort($pending);
        return $pending;
    }

    /** @return array<string, string> */
    public function runMigrations(): array
    {
        $this->ensureMigrationsTable();
        $this->releaseStaleMigrationMarks();

        $results = [];
        $batch = $this->getNextBatch();

        foreach ($this->getPendingMigrations() as $migration) {
            $path = $this->migrationsPath . '/' . $migration;
            $sql = file_get_contents($path);

            if ($sql === false || trim($sql) === '') {
                continue;
            }

            try {
                $this->execMigrationSql($sql);
                Database::getInstance()->insert('migrations', [
                    'migration' => $migration,
                    'batch' => $batch,
                ]);
                $results[$migration] = 'ok';
                Logger::info("Migration executed: {$migration}");
            } catch (\Throwable $e) {
                $results[$migration] = 'error: ' . $e->getMessage();
                Logger::error("Migration failed: {$migration}", ['error' => $e->getMessage()]);
                break;
            }
        }

        return $results;
    }

    public function checkForUpdates(): array
    {
        $pending = $this->getPendingMigrations();

        return [
            'current_version' => $this->getCurrentVersion(),
            'pending_migrations' => count($pending),
            'php_version' => PHP_VERSION,
            'needs_update' => count($pending) > 0,
        ];
    }

    public function ensureMigrationsTable(): void
    {
        try {
            Database::getInstance()->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `migrations` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `migration` VARCHAR(255) NOT NULL,
                    `batch` INT NOT NULL,
                    `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_migrations_name` (`migration`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable $e) {
            Logger::warning('Could not ensure migrations table', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Split a migration SQL file into executable statements, stripping comments.
     * Exposed for unit tests.
     *
     * @return array<int, string>
     */
    public static function parseMigrationStatements(string $sql): array
    {
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $chunks = preg_split('/;\s*(?:\n|$)/', $sql) ?: [];
        $statements = [];

        foreach ($chunks as $chunk) {
            $lines = preg_split('/\R/', $chunk) ?: [];
            $kept = [];

            foreach ($lines as $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }
                $kept[] = $line;
            }

            $statement = trim(implode("\n", $kept));
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function execMigrationSql(string $sql): void
    {
        foreach (self::parseMigrationStatements($sql) as $statement) {
            try {
                Database::getInstance()->pdo()->exec($statement);
            } catch (\Throwable $e) {
                if ($this->isIgnorableMigrationError($e->getMessage())) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function isIgnorableMigrationError(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'duplicate column')
            || str_contains($message, 'already exists')
            || str_contains($message, 'duplicate key name')
            || str_contains($message, 'duplicate entry');
    }

    /**
     * If a migration was marked executed but its schema object is missing,
     * clear the mark so the next run reapplies it (idempotent SQL).
     */
    private function releaseStaleMigrationMarks(): void
    {
        try {
            $executed = $this->getExecutedMigrations();
            if ($executed === []) {
                return;
            }

            $db = Database::getInstance();

            foreach (self::INTEGRITY_CHECKS as $migration => $check) {
                if (!in_array($migration, $executed, true)) {
                    continue;
                }

                if ($this->passesIntegrityCheck($check)) {
                    continue;
                }

                $db->query('DELETE FROM migrations WHERE migration = ?', [$migration]);
                Logger::warning("Released stale migration mark (schema missing): {$migration}");
            }
        } catch (\Throwable $e) {
            Logger::warning('Could not release stale migration marks', ['error' => $e->getMessage()]);
        }
    }

    /** @param array{table?: string, column?: array{0: string, 1: string}} $check */
    private function passesIntegrityCheck(array $check): bool
    {
        try {
            $db = Database::getInstance();

            if (isset($check['table'])) {
                $row = $db->fetchOne(
                    'SELECT COUNT(*) AS total
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?',
                    [$check['table']]
                );

                return ((int) ($row['total'] ?? 0)) > 0;
            }

            if (isset($check['column'])) {
                [$table, $column] = $check['column'];
                $row = $db->fetchOne(
                    'SELECT COUNT(*) AS total
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                       AND COLUMN_NAME = ?',
                    [$table, $column]
                );

                return ((int) ($row['total'] ?? 0)) > 0;
            }
        } catch (\Throwable) {
            return true; // Avoid infinite re-run loops if information_schema is unavailable.
        }

        return true;
    }

    /** @return array<int, string> */
    private function getExecutedMigrations(): array
    {
        try {
            $rows = Database::getInstance()->fetchAll('SELECT migration FROM migrations ORDER BY id');
            return array_column($rows, 'migration');
        } catch (\Throwable) {
            return [];
        }
    }

    private function getNextBatch(): int
    {
        try {
            $row = Database::getInstance()->fetchOne('SELECT MAX(batch) as max_batch FROM migrations');
            return ((int) ($row['max_batch'] ?? 0)) + 1;
        } catch (\Throwable) {
            return 1;
        }
    }
}
