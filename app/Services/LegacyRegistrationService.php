<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Repositories\ServerRepository;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
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

        $addDays = $this->monthsToDays($months);
        $db = Database::getInstance();
        $results = [];

        $db->beginTransaction();

        try {
            foreach ($emails as $email) {
                $results[] = $this->processEmail(
                    $tenantId,
                    $clientId,
                    $email,
                    $amount,
                    $paymentType,
                    $months,
                    $service,
                    $addDays
                );
            }

            $db->commit();

            return [
                'status' => 'success',
                'message' => 'Todos los emails procesados correctamente',
                'total_emails' => count($emails),
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            $db->rollBack();
            Logger::error('Legacy registration failed', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
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

        $existing = $db->fetchOne(
            'SELECT * FROM media_users
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND (email = ? OR telegram_chat_id = ?)
             ORDER BY expires_at DESC LIMIT 1',
            [$tenantId, $email, $clientId]
        );

        $today = new DateTimeImmutable('today');

        if ($existing) {
            $serverRow = $existing['server_id']
                ? $db->fetchOne('SELECT name FROM servers WHERE id = ? AND deleted_at IS NULL', [$existing['server_id']])
                : null;

            $newEndDate = $this->calculateNewEndDate($existing, $addDays, $today);
            $db->update('media_users', [
                'expires_at' => $newEndDate,
                'status' => 'active',
                'telegram_chat_id' => $clientId,
            ], 'id = ?', [$existing['id']]);

            $db->update('payments_history', [
                'media_user_id' => (int) $existing['id'],
            ], 'id = ?', [$paymentId]);

            $this->audit->log('media_user.legacy_renewed', 'media_user', (int) $existing['id']);

            return [
                'email' => $email,
                'action' => 'renewed',
                'end_date' => substr($newEndDate, 0, 10),
                'server' => (string) ($serverRow['name'] ?? '—'),
            ];
        }

        $server = $this->servers->findDefaultByTenant($tenantId);
        if ($server === null) {
            throw new \RuntimeException('No hay servidor por defecto configurado');
        }

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

        $db->update('payments_history', [
            'media_user_id' => (int) $user->id,
        ], 'id = ?', [$paymentId]);

        $libraryKeys = $this->librarySectionIds((int) $server->id);
        if ($server->isPlex()) {
            $plex = MediaServerFactory::make($server);
            if ($plex instanceof PlexService && !$plex->inviteUserByEmail($email, $libraryKeys)) {
                throw new \RuntimeException("Error al enviar invitación a Plex para: {$email}");
            }
        }

        $this->audit->log('media_user.legacy_created', 'media_user', (int) $user->id);

        return [
            'email' => $email,
            'action' => 'created',
            'end_date' => substr($endDate, 0, 10),
            'server' => (string) $server->name,
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
        if ((float) $months === 0.25) {
            return 7;
        }

        return (int) ((float) $months * 30);
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
