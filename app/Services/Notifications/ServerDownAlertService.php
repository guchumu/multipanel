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

    /** @return array{alerted: int, skipped: int, cleared: int} */
    public function processOfflineServers(int $tenantId = 1): array
    {
        $stats = ['alerted' => 0, 'skipped' => 0, 'cleared' => 0];

        $offlineRows = Database::getInstance()->fetchAll(
            "SELECT * FROM servers
             WHERE tenant_id = ? AND status = 'offline' AND deleted_at IS NULL
               AND last_check_at > DATE_SUB(NOW(), INTERVAL 20 MINUTE)",
            [$tenantId]
        );

        $state = $this->alerts->getServerDownState($tenantId);
        $offlineIds = [];

        foreach ($offlineRows as $row) {
            $server = new Server($row);
            $offlineIds[] = (string) $server->id;
            $result = $this->maybeAlert($tenantId, $server, $state);
            if ($result === 'alerted') {
                $stats['alerted']++;
            } else {
                $stats['skipped']++;
            }
        }

        // Limpiar estado de servidores que ya no están offline.
        $changed = false;
        foreach (array_keys($state) as $id) {
            if (!in_array((string) $id, $offlineIds, true)) {
                unset($state[(string) $id]);
                $changed = true;
                $stats['cleared']++;
            }
        }

        if ($changed || $stats['alerted'] > 0) {
            $this->alerts->saveServerDownState($tenantId, $state);
        }

        return $stats;
    }

    /**
     * @param array<string, array{first_seen_at: string, last_alert_at: string, level: int}> $state
     * @return 'alerted'|'skipped'
     */
    private function maybeAlert(int $tenantId, Server $server, array &$state): string
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

        if (!isset($levels[$nextLevel])) {
            Logger::debug('Server down alert capped (no more escalations)', [
                'server_id' => $server->id,
                'elapsed_min' => $elapsedMin,
            ]);
            return 'skipped';
        }

        $requiredMin = (int) $levels[$nextLevel];
        if ($elapsedMin < $requiredMin) {
            return 'skipped';
        }

        $diagnosis = $this->diagnose($server);
        $body = $this->buildMessage($server, $diagnosis, $firstSeen, $elapsedMin, $nextLevel, $requiredMin);
        $title = $nextLevel === 0
            ? 'Servidor caído'
            : 'Servidor sigue caído';

        $channels = $this->notifications->adminServerDownChannels($tenantId);
        if ($channels === []) {
            Logger::debug('Server down alert skipped: no channels enabled', [
                'server_id' => $server->id,
            ]);
            return 'skipped';
        }

        $this->notifications->notify(
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

        $state[$id]['level'] = $nextLevel;
        $state[$id]['last_alert_at'] = $nowSql;

        Logger::info('Server down alert sent', [
            'server_id' => $server->id,
            'escalation_level' => $nextLevel,
            'elapsed_min' => $elapsedMin,
            'channels' => $channels,
        ]);

        return 'alerted';
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
