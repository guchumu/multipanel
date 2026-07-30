<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Discord webhook notification channel.
 */
final class DiscordChannel implements NotificationChannelInterface
{
    private Client $client;

    public function __construct(
        private ?string $webhookUrl = null,
    ) {
        $this->webhookUrl ??= config('discord.webhook_url', '');
        $this->client = new Client(['timeout' => 15]);
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        if (!$this->webhookUrl) {
            return false;
        }

        $color = match ($data['level'] ?? 'info') {
            'error', 'critical' => 0xDC3545,
            'warning' => 0xFFC107,
            'success' => 0x198754,
            default => 0x0D6EFD,
        };

        try {
            $this->client->post($this->webhookUrl, [
                'json' => [
                    'embeds' => [[
                        'title' => $title,
                        'description' => $message,
                        'color' => $color,
                        'timestamp' => date('c'),
                        'footer' => ['text' => config('app.name', 'MultiPanel')],
                    ]],
                ],
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Discord notification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getName(): string
    {
        return 'discord';
    }
}
