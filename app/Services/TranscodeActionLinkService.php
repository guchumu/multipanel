<?php

declare(strict_types=1);

namespace App\Services;

use Core\Cache;
use Core\Logger;

/**
 * Enlaces públicos cortos para acciones Transcode desde ntfy/WhatsApp.
 * GET /t/{code} → pausa auto-corte o activar/apagar auto-corte.
 */
final class TranscodeActionLinkService
{
    public const KIND_PAUSE = 'pause';

    public const KIND_TOGGLE = 'toggle';

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private const CODE_LENGTH = 10;

    private const CACHE_PREFIX = 'vtrans_tlink:';

    private const DEFAULT_TTL_SECONDS = 900;

    /** Máximo TTL de enlace corto (p. ej. pausa «hoy» hasta medianoche). */
    private const MAX_TTL_SECONDS = 172800;

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
    public function createPauseUrl(array $payload): ?string
    {
        $ttl = (new VideoTranscodePauseService())->linkTtlSeconds();

        return $this->create(self::KIND_PAUSE, $payload, $ttl);
    }

    /**
     * @param array{tenant_id: int, action: string} $payload
     */
    public function createToggleUrl(array $payload): ?string
    {
        $ttl = (new VideoTranscodePauseService())->linkTtlSeconds();

        return $this->create(self::KIND_TOGGLE, $payload, $ttl);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(string $kind, array $payload, ?int $ttlSeconds = null): ?string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, [self::KIND_PAUSE, self::KIND_TOGGLE], true)) {
            return null;
        }

        $base = $this->publicBaseUrl();
        if ($base === null) {
            return null;
        }

        $ttl = max(60, min(self::MAX_TTL_SECONDS, $ttlSeconds ?? self::DEFAULT_TTL_SECONDS));
        $code = $this->allocateCode($ttl);
        if ($code === null) {
            return null;
        }

        Cache::set(self::CACHE_PREFIX . $code, [
            'kind' => $kind,
            'payload' => $payload,
            'created_at' => time(),
        ], $ttl);

        return $base . '/t/' . $code;
    }

    /**
     * @return array{
     *   ok: bool,
     *   kind?: string,
     *   error?: string,
     *   until?: int,
     *   until_label?: string,
     *   duration?: string,
     *   username?: string,
     *   enabled?: bool,
     *   action?: string
     * }
     */
    public function consume(string $code): array
    {
        $code = trim($code);
        if (!$this->isValidCode($code)) {
            return ['ok' => false, 'error' => 'Enlace no válido.'];
        }

        $key = self::CACHE_PREFIX . $code;
        $stored = Cache::get($key);

        if (!is_array($stored) || !isset($stored['kind'], $stored['payload']) || !is_array($stored['payload'])) {
            return ['ok' => false, 'error' => 'Este enlace no existe o ha caducado.'];
        }

        $kind = strtolower(trim((string) $stored['kind']));
        $payload = $stored['payload'];

        // Pausa y ON/OFF: reutilizables todo el día (mismo TTL de caché).

        if ($kind === self::KIND_PAUSE) {
            $result = (new VideoTranscodePauseService())->pause($payload);
            if (empty($result['ok'])) {
                return [
                    'ok' => false,
                    'kind' => $kind,
                    'error' => (string) ($result['error'] ?? 'No se pudo aplicar la pausa.'),
                ];
            }

            return [
                'ok' => true,
                'kind' => $kind,
                'until' => $result['until'] ?? null,
                'until_label' => $result['until_label'] ?? null,
                'duration' => $result['duration'] ?? null,
                'username' => trim((string) ($payload['username'] ?? '')),
            ];
        }

        if ($kind === self::KIND_TOGGLE) {
            $tenantId = (int) ($payload['tenant_id'] ?? 0);
            $action = strtolower(trim((string) ($payload['action'] ?? '')));
            if ($tenantId <= 0 || !in_array($action, VideoTranscodeAutoKillToggleService::ACTIONS, true)) {
                return ['ok' => false, 'kind' => $kind, 'error' => 'Datos no válidos.'];
            }

            $enabled = $action === VideoTranscodeAutoKillToggleService::ACTION_ENABLE;
            $settings = new StreamLimitSettingsService();
            try {
                $settings->setAutoKillVideoTranscodesEnabled($tenantId, $enabled);
            } catch (\Throwable $e) {
                Logger::warning('TranscodeActionLinkService: no se pudo guardar auto-corte', [
                    'tenant_id' => $tenantId,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);

                return ['ok' => false, 'kind' => $kind, 'error' => 'No se pudo guardar el auto-corte.'];
            }

            $persisted = $settings->isAutoKillVideoTranscodesEnabled($tenantId);
            if ($persisted !== $enabled) {
                return ['ok' => false, 'kind' => $kind, 'error' => 'El valor no se persistió correctamente.'];
            }

            return [
                'ok' => true,
                'kind' => $kind,
                'enabled' => $persisted,
                'action' => $action,
            ];
        }

        return ['ok' => false, 'error' => 'Enlace no válido.'];
    }

    public function isValidCode(string $code): bool
    {
        $code = trim($code);
        if (strlen($code) !== self::CODE_LENGTH) {
            return false;
        }

        return (bool) preg_match('/^[' . preg_quote(self::CODE_ALPHABET, '/') . ']+$/', $code);
    }

    private function allocateCode(int $ttl): ?string
    {
        for ($i = 0; $i < 12; $i++) {
            $code = $this->randomCode();
            $key = self::CACHE_PREFIX . $code;
            if (Cache::get($key) === null) {
                return $code;
            }
        }

        Logger::warning('TranscodeActionLinkService: no se pudo asignar código único');

        return null;
    }

    private function randomCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $out;
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
