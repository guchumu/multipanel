<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Session;

/**
 * SMTP desde settings (grupo smtp) con fallback a config/mail.php / .env.
 */
final class MailConfig
{
    /**
     * @return array{
     *   host: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   from_address: string,
     *   from_name: string
     * }
     */
    public static function forTenant(?int $tenantId = null): array
    {
        $tenantId ??= (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $stored = self::loadGroup($tenantId, 'smtp');

        $host = trim((string) ($stored['mail_host'] ?? ''));
        if ($host === '') {
            $host = trim((string) config('mail.host', ''));
        }

        $port = (int) ($stored['mail_port'] ?? 0);
        if ($port <= 0) {
            $port = (int) config('mail.port', 587);
        }

        $username = trim((string) ($stored['mail_username'] ?? ''));
        if ($username === '') {
            $username = trim((string) config('mail.username', ''));
        }

        $password = (string) ($stored['mail_password'] ?? '');
        if (trim($password) === '') {
            $password = (string) config('mail.password', '');
        }

        $fromAddress = trim((string) ($stored['mail_from'] ?? ''));
        if ($fromAddress === '') {
            $fromAddress = trim((string) config('mail.from.address', ''));
        }

        $fromName = trim((string) config('mail.from.name', 'MultiPanel'));
        $encryption = trim((string) config('mail.encryption', 'tls')) ?: 'tls';

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $encryption,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
        ];
    }

    /** @return array<string, string> */
    private static function loadGroup(int $tenantId, string $group): array
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT `key`, `value` FROM settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND `group` = ?',
                [$tenantId, $group]
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        return $out;
    }
}
