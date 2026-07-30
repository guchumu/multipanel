<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Generic HTTP webhook notification channel.
 */
final class WebhookChannel implements NotificationChannelInterface
{
    private Client $client;

    public function __construct(
        private ?string $url = null,
        private ?string $secret = null,
    ) {
        $this->client = new Client(['timeout' => 15]);
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        $url = $data['url'] ?? $this->url;
        if (!$url) {
            return false;
        }

        $payload = [
            'event' => $data['event'] ?? 'notification',
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c'),
        ];

        $headers = ['Content-Type' => 'application/json'];
        if ($this->secret) {
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($payload), $this->secret);
        }

        try {
            $this->client->post($url, ['json' => $payload, 'headers' => $headers]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Webhook notification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getName(): string
    {
        return 'webhook';
    }
}
