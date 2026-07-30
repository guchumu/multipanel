<?php

declare(strict_types=1);

namespace Core;

/**
 * Simple event/hook dispatcher for plugins and core hooks.
 */
final class EventDispatcher
{
    /** @var array<string, list<array{callable, int}>> */
    private static array $listeners = [];

    public static function listen(string $event, callable $listener, int $priority = 10): void
    {
        self::$listeners[$event][] = ['callable' => $listener, 'priority' => $priority];
        usort(self::$listeners[$event], fn ($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * @return mixed Last listener return value, or original payload if none
     */
    public static function dispatch(string $event, mixed $payload = null): mixed
    {
        foreach (self::$listeners[$event] ?? [] as $entry) {
            $payload = $entry['callable']($payload);
        }

        return $payload;
    }

    public static function hasListeners(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    public static function forget(string $event): void
    {
        unset(self::$listeners[$event]);
    }

    public static function flush(): void
    {
        self::$listeners = [];
    }
}
