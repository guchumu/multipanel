<?php

declare(strict_types=1);

namespace Core;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;
use Monolog\Level;

/**
 * Application logger wrapper.
 */
final class Logger
{
    private static ?MonologLogger $instance = null;

    public static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = new MonologLogger('multipanel');
            $path = storage_path('logs/multipanel.log');
            $level = match (config('logging.level', 'info')) {
                'debug' => Level::Debug,
                'warning' => Level::Warning,
                'error' => Level::Error,
                default => Level::Info,
            };
            self::$instance->pushHandler(new RotatingFileHandler($path, 14, $level));
        }

        return self::$instance;
    }

    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }
}
