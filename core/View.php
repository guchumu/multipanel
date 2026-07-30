<?php

declare(strict_types=1);

namespace Core;

/**
 * Simple PHP template renderer.
 */
final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $name, array $data = []): string
    {
        $path = base_path('resources/views/' . str_replace('.', '/', $name) . '.php');

        if (!file_exists($path)) {
            throw new \RuntimeException("View not found: {$name}");
        }

        $data = array_merge(self::$shared, $data);
        extract($data, EXTR_SKIP);

        ob_start();
        include $path;
        return (string) ob_get_clean();
    }

    public static function component(string $name, array $data = []): string
    {
        return self::render('components/' . $name, $data);
    }
}
