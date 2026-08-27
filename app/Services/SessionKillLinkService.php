<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Services\Media\JellyfinService;
use App\Services\Media\MediaServerFactory;
use App\Services\Media\PlexService;
use Core\Cache;
use Core\Database;
use Core\Logger;
use Core\Updater;

/**
 * Enlaces públicos de un solo uso: GET /k/{code} corta una reproducción con mensaje predefinido.
 */
final class SessionKillLinkService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private const CODE_LENGTH = 14;

    public const DEFAULT_TTL_MINUTES = 120;

    private bool $tableEnsured = false;

    /**
     * @return array{success: bool, short_url?: string, code?: string, message?: string}
     */
    public function create(
        int $tenantId,
        int $serverId,
        string $sessionId,
        string $killMessage,
        string $reasonKey = '',
        ?int $ttlMinutes = null,
    ): array {
        $sessionId = trim($sessionId);
        $killMessage = trim($killMessage);
        if ($tenantId <= 0 || $serverId <= 0 || $sessionId === '' || $killMessage === '') {
            return ['success' => false, 'message' => 'Datos de sesión incompletos.'];
        }

        $base = $this->publicBaseUrl();
        if ($base === null) {
            return ['success' => false, 'message' => 'APP_URL no es una URL pública válida.'];
        }

        $server = Server::find($serverId);
        if ($server === null || (int) $server->tenant_id !== $tenantId) {
            return ['success' => false, 'message' => 'Servidor no encontrado.'];
        }

        $this->ensureTable();
        $ttlMinutes = max(5, min(720, $ttlMinutes ?? self::DEFAULT_TTL_MINUTES));
        $expires = (new \DateTimeImmutable('now'))->modify('+' . $ttlMinutes . ' minutes');

        try {
            $code = $this->allocateUniqueCode();
            Database::getInstance()->insert('session_kill_links', [
                'tenant_id' => $tenantId,
                'server_id' => $serverId,
                'session_id' => $sessionId,
                'code' => $code,
                'reason_key' => trim($reasonKey),
                'kill_message' => mb_substr($killMessage, 0, 500),
                'expires_at' => $expires->format('Y-m-d H:i:s'),
            ]);

            return [
                'success' => true,
                'code' => $code,
                'short_url' => $base . '/k/' . $code,
            ];
        } catch (\Throwable $e) {
            Logger::warning('Session kill link create failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'No se pudo crear el enlace de corte.'];
        }
    }

    /**
     * @return array{ok: bool, killed: bool, title?: string, error?: string}
     */
    public function consume(string $code): array
    {
        $code = trim($code);
        if (!self::isValidCode($code)) {
            return ['ok' => false, 'killed' => false, 'error' => 'Enlace no válido.'];
        }

        $this->ensureTable();
        $db = Database::getInstance();

        try {
            $row = $db->fetchOne(
                'SELECT * FROM session_kill_links WHERE code = ? LIMIT 1',
                [$code]
            );
        } catch (\Throwable $e) {
            Logger::warning('Session kill link resolve failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'killed' => false, 'error' => 'No se pudo procesar el enlace.'];
        }

        if ($row === null) {
            return ['ok' => false, 'killed' => false, 'error' => 'Este enlace no existe.'];
        }

        if (!empty($row['used_at'])) {
            return ['ok' => false, 'killed' => false, 'error' => 'Este enlace ya se usó.'];
        }

        $expiresTs = strtotime((string) ($row['expires_at'] ?? ''));
        if ($expiresTs !== false && $expiresTs < time()) {
            return ['ok' => false, 'killed' => false, 'error' => 'Este enlace ha caducado.'];
        }

        $tenantId = (int) ($row['tenant_id'] ?? 0);
        $serverId = (int) ($row['server_id'] ?? 0);
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        $message = trim((string) ($row['kill_message'] ?? ''));

        $server = Server::find($serverId);
        if ($server === null || (int) $server->tenant_id !== $tenantId || $sessionId === '') {
            return ['ok' => false, 'killed' => false, 'error' => 'La sesión ya no está disponible.'];
        }

        $killed = $this->terminateSession($server, $sessionId, $message);

        try {
            $db->update('session_kill_links', ['used_at' => now()->format('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
        } catch (\Throwable) {
        }

        if (!$killed) {
            return [
                'ok' => true,
                'killed' => false,
                'error' => 'No había reproducción activa o no se pudo cortar (puede que ya terminara).',
            ];
        }

        return ['ok' => true, 'killed' => true, 'title' => 'Reproducción cortada'];
    }

    public static function isValidCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{10,32}$/', $code);
    }

    private function terminateSession(Server $server, string $sessionId, string $reason): bool
    {
        try {
            $media = MediaServerFactory::make($server);
            if (!($media instanceof PlexService || $media instanceof JellyfinService)) {
                return false;
            }

            $ok = $media->terminateSession($sessionId, $reason);
            if ($ok) {
                Cache::forget('activity_snapshot_' . (int) $server->tenant_id);
            }

            return $ok;
        } catch (\Throwable $e) {
            Logger::warning('Session kill link terminate failed', [
                'server_id' => (int) $server->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function allocateUniqueCode(): string
    {
        $db = Database::getInstance();
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            $existing = $db->fetchOne('SELECT id FROM session_kill_links WHERE code = ? LIMIT 1', [$code]);
            if ($existing === null) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not allocate unique session kill link code');
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        try {
            (new Updater())->runMigrations();
        } catch (\Throwable $e) {
            Logger::warning('Session kill links migration ensure failed', ['error' => $e->getMessage()]);
        }

        $this->tableEnsured = true;
    }

    private function publicBaseUrl(): ?string
    {
        $configured = rtrim((string) config('app.url', env('APP_URL', '')), '/');
        $configuredLooksLocal = $configured === ''
            || str_contains($configured, 'localhost')
            || str_contains($configured, '127.0.0.1');

        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $host = trim(explode(',', $host)[0]);

        if ($host !== '' && $configuredLooksLocal) {
            $proto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
            $https = strtolower($proto) === 'https'
                || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

            return ($https ? 'https' : 'http') . '://' . $host;
        }

        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return $configured;
        }

        return null;
    }
}
