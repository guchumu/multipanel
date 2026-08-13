<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\Database;
use Core\Logger;
use Core\Session;

/**
 * Central notification dispatcher with persistence.
 */
final class NotificationService
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];

    public function __construct()
    {
        $this->channels = [
            'email' => new EmailChannel(),
            'telegram' => new TelegramChannel(),
            'discord' => new DiscordChannel(),
            'webhook' => new WebhookChannel(),
            'whatsapp' => new WhatsAppChannel(),
        ];
    }

    /**
     * Dispatch notification to configured channels.
     *
     * @param array<int, string> $channels
     */
    public function notify(
        string $type,
        string $title,
        string $message,
        array $channels = ['telegram', 'email'],
        array $data = [],
        ?int $userId = null,
        ?int $tenantId = null,
    ): array {
        $results = [];
        $tenantId ??= Session::getInstance()->get('tenant_id', 1);

        foreach ($channels as $channelName) {
            $channel = $this->channels[$channelName] ?? null;
            if ($channel === null) {
                continue;
            }

            $sent = $channel->send($title, $message, array_merge($data, ['event' => $type]));
            $results[$channelName] = $sent;

            Database::getInstance()->insert('notifications', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => json_encode($data),
                'status' => $sent ? 'sent' : 'failed',
                'sent_at' => $sent ? date('Y-m-d H:i:s') : null,
            ]);
        }

        Logger::info("Notification dispatched: {$type}", $results);
        return $results;
    }

    public function notifyUserCreated(string $username, string $email): void
    {
        $this->notify(
            'user.created',
            'Nuevo usuario creado',
            "Usuario: {$username}\nEmail: {$email}",
            ['telegram', 'email']
        );
    }

    public function notifyServerDown(string $serverName): void
    {
        // Compat: alerta mínima. El cron de automatización usa ServerDownAlertService
        // (diagnóstico + email + WhatsApp + escalado 5/15/30).
        $this->notify(
            'server.down',
            'Servidor caído',
            "El servidor \"{$serverName}\" no responde.",
            ['telegram'],
            ['level' => 'error']
        );
    }

    public function notifyPaymentOverdue(string $customerEmail, int $daysOverdue): void
    {
        $this->notify(
            'payment.overdue',
            'Pago vencido',
            "Cliente {$customerEmail}: {$daysOverdue} días sin pagar.",
            ['telegram', 'email'],
            ['level' => 'warning']
        );
    }

    public function notifyLowDiskSpace(string $serverName, float $usagePercent): void
    {
        $this->notify(
            'server.disk_low',
            'Espacio bajo en disco',
            "Servidor {$serverName}: {$usagePercent}% utilizado.",
            ['telegram'],
            ['level' => 'warning']
        );
    }
}
