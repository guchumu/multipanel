<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Server;
use App\Services\AlertSettingsService;
use Core\Database;
use Core\Logger;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Multi-channel server-down alerts with HTTP diagnosis and capped escalation (0/5/15/30 min).
 */
final class ServerDownAlertService
{
    private const RECENT_CHECK_SECONDS = 7200; // 2h — comparación en PHP (evita desfase TZ MySQL)

    public function __construct(
        private NotificationService $notifications = new NotificationService(),
        private AlertSettingsService $alerts = new AlertSettingsService(),
        private ?Client $http = null,
    ) {
        $this->http ??= new Client([
            'timeout' => 8,
            'connect_timeout' => 5,
            'http_errors' => false,
            'verify' => false,
            'allow_redirects' => true,
        ]);
    }

    /**
     * @return array{
     *   alerted: int,
     *   skipped: int,
     *   cleared: int,
     *   details: array<int, array{server: string, result: string, reason: string, channels?: array<int, string>}>
     * }
     */
    public function processOfflineServers(int $tenantId = 1): array
    {
        $stats = ['alerted' => 0, 'skipped' => 0, 'cleared' => 0, 'details' => []];

        // offline + error (legacy): sync fallido siempre debería ser offline tras el fix.
        $offlineRows = Database::getInstance()->fetchAll(
            "SELECT * FROM servers
             WHERE tenant_id = ? AND status IN ('offline', 'error') AND deleted_at IS NULL",
            [$tenantId]
        );

        $state = $this->alerts->getServerDownState($tenantId);
        $offlineIds = [];
        $stateDirty = false;
        $nowTs = time();

        foreach ($offlineRows as $row) {
            $server = new Server($row);
            $checkedAt = strtotime((string) ($server->last_check_at ?? ''));
            if ($checkedAt === false || $checkedAt < ($nowTs - self::RECENT_CHECK_SECONDS)) {
                $stats['skipped']++;
                $stats['details'][] = [
                    'server' => (string) $server->name,
                    'result' => 'skipped',
                    'reason' => 'last_check_at stale or missing',
                ];
                continue;
            }

            $offlineIds[] = (string) $server->id;
            $result = $this->maybeAlert($tenantId, $server, $state, $stateDirty);
            if ($result['result'] === 'alerted') {
                $stats['alerted']++;
            } else {
                $stats['skipped']++;
            }
            $stats['details'][] = [
                'server' => (string) $server->name,
                'result' => $result['result'],
                'reason' => $result['reason'],
                'channels' => $result['channels'] ?? [],
            ];
        }

        foreach (array_keys($state) as $id) {
            if (!in_array((string) $id, $offlineIds, true)) {
                unset($state[(string) $id]);
                $stateDirty = true;
                $stats['cleared']++;
            }
        }

        if ($stateDirty || $stats['alerted'] > 0) {
            $this->alerts->saveServerDownState($tenantId, $state);
        }

        return $stats;
    }

    /**
     * @param array<string, array{first_seen_at: string, last_alert_at: string, level: int}> $state
     * @return array{result: 'alerted'|'skipped', reason: string, channels?: array<int, string>}
     */
    private function maybeAlert(int $tenantId, Server $server, array &$state, bool &$stateDirty): array
    {
        $id = (string) $server->id;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');

        if (!isset($state[$id])) {
            $state[$id] = [
                'first_seen_at' => $nowSql,
                'last_alert_at' => '',
                'level' => -1,
            ];
            $stateDirty = true;
        }

        $firstSeen = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $state[$id]['first_seen_at'],
            new DateTimeZone('UTC')
        ) ?: $now;

        $elapsedMin = (int) floor(($now->getTimestamp() - $firstSeen->getTimestamp()) / 60);
        $levels = $this->alerts->serverDownEscalationMinutes();
        $currentLevel = (int) ($state[$id]['level'] ?? -1);
        $nextLevel = $currentLevel + 1;

        // Recuperación: escalado agotado tras envíos fallidos → reintentar nivel 0 una vez.
        if (!isset($levels[$nextLevel])) {
            if ($this->hasSuccessfulDownNotification($tenantId, (int) $server->id, (string) ($state[$id]['first_seen_at'] ?? ''))) {
                return ['result' => 'skipped', 'reason' => 'escalation capped'];
            }
            $state[$id]['level'] = -1;
            $state[$id]['first_seen_at'] = $nowSql;
            $stateDirty = true;
            $nextLevel = 0;
            $elapsedMin = 0;
            $firstSeen = $now;
        }

