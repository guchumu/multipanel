<?php

declare(strict_types=1);

namespace Core;

/**
 * File-based realtime message broker for WebSocket/SSE/polling.
 */
final class RealtimeBroker
{
    private static function path(): string
    {
        $dir = storage_path('realtime');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/events.json';
    }

    public static function publish(string $channel, string $event, array $data): void
    {
        $messages = self::read();
        $messages[] = [
            'id' => uniqid('', true),
            'channel' => $channel,
            'event' => $event,
            'data' => $data,
            'at' => microtime(true),
        ];

        if (count($messages) > 500) {
            $messages = array_slice($messages, -500);
        }

        file_put_contents(self::path(), json_encode($messages), LOCK_EX);
    }

    /** @return list<array<string, mixed>> */
    public static function consume(string $channel, float $since = 0): array
    {
        return array_values(array_filter(
            self::read(),
            fn ($m) => $m['channel'] === $channel && ($m['at'] ?? 0) > $since
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function read(): array
    {
        $path = self::path();
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }
}
