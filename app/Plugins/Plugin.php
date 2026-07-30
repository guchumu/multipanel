<?php

declare(strict_types=1);

namespace App\Plugins;

/**
 * Base class for MultiPanel plugins.
 */
abstract class Plugin
{
    abstract public function getName(): string;

    abstract public function getSlug(): string;

    abstract public function getVersion(): string;

    public function getDescription(): string
    {
        return '';
    }

    public function boot(): void
    {
    }

    /** Register event hooks. Override in plugins. */
    public function registerHooks(): void
    {
    }

    public function registerRoutes(callable $registrar): void
    {
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return [];
    }
}
