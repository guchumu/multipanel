<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\AlertSettingsService;
use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * WhatsApp admin alerts via CallMeBot (optional free/cheap API).
 *
 * Docs: https://www.callmebot.com/blog/free-api-whatsapp-messages/
 * If phone/apikey are missing, send() returns false without error spam.
 */
final class WhatsAppChannel implements NotificationChannelInterface
{
    private Client $client;

    public function __construct(
        private AlertSettingsService $alerts = new AlertSettingsService(),
        private ?Client $http = null,
    ) {
        $this->client = $http ?? new Client(['timeout' => 20, 'http_errors' => false]);
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        $tenantId = isset($data['tenant_id']) ? (int) $data['tenant_id'] : null;

        if (!$this->alerts->whatsappConfigured($tenantId)) {
            Logger::debug('WhatsApp alert skipped: CallMeBot not configured');
            return false;
        }

        $phone = $this->alerts->whatsappPhone($tenantId);
        $apikey = $this->alerts->whatsappApiKey($tenantId);
        $text = trim($title . "\n\n" . $message);
        if ($text === '') {
            return false;
        }

        $apiUrl = (string) config('alerts.whatsapp_api_url', 'https://api.callmebot.com/whatsapp.php');

        try {
            $response = $this->client->get($apiUrl, [
                'query' => [
                    'phone' => ltrim($phone, '+'),
                    'text' => $text,
                    'apikey' => $apikey,
                ],
            ]);
            $code = $response->getStatusCode();
            $body = trim((string) $response->getBody());
            $ok = $code >= 200 && $code < 300 && !str_contains(strtolower($body), 'error');

            if (!$ok) {
                Logger::warning('WhatsApp CallMeBot failed', [
                    'status' => $code,
                    'body' => mb_substr($body, 0, 200),
                ]);
            }

            return $ok;
        } catch (GuzzleException $e) {
            Logger::error('WhatsApp notification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getName(): string
    {
        return 'whatsapp';
    }
}
