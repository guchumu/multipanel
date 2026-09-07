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
     * Aviso al cortar una sesión con Vídeo = Transcode (auto-corte o «Cortar ahora»).
     * En ntfy: carátula, archivo original vs stream en curso, y saltar (pausa) arriba tras el usuario.
     *
     * @param array<string, mixed> $session
     * @param array{user_active?: int, total_active?: int} $meta
     * @return array{ok: bool, skipped: bool, reason: string, channels: array<int, string>}
     */
    public function notifyVideoTranscodeKill(
        int $tenantId,
        string $username,
        string $title,
        string $serverName,
        string $fingerprint,
        array $session = [],
        array $meta = [],
    ): array {
        $username = trim($username) !== '' ? trim($username) : 'desconocido';
        $title = trim($title) !== '' ? trim($title) : 'Sin título';
        $serverName = trim($serverName) !== '' ? trim($serverName) : 'servidor ?';
        $when = WhatsAppAdminText::nowMadridLong();

        $subtitle = trim((string) ($session['subtitle'] ?? ''));
        $streamInfo = is_array($session['stream_info'] ?? null) ? $session['stream_info'] : [];
        $source = is_array($streamInfo['source'] ?? null) ? $streamInfo['source'] : [];
        $output = is_array($streamInfo['output'] ?? null) ? $streamInfo['output'] : [];

        $quality = trim((string) ($streamInfo['quality'] ?? ''));
        $streamLine = trim((string) ($output['stream'] ?? $streamInfo['stream'] ?? ''));
        $container = trim((string) ($output['container'] ?? $streamInfo['container'] ?? ''));
        $videoLine = trim((string) ($streamInfo['video'] ?? $session['video_label'] ?? $session['video_decision'] ?? ''));
        $audioLine = trim((string) ($streamInfo['audio'] ?? $session['audio_label'] ?? $session['audio_decision'] ?? ''));
        $subtitleLine = trim((string) ($output['subtitle'] ?? $streamInfo['subtitle'] ?? ''));
        $product = trim((string) ($session['product'] ?? ''));
        $player = trim((string) ($session['player'] ?? ''));
        $platform = trim((string) ($session['platform'] ?? ''));
        $location = trim((string) ($session['location'] ?? ''));
        $clientIp = trim((string) ($session['client_ip'] ?? ''));
        $bandwidth = trim((string) ($session['bandwidth'] ?? ''));
        $household = (($session['household'] ?? '') === 'home') ? 'Casa' : ((($session['household'] ?? '') === 'away') ? 'Fuera' : '');
        $progress = max(0, min(100, (int) ($session['progress'] ?? 0)));
        $state = trim((string) ($session['state'] ?? ''));
        $userActive = max(0, (int) ($meta['user_active'] ?? 0));
        $totalActive = max(0, (int) ($meta['total_active'] ?? 0));

        $srcFile = trim((string) ($source['file'] ?? ''));
        $srcFormat = trim((string) ($source['format'] ?? ''));
        $srcRes = trim((string) ($source['resolution'] ?? ''));
        $srcVideo = trim((string) ($source['video_codec'] ?? ''));
        $srcAudio = trim((string) ($source['audio_codec'] ?? ''));
        $srcChannels = trim((string) ($source['audio_channels'] ?? ''));
        $srcLang = trim((string) ($source['audio_lang'] ?? ''));
        $srcAudioType = trim(implode(' ', array_filter([$srcLang !== '—' ? $srcLang : '', $srcAudio !== '—' ? $srcAudio : '', $srcChannels !== '—' ? $srcChannels : ''])));

        $outVideoDecision = trim((string) ($output['video_decision'] ?? $session['video_decision'] ?? 'transcode'));
        $outAudioDecision = trim((string) ($output['audio_decision'] ?? $session['audio_decision'] ?? ''));
        $outRes = trim((string) ($output['resolution'] ?? ''));
        $outVideo = trim((string) ($output['video_codec'] ?? ''));
        $outAudio = trim((string) ($output['audio_codec'] ?? ''));
        $outChannels = trim((string) ($output['audio_channels'] ?? ''));

        $pause = new \App\Services\VideoTranscodePauseService();
        $pauseUrls = $session !== [] ? $pause->buildPauseUrls($tenantId, $session) : [];
        $pauseLines = [];
        foreach ($pauseUrls as $duration => $url) {
            $pauseLines[] = $pause->shortLinkLabel($duration) . ' ' . $url;
        }

        $toggle = new \App\Services\VideoTranscodeAutoKillToggleService();
        $toggleUrls = $toggle->buildToggleUrls($tenantId);
        $autoKillOn = (new \App\Services\StreamLimitSettingsService())->isAutoKillVideoTranscodesEnabled($tenantId);
        $autoKillState = $toggle->stateLabel($autoKillOn);

        $motivo = \App\Services\Media\SessionStreamInfo::explainVideoTranscodeReason($streamInfo, $session);
        $policy = \App\Services\Media\SessionStreamInfo::videoTranscodeActionLabel($streamInfo, $session);

        // Cabecera: contexto mínimo; el bloque Saltar va justo después del usuario.
        $headerLines = [
            AdminMessageFormat::label('Momento', $when),
            AdminMessageFormat::label('Usuario', $username),
            AdminMessageFormat::label('Política', $policy),
            AdminMessageFormat::label('Motivo', $motivo),
        ];

        $detailLines = [
            AdminMessageFormat::label('Título', $title),
        ];
        if ($subtitle !== '') {
            $detailLines[] = AdminMessageFormat::label('Episodio', $subtitle);
        }
        $detailLines[] = AdminMessageFormat::label('Servidor', $serverName);
        if ($userActive > 0 || $totalActive > 0) {
            $detailLines[] = AdminMessageFormat::label(
                'Reproducciones',
                ($userActive > 0 ? "usuario {$userActive}" : '')
                . ($userActive > 0 && $totalActive > 0 ? ' · ' : '')
                . ($totalActive > 0 ? "total {$totalActive}" : '')
            );
        }

        // Bloque idéntico a Tautulli / En directo (Product…Subtitle + Location).
        $tautulliLines = [
            AdminMessageFormat::label('Product', $product !== '' ? $product : '—'),
            AdminMessageFormat::label('Player', $player !== '' ? $player : ($platform !== '' ? $platform : '—')),
            AdminMessageFormat::label('Quality', $quality !== '' ? $quality : '—'),
            AdminMessageFormat::label('Stream', $streamLine !== '' ? $streamLine : 'Transcode'),
            AdminMessageFormat::label('Container', $container !== '' ? $container : '—'),
            AdminMessageFormat::label(
                'Video',
                $videoLine !== '' ? $videoLine : (
                    trim(
                        ($outVideoDecision !== '' ? ucfirst($outVideoDecision) : 'Transcode')
                        . ($outVideo !== '' && $outVideo !== '—' ? " ({$outVideo}" : '')
                        . ($outRes !== '' && $outRes !== '—' ? " {$outRes}" : '')
                        . ($outVideo !== '' && $outVideo !== '—' ? ')' : '')
                    ) ?: 'Transcode'
                )
            ),
            AdminMessageFormat::label(
                'Audio',
                $audioLine !== '' ? $audioLine : (
                    trim(
                        ($outAudioDecision !== '' ? ucfirst($outAudioDecision) : '—')
                        . ($outAudio !== '' && $outAudio !== '—' ? " ({$outAudio}" : '')
                        . ($outChannels !== '' && $outChannels !== '—' ? " {$outChannels}" : '')
                        . ($outAudio !== '' && $outAudio !== '—' ? ')' : '')
                    ) ?: '—'
                )
            ),
            AdminMessageFormat::label('Subtitle', $subtitleLine !== '' ? $subtitleLine : 'None'),
        ];
        $locationLine = '';
        if ($location !== '') {
            $locationLine = strtoupper($location);
        }
        if ($clientIp !== '') {
            $locationLine = ($locationLine !== '' ? $locationLine . ': ' : '') . $clientIp;
        }
        if ($household !== '') {
            $locationLine = ($locationLine !== '' ? $locationLine . ' · ' : '') . $household;
        }
        if ($locationLine !== '') {
            $tautulliLines[] = AdminMessageFormat::label('Location', $locationLine);
        }
        if ($bandwidth !== '') {
            $tautulliLines[] = AdminMessageFormat::label('Bandwidth', $bandwidth);
        }
        if ($state !== '' || $progress > 0) {
            $tautulliLines[] = AdminMessageFormat::label(
                'Progress',
                trim(($state !== '' ? $state : '') . ($progress > 0 ? " {$progress}%" : ''))
            );
        }

        $originalLines = [];
        if ($srcFile !== '') {
            $originalLines[] = AdminMessageFormat::label('Fichero', $srcFile);
        }
        if ($srcFormat !== '' && $srcFormat !== '—') {
            $originalLines[] = AdminMessageFormat::label('Formato', $srcFormat);
        }
        if ($srcRes !== '' && $srcRes !== '—') {
            $originalLines[] = AdminMessageFormat::label('Resolución', $srcRes);
        }
        if ($srcVideo !== '' && $srcVideo !== '—') {
            $originalLines[] = AdminMessageFormat::label('Vídeo', $srcVideo);
        }
        if ($srcAudioType !== '' || ($srcAudio !== '' && $srcAudio !== '—')) {
            $originalLines[] = AdminMessageFormat::label(
                'Audio',
                $srcAudioType !== '' ? $srcAudioType : $srcAudio
            );
        }

        $toggleLines = [
            AdminMessageFormat::label('Estado', $autoKillState),
        ];
        if (!empty($toggleUrls[\App\Services\VideoTranscodeAutoKillToggleService::ACTION_DISABLE])) {
            $toggleLines[] = 'OFF ' . $toggleUrls[\App\Services\VideoTranscodeAutoKillToggleService::ACTION_DISABLE];
        }
        if (!empty($toggleUrls[\App\Services\VideoTranscodeAutoKillToggleService::ACTION_ENABLE])) {
            $toggleLines[] = 'ON ' . $toggleUrls[\App\Services\VideoTranscodeAutoKillToggleService::ACTION_ENABLE];
        }

        $sections = [
            '✂️ Transcode salvable (calidad/ajustes). ~30 s para saltar antes del corte.',
            implode("\n", $headerLines),
        ];
        if ($pauseLines !== []) {
            $sections[] = AdminMessageFormat::block(
                '⏭ Saltar este corte',
                array_merge(
                    ['Pulsa YA si quieres permitirlo:'],
                    $pauseLines
                )
            );
        }
        $sections[] = implode("\n", $detailLines);
        $sections[] = AdminMessageFormat::block('📡 Stream (como Tautulli)', $tautulliLines);
        if ($originalLines !== []) {
            $sections[] = AdminMessageFormat::block('📁 Archivo original', $originalLines);
        }
        $sections[] = AdminMessageFormat::block('Auto-corte', $toggleLines);

        // ntfy máx. 3 Actions: priorizar Saltar (pausa); Activar solo si sobra hueco.
        $ntfyActions = [];
        foreach (\App\Services\VideoTranscodePauseService::NTFY_ACTION_DURATIONS as $duration) {
            if (count($ntfyActions) >= 3 || empty($pauseUrls[$duration])) {
                continue;
            }
            $ntfyActions[] = [
                'label' => $pause->ntfyActionLabel($duration),
                'url' => $pauseUrls[$duration],
                'clear' => true,
            ];
        }
        if (count($ntfyActions) < 3
            && !empty($toggleUrls[\App\Services\VideoTranscodeAutoKillToggleService::ACTION_ENABLE])) {
            $ntfyActions[] = [
                'label' => $toggle->ntfyActionLabel(\App\Services\VideoTranscodeAutoKillToggleService::ACTION_ENABLE),
                'url' => $toggleUrls[\App\Services\VideoTranscodeAutoKillToggleService::ACTION_ENABLE],
                'clear' => true,
            ];
        }

        $attach = \App\Services\StreamingActivityService::signedPublicThumbAbsoluteUrl($session);

        $data = [
            'whatsapp_kind' => 'cut',
        ];
        if ($attach !== null) {
            $data['ntfy_attach'] = $attach;
        }
        if ($ntfyActions !== []) {
            $data['ntfy_actions'] = $ntfyActions;
        }

        return $this->notify(
            $tenantId,
            'video_transcode_kill:' . $fingerprint,
            'CORTE: vídeo Transcode',
            AdminMessageFormat::compose($sections),
            [
                // El debounce por sesión (cache auto_kill_vtrans_*) evita spam entre ticks de cron.
                'debounce_minutes' => 0,
                'data' => $data,
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
