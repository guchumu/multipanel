<?php

declare(strict_types=1);

namespace App\Services\Notifications;

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
    ) {
        $this->botToken ??= config('telegram.bot_token', env('TELEGRAM_BOT_TOKEN'));
        $this->chatId ??= config('telegram.chat_id', env('TELEGRAM_CHAT_ID'));
        $this->client = new Client(['timeout' => 15]);
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        if (!$this->botToken || !$this->chatId) {
            Logger::warning('Telegram not configured');
            return false;
        }

        $chatId = $data['chat_id'] ?? $this->chatId;
        $text = "*{$title}*\n\n{$message}";

        if (isset($data['buttons']) && is_array($data['buttons'])) {
            return $this->sendWithKeyboard($chatId, $text, $data['buttons']);
        }

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
