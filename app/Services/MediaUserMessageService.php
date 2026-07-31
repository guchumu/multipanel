<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Stores outbound messages sent to media users (Telegram, etc.).
 */
final class MediaUserMessageService
{
    public function log(
        ?int $mediaUserId,
        string $messageType,
        string $body,
        ?string $title = null,
        ?string $telegramChatId = null,
        string $channel = 'telegram',
        bool $sent = true,
    ): int {
        return Database::getInstance()->insert('media_user_messages', [
            'media_user_id' => $mediaUserId,
            'channel' => $channel,
            'message_type' => $messageType,
            'title' => $title,
            'body' => $body,
            'telegram_chat_id' => $telegramChatId,
            'status' => $sent ? 'sent' : 'failed',
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $mediaUserId, int $limit = 50): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM media_user_messages WHERE media_user_id = ? ORDER BY sent_at DESC LIMIT ?',
            [$mediaUserId, $limit]
        );
    }
}
