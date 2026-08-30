<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\AlertSettingsService;
use Core\Logger;
use Core\Session;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * ntfy push notifications (https://ntfy.sh o servidor propio).
 */
final class NtfyChannel implements NotificationChannelInterface
{
    private Client $client;

    public function __construct(
        private AlertSettingsService $alerts = new AlertSettingsService(),
    ) {
        $this->client = new Client(['timeout' => 15]);
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        // Solo alertas admin; no mensajes a usuarios del portal.
        if (!empty($data['media_user_id']) || !empty($data['user_message']) || !empty($data['log_message'])) {
            return false;
        }

        $tenantId = isset($data['tenant_id'])
            ? (int) $data['tenant_id']
            : (int) (Session::getInstance()->get('tenant_id') ?? 1);

        if (!$this->alerts->ntfyConfigured($tenantId)) {
            Logger::warning('ntfy not configured', ['tenant_id' => $tenantId]);

            return false;
        }

        $url = $this->publishUrl($tenantId);
        if ($url === null) {
            return false;
        }

        $body = trim($message) !== '' ? $message : $title;
        $headers = [
            'Title' => mb_substr(trim($title), 0, 250),
            'Content-Type' => 'text/plain; charset=utf-8',
            'Priority' => $this->priorityForLevel((string) ($data['level'] ?? '')),
        ];

        $token = $this->alerts->ntfyToken($tenantId);
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $tags = $this->tagsForData($data);
        if ($tags !== '') {
            $headers['Tags'] = $tags;
        }

        try {
            $this->client->post($url, [
                'headers' => $headers,
                'body' => $body,
            ]);

            return true;
        } catch (GuzzleException $e) {
            Logger::error('ntfy notification failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getName(): string
    {
        return 'ntfy';
    }

    private function publishUrl(int $tenantId): ?string
    {
        $server = rtrim($this->alerts->ntfyServer($tenantId), '/');
        $topic = trim($this->alerts->ntfyTopic($tenantId));
        if ($server === '' || $topic === '' || !preg_match('#^https?://#i', $server)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $topic)) {
            return null;
        }

        return $server . '/' . rawurlencode($topic);
    }

    private function priorityForLevel(string $level): string
    {
        return match (strtolower($level)) {
            'error' => 'urgent',
            'warning' => 'high',
            default => 'default',
        };
    }

    /** @param array<string, mixed> $data */
    private function tagsForData(array $data): string
    {
        $tags = [];
        $kind = trim((string) ($data['whatsapp_kind'] ?? ''));
        if ($kind !== '') {
            $tags[] = $kind;
        }
        $event = trim((string) ($data['event'] ?? ''));
        if ($event !== '' && count($tags) < 3) {
            $tags[] = preg_replace('/[^a-z0-9_\-]/', '', strtolower(str_replace('.', '_', $event))) ?? '';
        }

        $tags = array_values(array_filter($tags, static fn (string $t): bool => $t !== ''));

        return implode(',', array_slice($tags, 0, 3));
    }
}
