<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\MediaUserMessageService;
use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Telegram bot notification channel.
 */
final class TelegramChannel implements NotificationChannelInterface
{
    private Client $client;

    public function __construct(
        private ?string $botToken = null,
        private ?string $chatId = null,
        private MediaUserMessageService $messageLog = new MediaUserMessageService(),
    ) {
        $this->botToken ??= config('telegram.bot_token', env('TELEGRAM_BOT_TOKEN'));
        $this->chatId ??= config('telegram.chat_id', env('TELEGRAM_CHAT_ID'));
        $this->client = new Client(['timeout' => 15]);
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        $chatId = $data['chat_id'] ?? $this->chatId;

        if (!$this->botToken || !$chatId) {
            Logger::warning('Telegram not configured');
            return false;
        }

        $text = "*{$title}*\n\n{$message}";

        if (isset($data['buttons']) && is_array($data['buttons'])) {
            $sent = $this->sendWithKeyboard((string) $chatId, $text, $data['buttons']);
        } else {
            $sent = $this->sendPlain((string) $chatId, $text);
        }

        if (!empty($data['log_message']) || !empty($data['media_user_id'])) {
            $this->messageLog->log(
                isset($data['media_user_id']) ? (int) $data['media_user_id'] : null,
                (string) ($data['message_type'] ?? 'telegram'),
                $message,
                $title,
                (string) $chatId,
                'telegram',
                $sent
            );
        }

        return $sent;
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
