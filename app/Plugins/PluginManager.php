<?php

declare(strict_types=1);

namespace App\Plugins;

use Core\Database;
use Core\Logger;

/**
 * Plugin loader and lifecycle manager.
 */
final class PluginManager
{
    /** @var array<string, Plugin> */
    private static array $loaded = [];

    public static function discover(): array
    {
        $plugins = [];
        $path = base_path('plugins');

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        foreach (glob($path . '/*/plugin.php') as $file) {
            $meta = require $file;
            if (is_array($meta)) {
                $plugins[] = $meta;
            }
        }

        return $plugins;
    }

    public static function loadActive(): void
    {
        try {
            $rows = Database::getInstance()->fetchAll('SELECT * FROM plugins WHERE is_active = 1');
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            self::load($row['slug']);
        }
    }

    public static function load(string $slug): ?Plugin
    {
        if (isset(self::$loaded[$slug])) {
            return self::$loaded[$slug];
        }

        $classFile = base_path("plugins/{$slug}/Plugin.php");
        if (!file_exists($classFile)) {
            return null;
        }

        require_once $classFile;

        $className = 'Plugins\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . '\\Plugin';

        if (!class_exists($className)) {
            // Try simple namespace
            $className = 'Plugins\\' . ucfirst($slug) . '\\Plugin';
        }

        if (!class_exists($className)) {
            Logger::warning("Plugin class not found: {$className}");
            return null;
        }

        $instance = new $className();
        if ($instance instanceof Plugin) {
            $instance->registerHooks();
            $instance->boot();
            self::$loaded[$slug] = $instance;
            Logger::info("Plugin loaded: {$slug}");
            return $instance;
        }

        return null;
    }

    public static function activate(string $slug): bool
    {
        $plugin = self::load($slug);
        if (!$plugin) {
            return false;
        }

        Database::getInstance()->query(
            'UPDATE plugins SET is_active = 1, installed_at = NOW() WHERE slug = ?',
            [$slug]
        );

        return true;
    }

    public static function deactivate(string $slug): void
    {
        Database::getInstance()->query('UPDATE plugins SET is_active = 0 WHERE slug = ?', [$slug]);
        unset(self::$loaded[$slug]);
    }

    /** @return array<string, Plugin> */
    public static function getLoaded(): array
    {
        return self::$loaded;
    }
}
