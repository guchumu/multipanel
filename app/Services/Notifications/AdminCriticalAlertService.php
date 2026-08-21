<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\AlertSettingsService;
use Core\Logger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Avisos admin de “todo lo que no sea bueno”: sync FAIL, cron crashes,
 * backup, streams, Stripe/webhook, etc. Debounce por fingerprint; la 1ª vez siempre intenta enviar.
 */
final class AdminCriticalAlertService
{
    public function __construct(
        private NotificationService $notifications = new NotificationService(),
        private AlertSettingsService $alerts = new AlertSettingsService(),
    ) {
    }

    /**
     * @param array{
     *   debounce_minutes?: int,
     *   prefer_telegram?: bool,
     *   data?: array<string, mixed>
     * } $options
     * @return array{ok: bool, skipped: bool, reason: string, channels: array<int, string>}
     */
    public function notify(
        int $tenantId,
        string $fingerprint,
        string $title,
        string $message,
        array $options = [],
    ): array {
        $debounceMin = max(0, (int) ($options['debounce_minutes'] ?? 30));
        $channels = $this->notifications->adminCriticalChannels($tenantId);

        if ($channels === []) {
            $reason = $this->noChannelsReason($tenantId);
            Logger::warning('Critical alert skipped: no channels', [
                'fingerprint' => $fingerprint,
                'reason' => $reason,
            ]);

            return ['ok' => false, 'skipped' => true, 'reason' => $reason, 'channels' => []];
        }

        $state = $this->alerts->getCriticalAlertState($tenantId);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');

        if ($debounceMin > 0 && isset($state[$fingerprint]['last_sent_at'])) {
            $last = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $state[$fingerprint]['last_sent_at'],
                new DateTimeZone('UTC')
            );
            if ($last instanceof DateTimeImmutable) {
                $elapsed = (int) floor(($now->getTimestamp() - $last->getTimestamp()) / 60);
                if ($elapsed < $debounceMin) {
                    $reason = "debounce ({$elapsed}/{$debounceMin} min)";

                    return ['ok' => false, 'skipped' => true, 'reason' => $reason, 'channels' => $channels];
                }
            }
        }

        $results = $this->notifications->notify(
            'admin.critical',
            $title,
            $message,
            $channels,
            array_merge([
                'level' => 'error',
                'to' => $this->alerts->alertEmail($tenantId),
                'tenant_id' => $tenantId,
                'fingerprint' => $fingerprint,
            ], $options['data'] ?? []),
            null,
            $tenantId
        );

        $sent = [];
        foreach ($results as $channel => $ok) {
            if ($ok) {
                $sent[] = (string) $channel;
            }
        }

        if ($sent === []) {
            $reason = 'send failed on all channels (' . implode(',', $channels) . ')';
            Logger::warning('Critical alert send failed', [
                'fingerprint' => $fingerprint,
                'results' => $results,
            ]);

            return ['ok' => false, 'skipped' => true, 'reason' => $reason, 'channels' => $channels];
        }

        $state[$fingerprint] = [
            'last_sent_at' => $nowSql,
            'title' => $title,
        ];
        $this->alerts->saveCriticalAlertState($tenantId, $state);

        Logger::info('Critical alert sent', [
            'fingerprint' => $fingerprint,
            'channels' => $sent,
        ]);

