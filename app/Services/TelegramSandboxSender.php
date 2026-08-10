<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Envía mensajes de prueba SIEMPRE al Sandbox Chat ID (aunque el modo sandbox esté off).
 */
final class TelegramSandboxSender
{
    public function __construct(
        private ?Client $client = null,
    ) {
        $this->client ??= new Client(['timeout' => 15]);
    }

    /**
     * @return array{ok: bool, message: string, chat_id: string}
     */
    public function sendToSandbox(int $tenantId, string $text, ?string $parseMode = null): array
    {
        $cfg = TelegramConfig::forTenant($tenantId);
        $botToken = $cfg['bot_token'];
        $chatId = $cfg['sandbox_chat_id'];

        if ($botToken === '') {
            return [
                'ok' => false,
                'message' => 'Configura el Bot Token de Telegram en Configuración → Telegram antes de probar.',
                'chat_id' => '',
            ];
        }

        if ($chatId === '') {
            return [
                'ok' => false,
                'message' => 'Configura el Sandbox Chat ID en Configuración → Telegram (o TELEGRAM_SANDBOX_CHAT_ID en .env). Las pruebas van siempre al sandbox.',
                'chat_id' => '',
            ];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $this->client->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'json' => $payload,
            ]);

            return [
                'ok' => true,
                'message' => 'Mensaje de prueba enviado al sandbox (' . $chatId . '). Revisa tu chat de Telegram.',
                'chat_id' => $chatId,
            ];
        } catch (GuzzleException $e) {
            return [
                'ok' => false,
                'message' => 'Telegram: ' . self::formatApiError($e),
                'chat_id' => $chatId,
            ];
        }
    }

    /**
     * Placeholders de ejemplo para previsualizar plantillas de caducidad.
     *
     * @return array<string, string>
     */
    public static function samplePlaceholders(int $daysLeft): array
    {
        $expires = (new DateTimeImmutable('today'))->modify(sprintf('%+d days', $daysLeft));
        $expiresAt = $expires->format('Y-m-d') . ' 23:59:59';
        $expiresDate = $expires->format('Y-m-d');
        $endDate = $expires->format('d/m/Y');

        return [
            '{username}' => 'usuario.demo',
            '{email}' => 'demo@ejemplo.com',
            '{display_name}' => 'Usuario Demo',
            '{expires_at}' => $expiresAt,
            '{expires_date}' => $expiresDate,
            '{end_date}' => $endDate,
            '{days}' => (string) abs($daysLeft),
            '{days_left}' => (string) $daysLeft,
            '{server_name}' => 'Servidor Demo',
        ];
    }

    public static function renderWithSamples(string $template, int $daysLeft): string
    {
        $replace = self::samplePlaceholders($daysLeft);

        return str_replace(array_keys($replace), array_values($replace), $template);
    }

    public static function formatApiError(\Throwable $e): string
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = (string) $e->getResponse()->getBody();
            $json = json_decode($body, true);
            if (is_array($json)) {
                $description = isset($json['description']) ? trim((string) $json['description']) : '';
                $code = isset($json['error_code']) ? (int) $json['error_code'] : 0;
                if ($description !== '') {
                    return ($code > 0 ? "[{$code}] " : '') . $description;
                }
            }
            if ($body !== '') {
                return substr($body, 0, 300);
            }
        }

        return $e->getMessage();
    }
}
