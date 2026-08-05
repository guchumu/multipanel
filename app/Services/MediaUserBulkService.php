<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MediaUser;
use App\Models\Server;
use App\Repositories\MediaUserRepository;
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
        private PasswordService $passwords = new PasswordService(),
        private MediaUserRepository $mediaUsers = new MediaUserRepository(),
    ) {
    }

    /** @return array{created: int, updated: int, skipped: int, errors: array<int, string>} */
    public function addEmailsToServer(int $tenantId, int $serverId, string $period, string $rawEmails): array
    {
        return $this->addEmailsWithExpiresAt(
            $tenantId,
            $serverId,
            SubscriptionPeriod::toExpiresAt($period),
            $rawEmails
        );
    }

    /**
     * Invitación rápida (dashboard): un email + duración en días + servidor.
     *
     * @return array{
     *   success: bool,
     *   message: string,
     *   created?: int,
     *   updated?: int,
     *   uuid?: ?string,
     *   server_type?: string,
     *   username?: string,
     *   password?: string,
     *   credentials_text?: string
     * }
     */
    public function inviteEmailWithDays(int $tenantId, int $serverId, string $email, int $days): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Email no válido.'];
        }
        if ($serverId <= 0) {
            return ['success' => false, 'message' => 'Selecciona un servidor.'];
        }

        $server = Server::find($serverId);
        if ($server === null || (int) ($server->tenant_id ?? 0) !== $tenantId) {
            return ['success' => false, 'message' => 'Servidor no válido.'];
        }

        $result = $this->addEmailsWithExpiresAt(
            $tenantId,
            $serverId,
            SubscriptionPeriod::daysToExpiresAt($days),
            $email
        );

        $errors = $result['errors'];
        if ($result['created'] === 0 && $result['updated'] === 0) {
            return [
                'success' => false,
                'message' => $errors[0] ?? 'No se pudo enviar la invitación.',
            ];
        }

        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT uuid, username FROM media_users WHERE tenant_id = ? AND LOWER(email) = LOWER(?) AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
            [$tenantId, $email]
        );

        $parts = [];
        if ($result['created'] > 0) {
            $parts[] = 'usuario creado';
        }
        if ($result['updated'] > 0) {
            $parts[] = 'usuario actualizado';
        }
        if ($errors !== []) {
            $parts[] = 'aviso: ' . $errors[0];
        }

        $response = [
            'success' => true,
            'message' => 'Invitación procesada (' . implode(', ', $parts) . ').',
            'created' => $result['created'],
            'updated' => $result['updated'],
            'uuid' => $row['uuid'] ?? null,
            'server_type' => (string) $server->type,
        ];

        $password = $result['last_password'] ?? null;
        $username = $result['last_username'] ?? ($row['username'] ?? null);
        if (is_string($password) && $password !== '' && (string) $server->type === 'jellyfin') {
            $response['username'] = (string) $username;
            $response['password'] = $password;
            $user = !empty($row['uuid']) ? $this->mediaUsers->findByUuid((string) $row['uuid']) : null;
            if ($user === null && is_string($username)) {
                $user = new MediaUser(['username' => $username, 'email' => $email]);
            }
            if ($user !== null) {
                $response['credentials_text'] = $this->provisioning->credentialsShareText($user, $server, $password);
                $response['message'] = 'Usuario Jellyfin creado. Usuario: ' . $username . ' · Contraseña: ' . $password
                    . ($errors !== [] ? ' (aviso: ' . $errors[0] . ')' : '');
            }
        }

        // Si el aprovisionamiento falló en un alta nueva, no fingir éxito total.
        if ($result['created'] > 0 && $errors !== [] && empty($response['password'])) {
            $response['success'] = false;
            $response['message'] = $errors[0];
        }

        return $response;
    }

    /**
     * @return array{
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   errors: array<int, string>,
     *   last_password?: ?string,
     *   last_username?: ?string
     * }
     */
    private function addEmailsWithExpiresAt(int $tenantId, int $serverId, ?string $expiresAt, string $rawEmails): array
    {
        $emails = $this->parseEmails($rawEmails);
        $db = Database::getInstance();
        $server = Server::find($serverId);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $lastPassword = null;
        $lastUsername = null;

        foreach ($emails as $email) {
            try {
                $isJellyfin = $server !== null && $server->type === 'jellyfin';
                $username = $isJellyfin
                    ? $this->provisioning->generateJellyfinUsername($email, $tenantId)
                    : (strstr($email, '@', true) ?: $email);

                $existing = $db->fetchOne(
                    'SELECT id, external_id, username FROM media_users WHERE tenant_id = ? AND LOWER(email) = LOWER(?) AND deleted_at IS NULL LIMIT 1',
                    [$tenantId, $email]
                );

                if ($existing) {
                    $db->update('media_users', [
                        'server_id' => $serverId,
                        'expires_at' => $expiresAt,
                        'status' => 'active',
                    ], 'id = ?', [$existing['id']]);
                    $updated++;
                    $lastUsername = (string) ($existing['username'] ?? $username);

                    if ($server !== null) {
                        $existingUser = MediaUser::find((int) $existing['id']);
                        if ($existingUser !== null) {
                            $needsProvision = trim((string) ($existingUser->external_id ?? '')) === '';
                            // Jellyfin: si no hay contraseña guardada, regenerar acceso.
                            if ($isJellyfin && !$needsProvision) {
                                $plain = $this->provisioning->revealJellyfinPassword($existingUser);
                                $needsProvision = $plain === null || $plain === '';
                            }
                            if ($needsProvision) {
                                if ($isJellyfin && trim((string) $existingUser->username) === '') {
                                    $existingUser->username = $this->provisioning->generateJellyfinUsername(
                                        $email,
                                        $tenantId,
                                        (int) $existingUser->id
                                    );
                                }
                                $result = $this->provisioning->provision($existingUser, $server);
                                if (!$result['success']) {
                                    $errors[] = "{$email}: {$result['message']}";
                                } else {
                                    $lastPassword = $result['password'] ?? null;
                                    $lastUsername = $result['username'] ?? $existingUser->username;
                                }
                            }
                        }
                    }
                    continue;
                }

                $plainForHash = $isJellyfin ? $this->passwords->generate(12) : null;

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
                    'password' => $plainForHash !== null ? $this->passwords->hash($plainForHash) : null,
                ]);
                $user->save();
                $this->audit->log('media_user.bulk_created', 'media_user', (int) $user->id);
                $created++;
                $lastUsername = $username;

                if ($server !== null) {
                    $result = $this->provisioning->provision($user, $server, $plainForHash);
                    if (!$result['success']) {
                        $errors[] = "{$email}: {$result['message']}";
                    } else {
                        $lastPassword = $result['password'] ?? $plainForHash;
                        $lastUsername = $result['username'] ?? $username;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "{$email}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'last_password' => $lastPassword,
            'last_username' => $lastUsername,
        ];
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
