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
        $executed = $this->getExecutedMigrations();
        $pending = [];

        foreach (glob($this->migrationsPath . '/*.sql') as $file) {
            $name = basename($file);
            if (!in_array($name, $executed, true)) {
                $pending[] = $name;
            }
        }

        sort($pending);
        return $pending;
    }

    public function runMigrations(): array
    {
        $results = [];
        $batch = $this->getNextBatch();

        foreach ($this->getPendingMigrations() as $migration) {
            $path = $this->migrationsPath . '/' . $migration;
            $sql = file_get_contents($path);

            if ($sql) {
                try {
                    Database::getInstance()->pdo()->exec($sql);
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
