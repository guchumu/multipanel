<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * In-memory event hub for real-time SSE broadcasting.
 */
final class EventHub
{
    /** @var list<array{event: string, data: array<string, mixed>, at: string}> */
    private static array $recent = [];

    public static function push(string $event, array $data): void
    {
        self::$recent[] = [
            'event' => $event,
            'data' => $data,
            'at' => date('c'),
        ];

        if (count(self::$recent) > 100) {
            self::$recent = array_slice(self::$recent, -100);
        }

        \Core\RealtimeBroker::publish('dashboard', $event, $data);
    }

    /** @return list<array{event: string, data: array<string, mixed>, at: string}> */
    public static function since(?string $timestamp): array
    {
        if ($timestamp === null) {
            return self::$recent;
        }

        return array_values(array_filter(
            self::$recent,
            fn ($e) => ($e['at'] ?? '') > $timestamp
        ));
    }

    public static function snapshot(int $tenantId): array
    {
        $db = Database::getInstance();
        return [
            'jobs_pending' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM jobs WHERE status = 'pending'")['c'] ?? 0),
            'tickets_open' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM tickets WHERE status IN ('open','pending')")['c'] ?? 0),
            'notifications_unread' => (int) ($db->fetchOne("SELECT COUNT(*) as c FROM notifications WHERE status = 'pending'")['c'] ?? 0),
            'recent_events' => array_slice(self::$recent, -10),
        ];
    }
}
