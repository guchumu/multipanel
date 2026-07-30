<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;
use GuzzleHttp\Client;

/**
 * Outgoing webhook dispatcher for external integrations.
 */
final class WebhookService
{
    /** @var list<string> */
    public const EVENTS = [
        'user.created',
        'user.suspended',
        'user.activated',
        'subscription.created',
        'subscription.paid',
        'subscription.cancelled',
        'payment.completed',
        'server.offline',
        'server.synced',
        'backup.created',
    ];

    public function dispatch(string $event, array $payload, int $tenantId = 1): int
    {
        $endpoints = Database::getInstance()->fetchAll(
            'SELECT * FROM webhook_endpoints WHERE tenant_id = ? AND is_active = 1',
            [$tenantId]
        );

        $sent = 0;
        foreach ($endpoints as $endpoint) {
            $events = json_decode($endpoint['events'] ?? '[]', true) ?: [];
            if (!in_array($event, $events, true) && !in_array('*', $events, true)) {
                continue;
            }

            if ($this->deliver($endpoint, $event, $payload)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @param array<string, mixed> $endpoint */
    private function deliver(array $endpoint, string $event, array $payload): bool
    {
        $body = json_encode([
            'event' => $event,
            'timestamp' => date('c'),
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'MultiPanel-Webhook/1.0',
            'X-MultiPanel-Event' => $event,
        ];

        if (!empty($endpoint['secret'])) {
            $headers['X-MultiPanel-Signature'] = hash_hmac('sha256', (string) $body, $endpoint['secret']);
        }

        $deliveryId = Database::getInstance()->insert('webhook_deliveries', [
            'endpoint_id' => $endpoint['id'],
            'event' => $event,
            'payload' => $body,
            'status' => 'pending',
        ]);

        try {
            $client = new Client(['timeout' => 10, 'http_errors' => false]);
            $response = $client->post($endpoint['url'], ['headers' => $headers, 'body' => $body]);
            $code = $response->getStatusCode();
            $responseBody = (string) $response->getBody();
            $success = $code >= 200 && $code < 300;

            Database::getInstance()->update('webhook_deliveries', [
                'response_code' => $code,
                'response_body' => substr($responseBody, 0, 2000),
                'status' => $success ? 'success' : 'failed',
            ], 'id = ?', [$deliveryId]);

            Database::getInstance()->update('webhook_endpoints', [
                'last_triggered_at' => date('Y-m-d H:i:s'),
                'last_status' => $code,
            ], 'id = ?', [$endpoint['id']]);

            return $success;
        } catch (\Throwable $e) {
            Database::getInstance()->update('webhook_deliveries', [
                'status' => 'failed',
                'response_body' => $e->getMessage(),
            ], 'id = ?', [$deliveryId]);

            Logger::error('Webhook delivery failed', ['endpoint' => $endpoint['id'], 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listEndpoints(int $tenantId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM webhook_endpoints WHERE tenant_id = ? ORDER BY name',
            [$tenantId]
        );
    }

    public function createEndpoint(int $tenantId, array $data): int
    {
        return Database::getInstance()->insert('webhook_endpoints', [
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $data['secret'] ?? bin2hex(random_bytes(16)),
            'events' => json_encode($data['events'] ?? ['*']),
            'is_active' => 1,
        ]);
    }

    public function deleteEndpoint(int $id): void
    {
        Database::getInstance()->query('DELETE FROM webhook_endpoints WHERE id = ?', [$id]);
    }

    public function testEndpoint(int $id): bool
    {
        $endpoint = Database::getInstance()->fetchOne('SELECT * FROM webhook_endpoints WHERE id = ?', [$id]);
        if (!$endpoint) {
            return false;
        }

        return $this->deliver($endpoint, 'webhook.test', ['message' => 'Test from MultiPanel']);
    }
}