        $requiredMin = (int) $levels[$nextLevel];
        if ($elapsedMin < $requiredMin) {
            return [
                'result' => 'skipped',
                'reason' => "waiting escalation #{$nextLevel} ({$elapsedMin}/{$requiredMin} min)",
            ];
        }

        // Evitar doble aviso: el sync FAIL del mismo cron ya mandó el lote crítico.
        if ($nextLevel === 0 && $this->recentSyncFailAlert($tenantId)) {
            $state[$id]['level'] = 0;
            $state[$id]['last_alert_at'] = $nowSql;
            $stateDirty = true;

            return [
                'result' => 'skipped',
                'reason' => 'covered by sync FAIL alert (escalation armed)',
            ];
        }

        $channels = $this->notifications->adminServerDownChannels($tenantId);
        if ($channels === []) {
            Logger::debug('Server down alert skipped: no channels enabled/configured', [
                'server_id' => $server->id,
            ]);

            return [
                'result' => 'skipped',
                'reason' => 'no channels configured (telegram chat admin / SMTP / whatsapp)',
            ];
        }

        $diagnosis = $this->diagnose($server);
        $body = $this->buildMessage($server, $diagnosis, $firstSeen, $elapsedMin, $nextLevel, $requiredMin);
        $title = $nextLevel === 0
            ? 'Servidor caído'
            : 'Servidor sigue caído';

        $results = $this->notifications->notify(
            'server.down',
            $title,
            $body,
            $channels,
            [
                'level' => 'error',
                'to' => $this->alerts->alertEmail($tenantId),
                'tenant_id' => $tenantId,
                'server_id' => (int) $server->id,
                'escalation_level' => $nextLevel,
            ],
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
            Logger::warning('Server down alert send failed on all channels', [
                'server_id' => $server->id,
                'channels' => $channels,
                'results' => $results,
            ]);

            // No avanzar escalado si nadie recibió el aviso (la 1ª vez debe poder reintentar).
            return [
                'result' => 'skipped',
                'reason' => 'send failed on all channels (' . implode(',', $channels) . ')',
                'channels' => $channels,
            ];
        }

        $state[$id]['level'] = $nextLevel;
        $state[$id]['last_alert_at'] = $nowSql;
        $stateDirty = true;

        Logger::info('Server down alert sent', [
            'server_id' => $server->id,
            'escalation_level' => $nextLevel,
            'elapsed_min' => $elapsedMin,
            'channels' => $sent,
        ]);

