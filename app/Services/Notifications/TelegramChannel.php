<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\MediaUserMessageService;
use App\Services\TelegramConfig;
use Core\Logger;
use Core\Session;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Telegram bot notification channel.
 */
final class TelegramChannel implements NotificationChannelInterface
{
    private Client $client;

    private string $botToken;

    private string $adminChatId;

    public function __construct(
        private ?string $botTokenOverride = null,
        private ?string $chatIdOverride = null,
        private MediaUserMessageService $messageLog = new MediaUserMessageService(),
    ) {
        $tenantId = (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $cfg = TelegramConfig::forTenant($tenantId);
        $this->botToken = trim((string) ($botTokenOverride ?: $cfg['bot_token']));
        $this->adminChatId = trim((string) ($chatIdOverride ?: $cfg['admin_chat_id']));
        $this->client = new Client(['timeout' => 15]);
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        $tenantId = isset($data['tenant_id']) ? (int) $data['tenant_id'] : (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $intended = trim((string) ($data['chat_id'] ?? $this->adminChatId));
        $isUserMessage = !empty($data['media_user_id']) || !empty($data['log_message']) || !empty($data['user_message']);

        // Mensajes a usuarios: respetan sandbox. Alertas admin usan chat admin directo.
        $targets = $isUserMessage
            ? TelegramConfig::resolveOutboundChatIds($intended, $tenantId)
            : ($intended !== '' ? [$intended] : []);

        if ($this->botToken === '' || $targets === []) {
            Logger::warning('Telegram not configured');
            return false;
        }

        $cfg = TelegramConfig::forTenant($tenantId);
        $text = "*{$title}*\n\n{$message}";
        if ($isUserMessage && $cfg['sandbox_enabled'] && $cfg['sandbox_chat_id'] !== '') {
            $text .= "\n\n_🧪 Sandbox → destino real: " . ($intended !== '' ? $intended : '—') . '_';
        }

        $anySent = false;
        foreach ($targets as $chatId) {
            if (isset($data['buttons']) && is_array($data['buttons'])) {
                $sent = $this->sendWithKeyboard((string) $chatId, $text, $data['buttons']);
            } else {
                $sent = $this->sendPlain((string) $chatId, $text);
            }
            $anySent = $anySent || $sent;
        }

        if (!empty($data['log_message']) || !empty($data['media_user_id'])) {
            $this->messageLog->log(
                isset($data['media_user_id']) ? (int) $data['media_user_id'] : null,
                (string) ($data['message_type'] ?? 'telegram'),
                $message,
                $title,
                (string) ($targets[0] ?? $intended),
                'telegram',
                $anySent
            );
        }

        return $anySent;
    }

    private function sendPlain(string $chatId, string $text): bool
    {
        try {
            $this->client->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                ],
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Telegram notification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** @param array<int, array{text: string, callback_data: string}> $buttons */
    private function sendWithKeyboard(string $chatId, string $text, array $buttons): bool
    {
        $keyboard = array_map(fn ($btn) => [['text' => $btn['text'], 'callback_data' => $btn['callback_data']]], $buttons);

        try {
            $this->client->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => ['inline_keyboard' => $keyboard],
                ],
            ]);
            return true;
        } catch (GuzzleException $e) {
            Logger::error('Telegram keyboard message failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getName(): string
    {
        return 'telegram';
    }
}
