<?php

declare(strict_types=1);

namespace Core;

/**
 * Application updater with migration runner.
 */
final class Updater
{
    private string $migrationsPath;

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
        return [
            'current_version' => $this->getCurrentVersion(),
            'pending_migrations' => count($this->getPendingMigrations()),
            'php_version' => PHP_VERSION,
            'needs_update' => count($this->getPendingMigrations()) > 0,
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

    private function execMigrationSql(string $sql): void
    {
        $statements = preg_split('/;\s*(?:\n|$)/', $sql) ?: [];

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }

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
            || str_contains($message, 'duplicate key name');
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
