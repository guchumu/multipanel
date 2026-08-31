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
     * @param array<int, string>|array<int, array{name: string, error?: string}> $failures
     * @return array{ok: bool, skipped: bool, reason: string, channels: array<int, string>}
     */
    public function notifySyncFailures(int $tenantId, array $failures): array
    {
        $rows = [];
        foreach ($failures as $item) {
            if (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $rows[] = [
                    'name' => $name,
                    'error' => trim((string) ($item['error'] ?? '')),
                ];
                continue;
            }
            $name = trim((string) $item);
            if ($name !== '') {
                $rows[] = ['name' => $name, 'error' => ''];
            }
        }
        if ($rows === []) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'no failures', 'channels' => []];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $names = array_column($rows, 'name');
        $count = count($rows);
        $list = implode(', ', $names);
        $fp = 'sync_fail:' . md5(implode('|', $names));

        $detailLines = [];
        foreach ($rows as $row) {
            $err = $row['error'] !== '' ? $row['error'] : 'sin detalle (revisa Servidores → last_error)';
            $detailLines[] = AdminMessageFormat::block($row['name'], [$err]);
        }
        $details = AdminMessageFormat::bullets($detailLines);

        $body = $count === 1
            ? AdminMessageFormat::compose([
                "El sync del servidor «{$list}» ha fallado.",
                AdminMessageFormat::label('Motivo', $details),
                AdminMessageFormat::block('Nota', [
                    'Si hay gente viendo, Plex puede seguir activo en la red local.',
                    'El panel no alcanzó la API de administración (URL, token o timeout).',
                ]),
            ])
            : AdminMessageFormat::compose([
                "Han fallado {$count} servidores en el sync:",
                $details,
                AdminMessageFormat::block('Nota', [
                    'El sync del panel no es lo mismo que el visionado de los clientes.',
                ]),
            ]);

        return $this->notify(
            $tenantId,
            $fp,
            $count === 1 ? "SYNC FAIL: {$list}" : "SYNC FAIL: {$count} servidores",
            $body,
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
            AdminMessageFormat::compose([
                "La tarea de cron «{$task}» ha fallado.",
                AdminMessageFormat::label('Error', $error),
            ]),
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
            $detail !== ''
                ? AdminMessageFormat::compose([
                    'El backup no se pudo crear.',
                    AdminMessageFormat::label('Detalle', $detail),
                ])
                : 'El backup no se pudo crear.',
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
        $sections = [];

        if ($sandbox) {
            $sections[] = '🧪 No se ha cortado. El corte automático está apagado.';
        } else {
            $sections[] = '✂️ Corte aplicado.';
        }

        $summary = [
            AdminMessageFormat::label('Momento', $when),
            AdminMessageFormat::label('Usuario', $username),
        ];
        if ($household) {
            $summary[] = AdminMessageFormat::label('Casa', "{$homeCount}/{$homeLimit}");
            $summary[] = AdminMessageFormat::label('Fuera', "{$awayCount}/{$awayLimit}");
        } else {
            $summary[] = AdminMessageFormat::label('Streams', "{$count}/{$limit}");
        }
        $sections[] = implode("\n", $summary);

        $cutLines = [];
        $otherLines = [];
        $killLinks = new \App\Services\SessionKillLinkService();
        $streamSettings = new \App\Services\StreamLimitSettingsService();
        $defaultKillMessage = $streamSettings->getKillMessage($tenantId);
        $batchTargets = [];
        $streamNum = 0;

        foreach ($sessions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $reason = (string) ($s['cut_reason'] ?? '');
            $alreadyKilled = !empty($s['killed']);
            $isCut = $alreadyKilled || !empty($s['would_cut']) || $reason !== '';
            $why = match ($reason) {
                'away' => (($s['household_source'] ?? '') === 'device_mobile' || ($s['device_class'] ?? '') === 'mobile')
                    ? 'móvil'
                    : 'otra casa',
                'home' => 'demasiadas teles',
                default => $alreadyKilled ? 'cortada' : '',
            };
            $titleS = trim((string) ($s['title'] ?? '')) ?: 'Sin título';
            $ip = trim((string) ($s['ip'] ?? '')) ?: 'IP ?';
            $player = trim((string) ($s['player'] ?? '')) ?: 'reproductor ?';
            $zone = (($s['household'] ?? '') === 'home') ? 'Casa' : 'Fuera';
            $streamNum++;
            $bit = AdminMessageFormat::compose([
                AdminMessageFormat::label("Stream {$streamNum}", $titleS),
                AdminMessageFormat::label('Zona', $zone),
                AdminMessageFormat::label('IP', $ip),
                AdminMessageFormat::label('Reproductor', $player),
                $why !== '' ? AdminMessageFormat::label('Motivo', $why) : '',
            ]);

            $serverId = (int) ($s['server_id'] ?? 0);
            $sessionId = trim((string) ($s['session_id'] ?? ''));
            if (!$alreadyKilled && $serverId > 0 && $sessionId !== '') {
                $killMessage = match ($reason) {
                    'away' => $streamSettings->getKillMessageAway($tenantId),
                    'home' => $streamSettings->getKillMessageHome($tenantId),
                    default => $defaultKillMessage,
                };
                $link = $killLinks->create($tenantId, $serverId, $sessionId, $killMessage, $reason);
                if (!empty($link['short_url'])) {
                    $bit .= "\n" . AdminMessageFormat::label('Cortar', $link['short_url']);
                    $batchTargets[] = [
                        'server_id' => $serverId,
                        'session_id' => $sessionId,
                        'reason_key' => $reason,
                    ];
                }
            }

            if ($isCut) {
                $cutLines[] = $bit;
            } else {
                $otherLines[] = $bit;
            }
        }
        if ($cutLines !== []) {
            $sections[] = AdminMessageFormat::label(
                $sandbox ? 'Se habría cortado' : 'Cortado',
                implode("\n\n", $cutLines)
            );
        }
        if ($otherLines !== []) {
            $sections[] = AdminMessageFormat::label('Siguen activas', implode("\n\n", $otherLines));
        }
        if (count($batchTargets) >= 2) {
            $batchLink = $killLinks->createBatch($tenantId, $batchTargets, $defaultKillMessage, 'all');
            if (!empty($batchLink['short_url'])) {
                $sections[] = AdminMessageFormat::label(
                    'Cortar todas (' . count($batchTargets) . ')',
                    $batchLink['short_url']
                );
            }
        }

        return $this->notify(
            $tenantId,
            'stream_limit:' . $fingerprint,
            $title,
            AdminMessageFormat::compose($sections),
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
            $error !== ''
                ? AdminMessageFormat::compose([
                    "Fallo al procesar webhook de pago ({$gateway}).",
                    AdminMessageFormat::label('Detalle', $error),
                ])
                : 'Firma inválida o payload ilegible.',
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
        if ($this->alerts->ntfyNotifyCritical($tenantId) && !$this->alerts->ntfyConfigured($tenantId)) {
            $bits[] = 'ntfy no configurado';
        } elseif (!$this->alerts->ntfyNotifyCritical($tenantId)) {
            $bits[] = 'ntfy off';
        }

        return $bits !== [] ? implode('; ', $bits) : 'no channels enabled';
    }
}
