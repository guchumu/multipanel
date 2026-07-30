<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Contract for notification delivery channels.
 */
interface NotificationChannelInterface
{
    public function send(string $title, string $message, array $data = []): bool;

    public function getName(): string;
}
