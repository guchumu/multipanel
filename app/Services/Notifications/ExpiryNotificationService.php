<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\MediaUser;
use Core\Database;
use Core\Logger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Sends Telegram reminders before/after media user expiry.
 */
final class ExpiryNotificationService
{
    public function __construct(
        private TelegramChannel $telegram = new TelegramChannel(),
    ) {
    }

    /** @return array{sent: int, skipped: int, errors: int, checked: int} */
    public function run(int $tenantId = 1): array
    {
        if (!config('expiry_notifications.enabled', true)) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => 0, 'checked' => 0];
        }

        $milestones = config('expiry_notifications.milestones', [10, 5, 3, 2, 1, 0, -1]);
        $messages = config('expiry_notifications.messages', []);
        $title = (string) config('expiry_notifications.title', 'Aviso de caducidad');
        $tz = new DateTimeZone((string) config('app.timezone', 'Europe/Madrid'));
        $today = new DateTimeImmutable('today', $tz);

        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0, 'checked' => 0];

        $rows = Database::getInstance()->fetchAll(
            'SELECT mu.*, s.name AS server_name
             FROM media_users mu
             LEFT JOIN servers s ON s.id = mu.server_id AND s.deleted_at IS NULL
             WHERE mu.tenant_id = ?
               AND mu.deleted_at IS NULL
               AND mu.expires_at IS NOT NULL
               AND mu.status IN (\'active\', \'invited\')',
            [$tenantId]
        );

        foreach ($rows as $row) {
            $stats['checked']++;
            $user = new MediaUser($row);
            $expiresAt = trim((string) ($user->expires_at ?? ''));
            if ($expiresAt === '') {
                continue;
            }

            $expiresDate = new DateTimeImmutable(substr($expiresAt, 0, 10), $tz);
            $daysLeft = (int) floor(($expiresDate->getTimestamp() - $today->getTimestamp()) / 86400);

            if (!in_array($daysLeft, $milestones, true)) {
                continue;
            }

            $milestoneKey = (string) $daysLeft;
            if ($this->alreadySent((int) $user->id, $milestoneKey)) {
                continue;
            }

            $chatId = trim((string) ($user->telegram_chat_id ?? ''));
            if ($chatId === '') {
                $stats['skipped']++;
                Logger::debug('Expiry notice skipped: no Telegram chat ID', [
                    'media_user_id' => $user->id,
                    'username' => $user->username,
                    'milestone' => $milestoneKey,
                ]);
                continue;
            }

            $template = $messages[$daysLeft] ?? $messages[$milestoneKey] ?? null;
            if (!is_string($template) || trim($template) === '') {
                $stats['skipped']++;
                continue;
            }

            $body = $this->renderTemplate($template, $user, (string) ($row['server_name'] ?? ''), $daysLeft);
            $sent = $this->telegram->send($title, $body, ['chat_id' => $chatId]);

            if ($sent) {
                $this->recordSent((int) $user->id, $milestoneKey);
                $stats['sent']++;
                Logger::info('Expiry notice sent', [
                    'media_user_id' => $user->id,
                    'milestone' => $milestoneKey,
                ]);
            } else {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    private function alreadySent(int mediaUserId, string $milestone): bool
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT id FROM media_user_expiry_notices WHERE media_user_id = ? AND milestone = ? LIMIT 1',
            [$mediaUserId, $milestone]
        );

        return $row !== null;
    }

    private function recordSent(int mediaUserId, string $milestone): void
    {
        Database::getInstance()->insert('media_user_expiry_notices', [
            'media_user_id' => $mediaUserId,
            'milestone' => $milestone,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function renderTemplate(string $template, MediaUser $user, string $serverName, int $daysLeft): string
    {
        $expiresAt = (string) ($user->expires_at ?? '');
        $expiresDate = $expiresAt !== '' ? substr($expiresAt, 0, 10) : '';

        $replace = [
            '{username}' => (string) $user->username,
            '{email}' => (string) ($user->email ?? ''),
            '{display_name}' => (string) ($user->display_name ?: $user->username),
            '{expires_at}' => $expiresAt,
            '{expires_date}' => $expiresDate,
            '{days_left}' => (string) $daysLeft,
            '{server_name}' => $serverName !== '' ? $serverName : '—',
        ];

        return str_replace(array_keys($replace), array_values($replace), $template);
    }
}
