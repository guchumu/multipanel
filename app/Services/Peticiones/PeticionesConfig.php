<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

use App\Services\SecretCrypt;
use Core\Database;
use Core\Session;

/**
 * Resuelve credenciales de la BD remota de peticiones.
 * Prioridad: settings del tenant → .env / config/peticiones.php.
 */
final class PeticionesConfig
{
    /**
     * @return array{
     *   host: string,
     *   port: int,
     *   database: string,
     *   username: string,
     *   password: string,
     *   charset: string,
     *   tmdb_api_key: string,
     *   scraper_api_key: string,
     *   configured: bool,
     *   password_set: bool
     * }
     */
    public static function forTenant(?int $tenantId = null): array
    {
        $tenantId ??= (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $stored = self::loadGroup($tenantId);

        $crypt = new SecretCrypt();
        $passwordRaw = (string) ($stored['peticiones_db_password'] ?? '');
        $password = $passwordRaw !== '' ? (string) ($crypt->decrypt($passwordRaw) ?? '') : '';
        if ($password === '') {
            $password = (string) config('peticiones.password', '');
        }

        $host = trim((string) ($stored['peticiones_db_host'] ?? '')) ?: (string) config('peticiones.host', '');
        $port = (int) ($stored['peticiones_db_port'] ?? 0);
        if ($port <= 0) {
            $port = (int) config('peticiones.port', 3306);
        }
        $database = trim((string) ($stored['peticiones_db_database'] ?? '')) ?: (string) config('peticiones.database', '');
        $username = trim((string) ($stored['peticiones_db_username'] ?? '')) ?: (string) config('peticiones.username', '');
        // Siempre utf8mb4 para tildes/ñ (ignora valores legacy latin1/utf8 en settings/.env).
        $charset = 'utf8mb4';

        $tmdb = trim((string) ($stored['peticiones_tmdb_api_key'] ?? ''));
        if ($tmdb === '') {
            $tmdb = (string) config('peticiones.tmdb_api_key', '');
        } else {
            $tmdb = (string) ($crypt->decrypt($tmdb) ?? $tmdb);
        }

        $scraper = trim((string) ($stored['peticiones_scraper_api_key'] ?? ''));
        if ($scraper === '') {
            $scraper = (string) config('peticiones.scraper_api_key', '');
        } else {
            $scraper = (string) ($crypt->decrypt($scraper) ?? $scraper);
        }

        $configured = $host !== '' && $database !== '' && $username !== '' && $password !== '';

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
            'tmdb_api_key' => $tmdb,
            'scraper_api_key' => $scraper,
            'configured' => $configured,
            'password_set' => $password !== '',
        ];
    }

    /**
     * Vista segura para el formulario de ajustes (sin password en claro).
     *
     * @return array<string, mixed>
     */
    public static function forSettingsUi(?int $tenantId = null): array
    {
        $cfg = self::forTenant($tenantId);

        return [
            'peticiones_db_host' => $cfg['host'],
            'peticiones_db_port' => (string) $cfg['port'],
            'peticiones_db_database' => $cfg['database'],
            'peticiones_db_username' => $cfg['username'],
            'peticiones_db_password_set' => $cfg['password_set'],
            'peticiones_tmdb_api_key_set' => $cfg['tmdb_api_key'] !== '',
            'peticiones_scraper_api_key_set' => $cfg['scraper_api_key'] !== '',
        ];
    }

    /**
     * Guarda ajustes; password / API keys vacíos no se sobrescriben.
     *
     * @param array<string, mixed> $input
     */
    public static function save(int $tenantId, array $input): void
    {
        $crypt = new SecretCrypt();
        $map = [
            'peticiones_db_host' => 'string',
            'peticiones_db_port' => 'string',
            'peticiones_db_database' => 'string',
            'peticiones_db_username' => 'string',
        ];

        foreach ($map as $key => $_type) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = trim((string) $input[$key]);
            self::upsert($tenantId, $key, $value, 'string');
        }

        foreach (['peticiones_db_password', 'peticiones_tmdb_api_key', 'peticiones_scraper_api_key'] as $secretKey) {
            if (!array_key_exists($secretKey, $input)) {
                continue;
            }
            $value = trim((string) $input[$secretKey]);
            if ($value === '') {
                continue; // vacío = no cambiar
            }
            self::upsert($tenantId, $secretKey, $crypt->encrypt($value), 'encrypted');
        }
    }

    /** @return array<string, string> */
    private static function loadGroup(int $tenantId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT `key`, `value` FROM settings WHERE (tenant_id = ? OR tenant_id IS NULL) AND `group` = ?',
            [$tenantId, 'peticiones']
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        return $out;
    }

    private static function upsert(int $tenantId, string $key, string $value, string $type): void
    {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE tenant_id = ? AND `group` = ? AND `key` = ?',
            [$tenantId, 'peticiones', $key]
        );

        if ($existing) {
            $db->update('settings', ['value' => $value, 'type' => $type], 'id = ?', [$existing['id']]);
        } else {
            $db->insert('settings', [
                'tenant_id' => $tenantId,
                'group' => 'peticiones',
                'key' => $key,
                'value' => $value,
                'type' => $type,
            ]);
        }
    }
}
