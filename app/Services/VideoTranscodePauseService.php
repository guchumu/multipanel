<?php

declare(strict_types=1);

namespace App\Services;

use Core\Cache;
use Core\Logger;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pausa temporal del auto-corte de vídeo Transcode (solo ese corte; no límites de streams).
 * Clave por media_user_id o, si no hay match, por servidor + user_id externo / username.
 */
final class VideoTranscodePauseService
{
    public const DURATION_1H = '1h';

    public const DURATION_3H = '3h';

    public const DURATION_5H = '5h';

    public const DURATION_EOD = 'eod';

    /** @var list<string> */
    public const DURATIONS = [
        self::DURATION_1H,
        self::DURATION_3H,
        self::DURATION_5H,
        self::DURATION_EOD,
    ];

    /** ntfy admite como máximo 3 Actions; el resto va en el cuerpo. */
    /** @var list<string> */
    public const NTFY_ACTION_DURATIONS = [
        self::DURATION_1H,
        self::DURATION_3H,
        self::DURATION_EOD,
    ];

    private const TOKEN_TTL_SECONDS = 900;

    private const CACHE_PREFIX = 'vtrans_pause:';

    /**
     * @param array<string, mixed> $session
     */
    public function isPaused(int $tenantId, array $session): bool
    {
        foreach ($this->cacheKeysForSession($tenantId, $session) as $key) {
            $until = Cache::get($key);
            if (is_int($until) && $until > time()) {
                return true;
            }
            if (is_array($until) && isset($until['until']) && (int) $until['until'] > time()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *   tenant_id: int,
     *   media_user_id?: int,
     *   server_id?: int,
     *   user_id?: string,
     *   username?: string,
     *   duration: string
     * } $target
     * @return array{ok: bool, until?: int, until_label?: string, duration?: string, error?: string}
     */
    public function pause(array $target): array
    {
        $tenantId = (int) ($target['tenant_id'] ?? 0);
        $duration = strtolower(trim((string) ($target['duration'] ?? '')));
        if ($tenantId <= 0 || !in_array($duration, self::DURATIONS, true)) {
            return ['ok' => false, 'error' => 'Datos de pausa no válidos.'];
        }

        $until = $this->expiresAt($duration);
        $ttl = max(60, $until - time());
        $payload = [
            'until' => $until,
            'duration' => $duration,
            'username' => trim((string) ($target['username'] ?? '')),
            'paused_at' => time(),
        ];

        $keys = $this->cacheKeysForTarget($tenantId, $target);
        if ($keys === []) {
            return ['ok' => false, 'error' => 'No se pudo identificar al usuario de medios.'];
        }

        foreach ($keys as $key) {
            Cache::set($key, $payload, $ttl);
        }

        return [
            'ok' => true,
            'until' => $until,
            'until_label' => $this->formatUntilLabel($until),
            'duration' => $duration,
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, string> duration => absolute URL
     */
    public function buildPauseUrls(int $tenantId, array $session): array
    {
        $base = $this->publicBaseUrl();
        if ($base === null) {
            return [];
        }

        $shortener = new TranscodeActionLinkService();
        $urls = [];
        foreach (self::DURATIONS as $duration) {
            $payload = [
                'tenant_id' => $tenantId,
                'media_user_id' => (int) ($session['media_user_id'] ?? 0),
                'server_id' => (int) ($session['server_id'] ?? 0),
                'user_id' => trim((string) ($session['user_id'] ?? '')),
                'username' => trim((string) ($session['user'] ?? '')),
                'duration' => $duration,
            ];
            $short = $shortener->createPauseUrl($payload);
            if ($short !== null) {
                $urls[$duration] = $short;
                continue;
            }
            // Fallback: token largo firmado (si falla el acortador).
            $token = $this->createToken($payload);
            if ($token === null) {
                continue;
            }
            $urls[$duration] = $base . '/activity/transcode-pause/' . rawurlencode($token);
        }

        return $urls;
    }

    /**
     * @param array{
     *   tenant_id: int,
     *   media_user_id?: int,
     *   server_id?: int,
     *   user_id?: string,
     *   username?: string,
     *   duration: string
     * } $payload
     */
    public function createToken(array $payload): ?string
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $duration = strtolower(trim((string) ($payload['duration'] ?? '')));
        if ($tenantId <= 0 || !in_array($duration, self::DURATIONS, true)) {
            return null;
        }

        $mediaUserId = max(0, (int) ($payload['media_user_id'] ?? 0));
        $serverId = max(0, (int) ($payload['server_id'] ?? 0));
        $userId = trim((string) ($payload['user_id'] ?? ''));
        $username = trim((string) ($payload['username'] ?? ''));
        if ($mediaUserId <= 0 && $serverId <= 0 && $userId === '' && $username === '') {
            return null;
        }

        $body = [
            't' => $tenantId,
            'm' => $mediaUserId,
            's' => $serverId,
            'u' => $userId,
            'n' => $username,
            'd' => $duration,
            'e' => time() + self::TOKEN_TTL_SECONDS,
        ];

        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $encoded = $this->b64url($json);
        $sig = $this->b64url(hash_hmac('sha256', $encoded, $this->signingKey(), true));

        return $encoded . '.' . $sig;
    }

    /**
     * @return array{ok: bool, until?: int, until_label?: string, duration?: string, username?: string, error?: string}
     */
    public function consumeToken(string $token): array
    {
        $token = trim($token);
        if ($token === '' || !str_contains($token, '.')) {
            return ['ok' => false, 'error' => 'Enlace no válido.'];
        }

        [$encoded, $sig] = explode('.', $token, 2);
        $expected = $this->b64url(hash_hmac('sha256', $encoded, $this->signingKey(), true));
        if ($encoded === '' || $sig === '' || !hash_equals($expected, $sig)) {
            return ['ok' => false, 'error' => 'Firma no válida.'];
        }

        $json = $this->b64urlDecode($encoded);
        if ($json === null) {
            return ['ok' => false, 'error' => 'Enlace no válido.'];
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Enlace no válido.'];
        }

        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Enlace no válido.'];
        }

        $exp = (int) ($data['e'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            return ['ok' => false, 'error' => 'Este enlace ha caducado.'];
        }

        $result = $this->pause([
            'tenant_id' => (int) ($data['t'] ?? 0),
            'media_user_id' => (int) ($data['m'] ?? 0),
            'server_id' => (int) ($data['s'] ?? 0),
            'user_id' => (string) ($data['u'] ?? ''),
            'username' => (string) ($data['n'] ?? ''),
            'duration' => (string) ($data['d'] ?? ''),
        ]);

        if (!$result['ok']) {
            return $result;
        }

        $result['username'] = trim((string) ($data['n'] ?? ''));

        return $result;
    }

    public function durationLabel(string $duration): string
    {
        return match ($duration) {
            self::DURATION_1H => 'Saltar 1 hora',
            self::DURATION_3H => 'Saltar 3 horas',
            self::DURATION_5H => 'Saltar 5 horas',
            self::DURATION_EOD => 'Saltar hasta medianoche',
            default => $duration,
        };
    }

    public function ntfyActionLabel(string $duration): string
    {
        return match ($duration) {
            self::DURATION_1H => 'Saltar 1h',
            self::DURATION_3H => 'Saltar 3h',
            self::DURATION_5H => 'Saltar 5h',
            self::DURATION_EOD => 'Saltar hoy',
            default => 'Saltar',
        };
    }

    /** Etiqueta corta para el cuerpo del aviso (ntfy/WhatsApp). */
    public function shortLinkLabel(string $duration): string
    {
        return match ($duration) {
            self::DURATION_1H => '1h',
            self::DURATION_3H => '3h',
            self::DURATION_5H => '5h',
            self::DURATION_EOD => 'hoy',
            default => $duration,
        };
    }

    public function expiresAt(string $duration): int
    {
        $tz = $this->appTimezone();
        $now = new DateTimeImmutable('now', $tz);

        return match ($duration) {
            self::DURATION_1H => $now->modify('+1 hour')->getTimestamp(),
            self::DURATION_3H => $now->modify('+3 hours')->getTimestamp(),
            self::DURATION_5H => $now->modify('+5 hours')->getTimestamp(),
            self::DURATION_EOD => $now->modify('tomorrow')->setTime(0, 0, 0)->getTimestamp(),
            default => $now->modify('+1 hour')->getTimestamp(),
        };
    }

    public function formatUntilLabel(int $untilTs): string
    {
        try {
            $dt = (new DateTimeImmutable('@' . $untilTs))->setTimezone($this->appTimezone());
        } catch (\Throwable) {
            return date('Y-m-d H:i', $untilTs);
        }

        return $dt->format('d/m/Y H:i') . ' (' . $this->appTimezone()->getName() . ')';
    }

    private function appTimezone(): DateTimeZone
    {
        $name = (string) config('app.timezone', 'Europe/Madrid');
        try {
            return new DateTimeZone($name !== '' ? $name : 'Europe/Madrid');
        } catch (\Throwable) {
            return new DateTimeZone('Europe/Madrid');
        }
    }

    /**
     * @param array<string, mixed> $session
     * @return list<string>
     */
    private function cacheKeysForSession(int $tenantId, array $session): array
    {
        return $this->cacheKeysForTarget($tenantId, [
            'media_user_id' => (int) ($session['media_user_id'] ?? 0),
            'server_id' => (int) ($session['server_id'] ?? 0),
            'user_id' => trim((string) ($session['user_id'] ?? '')),
            'username' => trim((string) ($session['user'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $target
     * @return list<string>
     */
    private function cacheKeysForTarget(int $tenantId, array $target): array
    {
        $keys = [];
        $mediaUserId = (int) ($target['media_user_id'] ?? 0);
        if ($mediaUserId > 0) {
            $keys[] = self::CACHE_PREFIX . $tenantId . ':mu:' . $mediaUserId;
        }

        $serverId = (int) ($target['server_id'] ?? 0);
        $userId = trim((string) ($target['user_id'] ?? ''));
        if ($serverId > 0 && $userId !== '') {
            $keys[] = self::CACHE_PREFIX . $tenantId . ':ext:' . $serverId . ':' . sha1($userId);
        }

        $username = mb_strtolower(trim((string) ($target['username'] ?? '')));
        if ($serverId > 0 && $username !== '') {
            $keys[] = self::CACHE_PREFIX . $tenantId . ':name:' . $serverId . ':' . sha1($username);
        }

        return array_values(array_unique($keys));
    }

    private function signingKey(): string
    {
        $key = (string) (env('APP_KEY', '') ?: config('app.key', ''));
        if ($key === '') {
            Logger::warning('VideoTranscodePauseService: APP_KEY vacío');
            $key = 'multipanel-vtrans-pause';
        }

        return $key;
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

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
