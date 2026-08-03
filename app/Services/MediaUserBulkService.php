<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use Core\Database;
use Ramsey\Uuid\Uuid;

/**
 * Bulk create media users from email list with subscription periods.
 */
final class MediaUserBulkService
{
    public function __construct(
        private AuditService $audit = new AuditService(),
        private MediaUserProvisioningService $provisioning = new MediaUserProvisioningService(),
    ) {
    }

    /** @return array{created: int, updated: int, skipped: int, errors: array<int, string>} */
    public function addEmailsToServer(int $tenantId, int $serverId, string $period, string $rawEmails): array
    {
        $expiresAt = SubscriptionPeriod::toExpiresAt($period);
        $emails = $this->parseEmails($rawEmails);
        $db = Database::getInstance();
        $server = Server::find($serverId);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($emails as $email) {
            try {
                $username = strstr($email, '@', true) ?: $email;
                $existing = $db->fetchOne(
                    'SELECT id FROM media_users WHERE tenant_id = ? AND email = ? AND deleted_at IS NULL LIMIT 1',
                    [$tenantId, $email]
                );

                if ($existing) {
                    $db->update('media_users', [
                        'server_id' => $serverId,
                        'expires_at' => $expiresAt,
                        'status' => 'active',
                    ], 'id = ?', [$existing['id']]);
                    $updated++;
                    if ($server !== null) {
                        $existingUser = MediaUser::find((int) $existing['id']);
                        if ($existingUser !== null && trim((string) ($existingUser->external_id ?? '')) === '') {
                            $result = $this->provisioning->provision($existingUser, $server);
                            if (!$result['success']) {
                                $errors[] = "{$email}: {$result['message']}";
                            }
                        }
                    }
                    continue;
                }

                $user = new MediaUser([
                    'tenant_id' => $tenantId,
                    'uuid' => Uuid::uuid4()->toString(),
                    'server_id' => $serverId,
                    'username' => $username,
                    'email' => $email,
                    'display_name' => $username,
                    'status' => 'pending',
                    'expires_at' => $expiresAt,
                    'max_streams' => 1,
                    'max_devices' => 5,
                ]);
                $user->save();
                $this->audit->log('media_user.bulk_created', 'media_user', (int) $user->id);
                $created++;

                if ($server !== null) {
                    $result = $this->provisioning->provision($user, $server);
                    if (!$result['success']) {
                        $errors[] = "{$email}: {$result['message']}";
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "{$email}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    /** @return array<int, string> */
    private function parseEmails(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !filter_var($part, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $emails[$part] = $part;
        }

        return array_values($emails);
    }
}