        return [
            'ok' => true,
            'skipped' => false,
            'reason' => 'sent via ' . implode(',', $sent),
            'channels' => $sent,
        ];
    }

    /**
     * @param array<int, string> $serverNames
     * @return array{ok: bool, skipped: bool, reason: string, channels: array<int, string>}
     */
    public function notifySyncFailures(int $tenantId, array $serverNames): array
    {
        $names = array_values(array_filter(array_map('strval', $serverNames)));
        if ($names === []) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no failures', 'channels' => []];
        }

        sort($names);
        $count = count($names);
        $list = implode(', ', $names);
        $fp = 'sync_fail:' . md5(implode('|', $names));

        return $this->notify(
            $tenantId,
            $fp,
            $count === 1 ? "SYNC FAIL: {$list}" : "SYNC FAIL: {$count} servidores",
            $count === 1
                ? "El sync del servidor \"{$list}\" ha fallado. Revisar conexión / token / URL."
                : "Han fallado {$count} servidores en el sync (nombre + tipo):\n{$list}",
            ['debounce_minutes' => 30]
        );
    }

    /**
     * @return array{ok: bool, skipped: bool, reason: string, channels: array<int, string>}
     */
    public function notifyCronFailure(int $tenantId, string $task, string $error): array
    {
        $error = trim($error);
        $fp = 'cron_fail:' . $task . ':' . md5($error);

        return $this->notify(
            $tenantId,
            $fp,
            "CRON FALLÓ: {$task}",
            "La tarea de cron \"{$task}\" ha fallado:\n{$error}",
            ['debounce_minutes' => 60]
        );
    }

    /**
     * @return array{ok: bool, skipped: bool, reason: string, channels: array<int, string>}
     */
    public function notifyBackupFailure(int $tenantId, string $detail = ''): array
    {
        $detail = trim($detail);

        return $this->notify(
            $tenantId,
            'backup_fail',
            'BACKUP FALLIDO',
            $detail !== '' ? "El backup no se pudo crear.\n{$detail}" : 'El backup no se pudo crear.',
            ['debounce_minutes' => 120]
        );
    }

    /**
     * @return array{ok: bool, skipped: bool, reason: string, channels: array<int, string>}
     */
    public function notifyStreamLimitViolation(
        int $tenantId,
        string $username,
        int $count,
        int $limit,
        bool $enforced,
        string $fingerprint,
        array $sessions = [],
        array $meta = [],
    ): array {
        $sandbox = !empty($meta['sandbox']) && !$enforced;
        $household = !empty($meta['household']);
        $when = WhatsAppAdminText::nowMadridLong();
        $homeCount = (int) ($meta['home_count'] ?? $count);
        $awayCount = (int) ($meta['away_count'] ?? 0);
        $homeLimit = (int) ($meta['home_limit'] ?? $limit);
        $awayLimit = (int) ($meta['away_limit'] ?? 0);

        if ($sandbox && !(new \App\Services\StreamLimitSettingsService())->sandboxAlertsEnabled($tenantId)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'sandbox off', 'channels' => []];
        }

        $title = $enforced ? 'CORTE de reproducción' : 'SANDBOX: se habría cortado';
        $lines = [];
        if ($sandbox) {
            $lines[] = 'No se ha cortado. El corte automático está apagado.';
        } else {
            $lines[] = 'Corte aplicado.';
        }
        $lines[] = "Momento: {$when}";
        $lines[] = "Usuario: {$username}";
        if ($household) {
            $lines[] = "Casa: {$homeCount}/{$homeLimit} · Fuera: {$awayCount}/{$awayLimit}";
        } else {
            $lines[] = "Streams: {$count}/{$limit}";
        }

        $cutLines = [];
        $otherLines = [];
        foreach ($sessions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $reason = (string) ($s['cut_reason'] ?? '');
            $isCut = !empty($s['killed']) || !empty($s['would_cut']) || $reason !== '';
            $why = match ($reason) {
                'away' => (($s['household_source'] ?? '') === 'device_mobile' || ($s['device_class'] ?? '') === 'mobile')
                    ? 'móvil'
                    : 'otra casa',
                'home' => 'demasiadas teles',
                default => !empty($s['killed']) ? 'cortada' : '',
            };
            $titleS = trim((string) ($s['title'] ?? '')) ?: 'Sin título';
            $ip = trim((string) ($s['ip'] ?? '')) ?: 'IP ?';
            $player = trim((string) ($s['player'] ?? '')) ?: 'reproductor ?';
            $zone = (($s['household'] ?? '') === 'home') ? 'Casa' : 'Fuera';
            $bit = "{$zone}: {$titleS} · {$ip} · {$player}";
            if ($why !== '') {
                $bit .= " → {$why}";
            }
            if ($isCut) {
                $cutLines[] = $bit;
            } else {
                $otherLines[] = $bit;
            }
        }
        if ($cutLines !== []) {
            $lines[] = $sandbox ? 'Se habría cortado:' : 'Cortado:';
            foreach ($cutLines as $line) {
                $lines[] = '· ' . $line;
            }
        }
        if ($otherLines !== []) {
            $lines[] = 'Siguen:';
            foreach ($otherLines as $line) {
                $lines[] = '· ' . $line;
            }
        }

        return $this->notify(
            $tenantId,
            'stream_limit:' . $fingerprint,
            $title,
            implode("\n", $lines),
            [
                'debounce_minutes' => $sandbox ? 3 : 15,
                'data' => [
                    'whatsapp_kind' => $enforced ? 'cut' : 'sandbox',
                ],
            ]
        );
    }

    /**
     * @return array{ok: bool, skipped: bool, reason: string, channels: array<int, string>}
     */
    public function notifyPaymentWebhookFailure(int $tenantId, string $gateway, string $error): array
    {
        $gateway = trim($gateway) !== '' ? trim($gateway) : 'payment';
        $error = trim($error);

        return $this->notify(
            $tenantId,
            'payment_webhook:' . $gateway . ':' . md5($error),
            "Webhook pago fallido ({$gateway})",
            $error !== '' ? $error : 'Firma inválida o payload ilegible.',
            ['debounce_minutes' => 60]
        );
    }

    private function noChannelsReason(int $tenantId): string
    {
        $bits = [];
        if ($this->alerts->telegramNotifyCritical($tenantId) && !$this->alerts->telegramConfigured($tenantId)) {
            $bits[] = 'telegram sin bot/chat admin';
        } elseif (!$this->alerts->telegramNotifyCritical($tenantId)) {
            $bits[] = 'telegram off';
        }
        if ($this->alerts->emailNotifyCritical($tenantId) && !$this->alerts->emailConfigured($tenantId)) {
            $bits[] = 'email sin SMTP/destinatario';
        } elseif (!$this->alerts->emailNotifyCritical($tenantId)) {
            $bits[] = 'email off';
        }
        if ($this->alerts->whatsappNotifyCritical($tenantId) && !$this->alerts->whatsappConfigured($tenantId)) {
            $bits[] = 'whatsapp no configurado';
        } elseif (!$this->alerts->whatsappNotifyCritical($tenantId)) {
            $bits[] = 'whatsapp off';
        }

        return $bits !== [] ? implode('; ', $bits) : 'no channels enabled';
    }
}
