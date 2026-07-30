<?php

declare(strict_types=1);

namespace Core;

/**
 * Simple i18n translation loader.
 */
final class Language
{
    private static string $locale = 'es';

    /** @var array<string, array<string, string>> */
    private static array $translations = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
        self::$translations = [];
    }

    public static function get(string $key, array $replace = []): string
    {
        $translations = self::load();
        $text = $translations[$key] ?? $key;

        foreach ($replace as $search => $value) {
            $text = str_replace(':' . $search, (string) $value, $text);
        }

        return $text;
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    /** @return array<string, string> */
    private static function load(): array
    {
        if (isset(self::$translations[self::$locale])) {
            return self::$translations[self::$locale];
        }

        $path = base_path('resources/lang/' . self::$locale . '.php');
        self::$translations[self::$locale] = file_exists($path) ? require $path : [];

        return self::$translations[self::$locale];
    }
}
