<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\MediaUser;
use App\Services\AlertSettingsService;
use App\Services\MediaUserMessageService;
use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Avisos WhatsApp a clientes (no el CallMeBot del admin).
 *
 * 1) WhatsApp Cloud API (Meta) si el tenant tiene token + phone number id.
 * 2) CallMeBot por usuario si hay `whatsapp_apikey` en metadata (ficha).
 */
final class ClientWhatsAppChannel
{
    public function __construct(
        private AlertSettingsService $alerts = new AlertSettingsService(),
        private MediaUserMessageService $messageLog = new MediaUserMessageService(),
        private ?Client $http = null,
    ) {
        $this->http ??= new Client(['timeout' => 20, 'http_errors' => false]);
    }

    public function canSend(MediaUser $user, ?int $tenantId = null): bool
    {
        $tenantId ??= (int) ($user->tenant_id ?? 1);
        if (!$this->optedIn($user)) {
            return false;
        }

        $phone = $this->userPhone($user);
        if ($phone === '') {
            return false;
        }

        return $this->cloudConfigured($tenantId) || $this->userCallMeBotKey($user) !== '';
    }

    /**
     * @return array{sent: bool, message: string}
     */
    public function send(MediaUser $user, string $title, string $body, string $messageType = 'whatsapp'): array
    {
        $tenantId = (int) ($user->tenant_id ?? 1);
        if (!$this->optedIn($user)) {
            return ['sent' => false, 'message' => 'El usuario no quiere avisos por WhatsApp.'];
        }

        $phone = $this->userPhone($user);
        if ($phone === '') {
            return ['sent' => false, 'message' => 'El usuario no tiene WhatsApp guardado.'];
        }

        $text = trim($title . "\n\n" . $body);
        $sent = false;
        $error = 'WhatsApp no está configurado para clientes.';

        if ($this->cloudConfigured($tenantId)) {
            $result = $this->sendCloud($tenantId, $phone, $text);
            $sent = $result['sent'];
            $error = $result['message'];
        }

        if (!$sent) {
            $apikey = $this->userCallMeBotKey($user);
            if ($apikey !== '') {
                $result = $this->sendCallMeBot($phone, $apikey, $text);
                $sent = $result['sent'];
                $error = $result['message'];
            }
        }

        $this->messageLog->log(
            (int) $user->id,
            $messageType,
            $body,
            $title,
            $phone,
            'whatsapp',
            $sent
        );

        return ['sent' => $sent, 'message' => $sent ? 'WhatsApp enviado.' : $error];
    }

    public function userPhone(MediaUser $user): string
    {
        return self::normalizePhone((string) ($user->metaGet('whatsapp_phone') ?? ''));
    }

    public function optedIn(MediaUser $user): bool
    {
        $flag = $user->metaGet('whatsapp_opt_in', true);
        if (is_bool($flag)) {
            return $flag;
        }

        return !in_array(strtolower(trim((string) $flag)), ['0', 'false', 'no', 'off'], true);
    }

    public function cloudConfigured(?int $tenantId = null): bool
    {
        return $this->alerts->whatsappCloudConfigured($tenantId)
            && $this->alerts->whatsappClientAlertsEnabled($tenantId);
    }

    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Móvil España sin prefijo: 6xx/7xx de 9 dígitos.
        if (strlen($digits) === 9 && preg_match('/^[67]/', $digits)) {
            $digits = '34' . $digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return '';
        }

        return $digits;
    }

    private function userCallMeBotKey(MediaUser $user): string
    {
        return trim((string) ($user->metaGet('whatsapp_apikey') ?? ''));
    }

    /** @return array{sent: bool, message: string} */
    private function sendCloud(int $tenantId, string $phone, string $text): array
    {
        $phoneId = $this->alerts->whatsappCloudPhoneId($tenantId);
        $token = $this->alerts->whatsappCloudToken($tenantId);
        $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($phoneId) . '/messages';

        try {
            $response = $this->http->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => ['body' => $text, 'preview_url' => false],
                ],
            ]);
            $code = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $json = json_decode($raw, true);
            $ok = $code >= 200 && $code < 300 && is_array($json) && isset($json['messages']);
            if (!$ok) {
                $err = is_array($json) ? (string) (($json['error']['message'] ?? '') ?: $raw) : $raw;
                Logger::warning('WhatsApp Cloud API failed', [
                    'status' => $code,
                    'body' => mb_substr($err, 0, 240),
                ]);

                return ['sent' => false, 'message' => 'WhatsApp Business no aceptó el envío.'];
            }

            return ['sent' => true, 'message' => 'WhatsApp enviado.'];
        } catch (GuzzleException $e) {
            Logger::error('WhatsApp Cloud API error', ['error' => $e->getMessage()]);

            return ['sent' => false, 'message' => 'No se pudo contactar WhatsApp Business.'];
        }
    }

    /** @return array{sent: bool, message: string} */
    private function sendCallMeBot(string $phone, string $apikey, string $text): array
    {
        $apiUrl = (string) config('alerts.whatsapp_api_url', 'https://api.callmebot.com/whatsapp.php');

        try {
            $response = $this->http->get($apiUrl, [
                'query' => [
                    'phone' => ltrim($phone, '+'),
                    'text' => $text,
                    'apikey' => $apikey,
                ],
            ]);
            $code = $response->getStatusCode();
            $body = trim((string) $response->getBody());
            $ok = $code >= 200 && $code < 300 && !str_contains(strtolower($body), 'error');

            return [
                'sent' => $ok,
                'message' => $ok ? 'WhatsApp enviado.' : 'CallMeBot no aceptó el envío.',
            ];
        } catch (GuzzleException $e) {
            Logger::error('WhatsApp CallMeBot (cliente) failed', ['error' => $e->getMessage()]);

            return ['sent' => false, 'message' => 'No se pudo enviar por WhatsApp.'];
        }
    }
}
