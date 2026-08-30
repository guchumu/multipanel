<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\AlertSettingsService;
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

    public function __construct(
        private AlertSettingsService $alerts = new AlertSettingsService(),
    ) {
        $this->channels = [
            'email' => new EmailChannel(),
            'telegram' => new TelegramChannel(),
            'discord' => new DiscordChannel(),
            'webhook' => new WebhookChannel(),
            'whatsapp' => new WhatsAppChannel($this->alerts),
            'ntfy' => new NtfyChannel($this->alerts),
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
        $tenantId ??= (int) (Session::getInstance()->get('tenant_id') ?? 1);

        foreach ($channels as $channelName) {
            $channel = $this->channels[$channelName] ?? null;
            if ($channel === null) {
                continue;
            }

            $sent = $channel->send($title, $message, array_merge($data, [
                'event' => $type,
                'tenant_id' => $tenantId,
            ]));
            $results[$channelName] = $sent;

            try {
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
            } catch (\Throwable) {
                // Tabla notifications puede no existir aún; no bloquear el envío.
            }
        }

        Logger::info("Notification dispatched: {$type}", $results);
        return $results;
    }

    /**
     * Canales admin para altas/renovaciones según toggles.
     * Alta: Telegram + WhatsApp ON por defecto. Renovación: Telegram ON, WhatsApp OFF.
     *
     * @param 'created'|'renewed' $event
     * @return array<int, string>
     */
    public function adminLifecycleChannels(string $event = 'created', ?int $tenantId = null): array
    {
        $channels = [];
        $wantTelegram = $event === 'renewed'
            ? $this->alerts->telegramNotifyRenew($tenantId)
            : $this->alerts->telegramNotifyAlta($tenantId);
        $wantWhatsApp = $event === 'renewed'
            ? $this->alerts->whatsappNotifyRenew($tenantId)
            : $this->alerts->whatsappNotifyAlta($tenantId);
        $wantNtfy = $event === 'renewed'
            ? $this->alerts->ntfyNotifyRenew($tenantId)
            : $this->alerts->ntfyNotifyAlta($tenantId);

        if ($wantTelegram) {
            $channels[] = 'telegram';
        }
        if ($wantWhatsApp && $this->alerts->whatsappConfigured($tenantId)) {
            $channels[] = 'whatsapp';
        }
        if ($wantNtfy && $this->alerts->ntfyConfigured($tenantId)) {
            $channels[] = 'ntfy';
        }

        return $channels;
    }

    /**
     * Canales del resumen diario admin.
     *
     * @return array<int, string>
     */
    public function adminDigestChannels(?int $tenantId = null): array
    {
        $channels = [];
        if ($this->alerts->telegramNotifyDigest($tenantId)) {
            $channels[] = 'telegram';
        }
        if ($this->alerts->whatsappNotifyDigest($tenantId) && $this->alerts->whatsappConfigured($tenantId)) {
            $channels[] = 'whatsapp';
        }
        if ($this->alerts->ntfyNotifyDigest($tenantId) && $this->alerts->ntfyConfigured($tenantId)) {
            $channels[] = 'ntfy';
        }

        return $channels;
    }

    /**
     * Canales de alerta servidor caído (Telegram / email / WhatsApp según toggles).
     * Solo incluye canales realmente configurados (evita marcar "alerted" sin envío).
     *
     * @return array<int, string>
     */
    public function adminServerDownChannels(?int $tenantId = null): array
    {
        $channels = [];
        if ($this->alerts->telegramNotifyServerDown($tenantId) && $this->alerts->telegramConfigured($tenantId)) {
            $channels[] = 'telegram';
        }
        if ($this->alerts->emailNotifyServerDown($tenantId) && $this->alerts->emailConfigured($tenantId)) {
            $channels[] = 'email';
        }
        if ($this->alerts->whatsappNotifyServerDown($tenantId) && $this->alerts->whatsappConfigured($tenantId)) {
            $channels[] = 'whatsapp';
        }
        if ($this->alerts->ntfyNotifyServerDown($tenantId) && $this->alerts->ntfyConfigured($tenantId)) {
            $channels[] = 'ntfy';
        }

        return $channels;
    }

    /**
     * Canales para “todo lo que no sea bueno” (sync FAIL, cron, backup, streams, Stripe…).
     *
     * @return array<int, string>
     */
    public function adminCriticalChannels(?int $tenantId = null): array
    {
        $channels = [];
        if ($this->alerts->telegramNotifyCritical($tenantId) && $this->alerts->telegramConfigured($tenantId)) {
            $channels[] = 'telegram';
        }
        if ($this->alerts->emailNotifyCritical($tenantId) && $this->alerts->emailConfigured($tenantId)) {
            $channels[] = 'email';
        }
        if ($this->alerts->whatsappNotifyCritical($tenantId) && $this->alerts->whatsappConfigured($tenantId)) {
            $channels[] = 'whatsapp';
        }
        if ($this->alerts->ntfyNotifyCritical($tenantId) && $this->alerts->ntfyConfigured($tenantId)) {
            $channels[] = 'ntfy';
        }

        // Fallback: si no hay toggles críticos explícitos útiles, reutilizar server-down.
        if ($channels === []) {
            return $this->adminServerDownChannels($tenantId);
        }

        return $channels;
    }

    public function notifyUserCreated(string $username, string $email): void
    {
        $this->notifyMediaUserCreated(
            email: $email !== '' ? $email : $username,
            serverName: '',
            days: null,
            expiresAt: null,
            tenantId: null,
            username: $username,
        );
    }

    /**
     * Aviso admin: alta de usuario media (panel, registro, quick-invite).
     */
    public function notifyMediaUserCreated(
        string $email,
        string $serverName = '',
        ?int $days = null,
        ?string $expiresAt = null,
        ?int $tenantId = null,
        string $username = '',
    ): void {
        $tenantId ??= (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $who = trim($email) !== '' ? trim($email) : (trim($username) !== '' ? trim($username) : 'sin email');
        $lines = ['*' . $who . '*'];
        if (trim($serverName) !== '') {
            $lines[] = 'Servidor: ' . trim($serverName);
        }
        if ($days !== null && $days > 0) {
            $lines[] = 'Duración: ' . $days . ' días';
        }
        if ($expiresAt !== null && trim($expiresAt) !== '') {
            $lines[] = 'Hasta: ' . substr(trim($expiresAt), 0, 10);
        }

        try {
            $channels = $this->adminLifecycleChannels('created', $tenantId);
            if ($channels === []) {
                return;
            }
            $this->notify(
                'media_user.created',
                'ALTA',
                implode("\n", $lines),
                $channels,
                [
                    'email' => $who,
                    'server' => $serverName,
                    'days' => $days,
                    'expires_at' => $expiresAt,
                    'whatsapp_kind' => 'created',
                ],
                null,
                $tenantId
            );
        } catch (\Throwable $e) {
            Logger::warning('Admin create alert failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Aviso admin: renovación (registro, Stripe, extensión manual, quick-invite update).
     */
    public function notifyMediaUserRenewed(
        string $email,
        string $serverName = '',
        ?int $days = null,
        ?string $expiresAt = null,
        ?int $tenantId = null,
        string $username = '',
    ): void {
        $tenantId ??= (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $who = trim($email) !== '' ? trim($email) : (trim($username) !== '' ? trim($username) : 'sin email');
        $lines = ['*' . $who . '*'];
        if ($days !== null && $days > 0) {
            $lines[] = '+' . $days . ' días';
        }
        if (trim($serverName) !== '') {
            $lines[] = 'Servidor: ' . trim($serverName);
        }
        if ($expiresAt !== null && trim($expiresAt) !== '') {
            $lines[] = 'Hasta: ' . substr(trim($expiresAt), 0, 10);
        }

        try {
            $channels = $this->adminLifecycleChannels('renewed', $tenantId);
            if ($channels === []) {
                return;
            }
            $this->notify(
                'media_user.renewed',
                'RENOVACIÓN',
                implode("\n", $lines),
                $channels,
                [
                    'email' => $who,
                    'server' => $serverName,
                    'days' => $days,
                    'expires_at' => $expiresAt,
                    'whatsapp_kind' => 'renewed',
                ],
                null,
                $tenantId
            );
        } catch (\Throwable $e) {
            Logger::warning('Admin renew alert failed', ['error' => $e->getMessage()]);
        }
    }

    public function notifyServerDown(string $serverName): void
    {
        // Compat: alerta mínima. El cron de automatización usa ServerDownAlertService
        // (diagnóstico + email + WhatsApp + escalado 5/15/30).
        // $serverName debería incluir el tipo, p.ej. "NucBox (Plex)".
        $this->notify(
            'server.down',
            'Servidor caído: ' . $serverName,
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
