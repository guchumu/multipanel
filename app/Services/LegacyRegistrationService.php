<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Repositories\ServerRepository;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use App\Services\Notifications\TelegramChannel;
use Core\Database;
use Core\Logger;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

/**
 * Legacy payment registration webhook (compatible with guarda-registro.php).
 */
final class LegacyRegistrationService
{
    public function __construct(
        private ServerRepository $servers = new ServerRepository(),
        private AuditService $audit = new AuditService(),
        private TelegramChannel $telegram = new TelegramChannel(),
        private MediaUserMessageService $messageLog = new MediaUserMessageService(),
    ) {
    }

    /**
     * @param array{
     *     idcliente: string,
     *     pagado: float|string,
     *     tipopago: string,
     *     tiempomes: float|string,
     *     servicio?: string,
     *     emails: array<int, string>
     * } $input
     * @return array{status: string, message: string, total_emails?: int, results?: array<int, array<string, mixed>>}
     */
    public function process(int $tenantId, array $input): array
    {
        $clientId = trim((string) $input['idcliente']);
        $amount = (float) $input['pagado'];
        $paymentType = trim((string) $input['tipopago']);
        $months = $input['tiempomes'];
        $service = trim((string) ($input['servicio'] ?? 'plex'));
        $emails = $input['emails'];

        if ($clientId === '' || $paymentType === '' || $emails === []) {
            return ['status' => 'error', 'message' => 'Faltan parámetros obligatorios'];
        }

        $rateError = $this->checkRateLimits($clientId, $emails);
        if ($rateError !== null) {
            return ['status' => 'error', 'message' => $rateError];
        }

        $addDays = $this->monthsToDays($months);
        $db = Database::getInstance();
        $results = [];
        $telegramLines = [];

        $db->beginTransaction();

        try {
            foreach ($emails as $email) {
                $result = $this->processEmail(
                    $tenantId,
                    $clientId,
                    $email,
                    $amount,
                    $paymentType,
                    $months,
                    $service,
                    $addDays
                );
                $results[] = $result;
                if (!empty($result['telegram_line'])) {
                    $telegramLines[] = $result['telegram_line'];
                }
            }

            $db->commit();

            if ($telegramLines !== []) {
                $summary = "Acciones:\n\n" . implode("\n\n", $telegramLines);
                $this->telegram->send('Registro procesado', $summary, [
                    'chat_id' => $clientId,
                    'message_type' => 'registro_summary',
                    'log_message' => true,
                ]);
                $this->messageLog->log(
                    null,
                    'registro_summary',
                    $summary,
                    'Registro procesado',
                    $clientId,
                    'telegram',
                    true
                );
            }

            return [
                'status' => 'ok',
                'message' => 'Proceso completado.',
                'total_emails' => count($emails),
                'processed_emails' => array_column($results, 'email', 'email'),
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            Logger::error('Legacy registration failed', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** @param array<int, string> $emails */
    private function checkRateLimits(string $clientId, array $emails): ?string
    {
        $hours = (int) config('registro.rate_limit_hours', 24);
        $maxClient = (int) config('registro.max_requests_per_client', 3);
        $maxEmail = (int) config('registro.max_requests_per_email', 3);
        $db = Database::getInstance();

        try {
            $clientCount = $db->fetchOne(
                'SELECT COUNT(*) AS total FROM payments_history
                 WHERE client_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
                [$clientId, $hours]
            );
            if ((int) ($clientCount['total'] ?? 0) >= $maxClient) {
                return "Límite alcanzado: máximo {$maxClient} peticiones por Telegram en {$hours}h.";
            }

            foreach ($emails as $email) {
                $emailCount = $db->fetchOne(
                    'SELECT COUNT(*) AS total FROM payments_history
                     WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
                    [$email, $hours]
                );
                if ((int) ($emailCount['total'] ?? 0) >= $maxEmail) {
                    return "Límite alcanzado: máximo {$maxEmail} peticiones para {$email} en {$hours}h.";
                }
            }
        } catch (\Throwable) {
            // payments_history may not exist until migration runs
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function processEmail(
        int $tenantId,
        string $clientId,
        string $email,
        float $amount,
        string $paymentType,
        float|string $months,
        string $service,
        int $addDays,
    ): array {
        $db = Database::getInstance();

        $paymentId = $db->insert('payments_history', [
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'telegram_chat_id' => $clientId,
            'email' => $email,
            'amount' => $amount,
            'payment_type' => $paymentType,
            'months_added' => (float) $months,
            'service' => $service,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $defaultServer = $this->servers->findDefaultByTenant($tenantId);
        if ($defaultServer === null) {
            throw new \RuntimeException('No hay servidor predeterminado configurado');
        }

        $existing = $db->fetchOne(
            'SELECT * FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL AND email = ?
             ORDER BY expires_at DESC LIMIT 1',
            [$tenantId, $email]
        );

        $today = new DateTimeImmutable('today');
        $isNewUser = $existing === null;
        $server = $defaultServer;

        if ($existing && !empty($existing['server_id'])) {
            $serverRow = $db->fetchOne(
                'SELECT * FROM servers WHERE id = ? AND deleted_at IS NULL',
                [$existing['server_id']]
            );
            if ($serverRow) {
                $server = new \App\Models\Server($serverRow);
            }
        }

        if ($existing) {
            $newEndDate = $this->calculateNewEndDate($existing, $addDays, $today);
            $db->update('media_users', [
                'expires_at' => $newEndDate,
                'status' => 'active',
                'telegram_chat_id' => $clientId,
            ], 'id = ?', [$existing['id']]);

            $mediaUserId = (int) $existing['id'];
            $action = 'renewed';
            $endDateStr = substr($newEndDate, 0, 10);
        } else {
            $endDate = $today->modify("+{$addDays} days")->format('Y-m-d 23:59:59');
            $username = strstr($email, '@', true) ?: $email;

            $user = new MediaUser([
                'tenant_id' => $tenantId,
                'uuid' => Uuid::uuid4()->toString(),
                'server_id' => (int) $server->id,
                'username' => $username,
                'email' => $email,
                'display_name' => $username,
                'status' => 'invited',
                'expires_at' => $endDate,
                'telegram_chat_id' => $clientId,
                'max_streams' => 1,
                'max_devices' => 5,
            ]);
            $user->save();

            $mediaUserId = (int) $user->id;
            $action = 'created';
            $endDateStr = substr($endDate, 0, 10);
            $this->audit->log('media_user.legacy_created', 'media_user', $mediaUserId);
        }

        $db->update('payments_history', [
            'media_user_id' => $mediaUserId,
        ], 'id = ?', [$paymentId]);

        if ($action === 'renewed') {
            $this->audit->log('media_user.legacy_renewed', 'media_user', $mediaUserId);
        }

        $libraryKeys = $this->librarySectionIds((int) $server->id);
        if ($server->isPlex()) {
            $plex = MediaServerFactory::make($server);
            if ($plex instanceof PlexService && !$plex->inviteUserByEmail($email, $libraryKeys)) {
                throw new \RuntimeException("Error al enviar invitación a Plex para: {$email}");
            }
        }

        $fechaFinal = $this->formatDateEs($endDateStr);
        $telegramLine = $isNewUser
            ? "✅ {$email}\n Invitación al servidor \"{$server->name}\". Alta hasta el {$fechaFinal}"
            : "✅ {$email}\n Acceso actualizado en \"{$server->name}\" hasta {$fechaFinal}";

        return [
            'email' => $email,
            'action' => $action,
            'end_date' => $endDateStr,
            'server' => (string) $server->name,
            'telegram_line' => $telegramLine,
        ];
    }

    /** @param array<string, mixed> $userRow */
    private function calculateNewEndDate(array $userRow, int $addDays, DateTimeImmutable $today): string
    {
        $currentEnd = trim((string) ($userRow['expires_at'] ?? ''));
        $status = (string) ($userRow['status'] ?? '');

        if ($status === 'active' && $currentEnd !== '' && strtotime($currentEnd) > $today->getTimestamp()) {
            $base = new DateTimeImmutable(substr($currentEnd, 0, 10));
        } else {
            $base = $today;
        }

        return $base->modify("+{$addDays} days")->format('Y-m-d 23:59:59');
    }

    private function monthsToDays(float|string $months): int
    {
        $value = (float) $months;

        if (abs($value - 0.24) < 0.02 || abs($value - 0.25) < 0.02) {
            return 7;
        }

        if ($value <= 0) {
            throw new \InvalidArgumentException('tiempomes debe ser mayor que 0');
        }

        return (int) round($value * 30);
    }

    private function formatDateEs(string $isoDate): string
    {
        $parts = explode('-', $isoDate);
        if (count($parts) !== 3) {
            return $isoDate;
        }

        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }

    /** @return array<int, int> */
    private function librarySectionIds(int $serverId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT external_id FROM libraries WHERE server_id = ? AND is_enabled = 1',
            [$serverId]
        );

        return array_values(array_map(static fn (array $row): int => (int) $row['external_id'], $rows));
    }
}