        return [
            'result' => 'alerted',
            'reason' => 'sent via ' . implode(',', $sent),
            'channels' => $sent,
        ];
    }

    private function hasSuccessfulDownNotification(int $tenantId, int $serverId, string $sinceSql): bool
    {
        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT id FROM notifications
                 WHERE tenant_id = ? AND type = 'server.down' AND status = 'sent'
                   AND created_at >= ?
                   AND (data LIKE ? OR data LIKE ?)
                 ORDER BY id DESC LIMIT 1",
                [
                    $tenantId,
                    $sinceSql !== '' ? $sinceSql : '1970-01-01 00:00:00',
                    '%"server_id":' . $serverId . '%',
                    '%"server_id": ' . $serverId . '%',
                ]
            );

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** True si acabamos de avisar un sync FAIL (mismo ciclo / minutos recientes). */
    private function recentSyncFailAlert(int $tenantId, int $withinMinutes = 10): bool
    {
        $state = $this->alerts->getCriticalAlertState($tenantId);
        $now = time();
        foreach ($state as $fp => $row) {
            if (!is_string($fp) || !str_starts_with($fp, 'sync_fail:')) {
                continue;
            }
            $lastAt = (string) ($row['last_sent_at'] ?? '');
            $last = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastAt, new DateTimeZone('UTC'));
            if ($last instanceof DateTimeImmutable && ($now - $last->getTimestamp()) <= ($withinMinutes * 60)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function diagnose(Server $server): array
    {
        $url = $server->fullUrl();
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $dnsOk = null;
        $resolvedIp = null;

        if ($host !== '') {
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $dnsOk = true;
                $resolvedIp = $host;
            } else {
                $resolvedIp = gethostbyname($host);
                $dnsOk = $resolvedIp !== '' && $resolvedIp !== $host;
            }
        }

        $statusCode = null;
        $reachable = false;
        $error = null;
        $latencyMs = null;
        $errorClass = null;

        try {
            $start = microtime(true);
            $response = $this->http->get($url);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $statusCode = $response->getStatusCode();
            $reachable = $statusCode > 0;
        } catch (ConnectException $e) {
            $error = $e->getMessage();
            $errorClass = $this->classifyConnectError($error);
        } catch (RequestException $e) {
            $error = $e->getMessage();
            $errorClass = 'http_error';
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()?->getStatusCode();
            }
        } catch (GuzzleException $e) {
            $error = $e->getMessage();
            $errorClass = 'request_failed';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $errorClass = 'unknown';
        }

        return [
            'configured_url' => $url,
            'host' => $host,
            'dns_ok' => $dnsOk,
            'resolved_ip' => $resolvedIp,
            'reachable' => $reachable,
            'status_code' => $statusCode,
            'latency_ms' => $latencyMs,
            'error' => $error,
            'error_class' => $errorClass,
            'last_error' => (string) ($server->last_error ?? ''),
            'last_check_at' => (string) ($server->last_check_at ?? ''),
        ];
    }

    private function classifyConnectError(string $error): string
    {
        $lower = strtolower($error);
        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($lower, 'could not resolve') || str_contains($lower, 'name or service not known') || str_contains($lower, 'getaddrinfo')) {
            return 'dns';
        }
        if (str_contains($lower, 'connection refused') || str_contains($lower, 'failed to connect')) {
            return 'connection_refused';
        }
        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate')) {
            return 'ssl';
        }

        return 'connect_error';
    }

    /** @param array<string, mixed> $diagnosis */
    private function buildMessage(
        Server $server,
        array $diagnosis,
        DateTimeImmutable $firstSeen,
        int $elapsedMin,
        int $escalationLevel,
        int $requiredMin,
    ): string {
        $tz = new DateTimeZone((string) config('app.timezone', 'Europe/Madrid'));
        $sinceLocal = $firstSeen->setTimezone($tz)->format('d/m/Y H:i');

        $lines = [];
        $lines[] = 'El servidor "' . $server->name . '" no responde.';
        if ($escalationLevel > 0) {
            $lines[] = "⚠️ Sigue caído desde {$sinceLocal} ({$elapsedMin} min). Escalado #{$escalationLevel} (≥ {$requiredMin} min).";
        } else {
            $lines[] = "Detectado a las {$sinceLocal}.";
        }
        $lines[] = '';
        $lines[] = '— Diagnóstico —';
        $lines[] = 'URL: ' . ($diagnosis['configured_url'] ?? '—');
        $lines[] = 'Host: ' . (($diagnosis['host'] ?? '') !== '' ? $diagnosis['host'] : '—');
        $dns = $diagnosis['dns_ok'];
        if ($dns === true) {
            $lines[] = 'DNS: OK' . (!empty($diagnosis['resolved_ip']) ? ' → ' . $diagnosis['resolved_ip'] : '');
        } elseif ($dns === false) {
            $lines[] = 'DNS: FALLÓ (no resuelve)';
        } else {
            $lines[] = 'DNS: n/d';
        }
        $lines[] = 'HTTP alcanzable: ' . (!empty($diagnosis['reachable']) ? 'sí' : 'no');
        $lines[] = 'Status code: ' . ($diagnosis['status_code'] !== null ? (string) $diagnosis['status_code'] : '—');
        if ($diagnosis['latency_ms'] !== null) {
            $lines[] = 'Latencia: ' . $diagnosis['latency_ms'] . ' ms';
        }
        if (!empty($diagnosis['error_class'])) {
            $lines[] = 'Tipo error: ' . $diagnosis['error_class'];
        }
        if (!empty($diagnosis['error'])) {
            $lines[] = 'Error probe: ' . $diagnosis['error'];
        }
        $lastError = trim((string) ($diagnosis['last_error'] ?? ''));
        if ($lastError !== '') {
            $lines[] = 'last_error (sync): ' . $lastError;
        }
        $lastCheck = trim((string) ($diagnosis['last_check_at'] ?? ''));
        if ($lastCheck !== '') {
            $lines[] = 'last_check_at: ' . $lastCheck;
        }
        $lines[] = '';
        $channelBits = [];
        if ($this->alerts->telegramNotifyServerDown((int) $server->tenant_id)) {
            $channelBits[] = 'Telegram';
        }
        if ($this->alerts->emailNotifyServerDown((int) $server->tenant_id)) {
            $channelBits[] = 'email';
        }
        if ($this->alerts->whatsappNotifyServerDown((int) $server->tenant_id)
            && $this->alerts->whatsappConfigured((int) $server->tenant_id)) {
            $channelBits[] = 'WhatsApp';
        }
        $lines[] = 'Canales: ' . ($channelBits !== [] ? implode(' + ', $channelBits) : 'ninguno');

        return implode("\n", $lines);
    }
}
