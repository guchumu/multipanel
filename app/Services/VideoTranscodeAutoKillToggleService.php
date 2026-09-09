<?php

declare(strict_types=1);

namespace App\Services;

use Core\Logger;

/**
 * Enlaces públicos firmados para activar/apagar el auto-corte de vídeo Transcode (tenant).
 */
final class VideoTranscodeAutoKillToggleService
{
    public const ACTION_ENABLE = 'enable';

    public const ACTION_DISABLE = 'disable';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_ENABLE,
        self::ACTION_DISABLE,
    ];

    /**
     * @return array{enable?: string, disable?: string}
     */
    public function buildToggleUrls(int $tenantId): array
    {
        $base = $this->publicBaseUrl();
        if ($base === null || $tenantId <= 0) {
            return [];
        }

        $shortener = new TranscodeActionLinkService();
        $urls = [];
        foreach (self::ACTIONS as $action) {
            $payload = [
                'tenant_id' => $tenantId,
                'action' => $action,
            ];
            $short = $shortener->createToggleUrl($payload);
            if ($short !== null) {
                $urls[$action] = $short;
                continue;
            }
            $token = $this->createToken($payload);
            if ($token === null) {
                continue;
            }
            $urls[$action] = $base . '/activity/auto-kill-video-transcodes/' . rawurlencode($token);
        }

        return $urls;
    }

    /**
     * @param array{tenant_id: int, action: string} $payload
     */
    public function createToken(array $payload): ?string
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        if ($tenantId <= 0 || !in_array($action, self::ACTIONS, true)) {
            return null;
        }

        $body = [
            't' => $tenantId,
            'a' => $action,
            'e' => time() + (new VideoTranscodePauseService())->linkTtlSeconds(),
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
     * @return array{ok: bool, enabled?: bool, action?: string, error?: string}
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

        $tenantId = (int) ($data['t'] ?? 0);
        $action = strtolower(trim((string) ($data['a'] ?? '')));
        if ($tenantId <= 0 || !in_array($action, self::ACTIONS, true)) {
            return ['ok' => false, 'error' => 'Datos no válidos.'];
        }

        $enabled = $action === self::ACTION_ENABLE;
        $settings = new StreamLimitSettingsService();
        try {
            $settings->setAutoKillVideoTranscodesEnabled($tenantId, $enabled);
        } catch (\Throwable $e) {
            Logger::warning('VideoTranscodeAutoKillToggleService: no se pudo guardar', [
                'tenant_id' => $tenantId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'No se pudo guardar el auto-corte.'];
        }

        $persisted = $settings->isAutoKillVideoTranscodesEnabled($tenantId);
        if ($persisted !== $enabled) {
            return ['ok' => false, 'error' => 'El valor no se persistió correctamente.'];
        }

        return [
            'ok' => true,
            'enabled' => $persisted,
            'action' => $action,
        ];
    }

    public function actionLabel(string $action): string
    {
        return match ($action) {
            self::ACTION_ENABLE => 'Activar',
            self::ACTION_DISABLE => 'Apagar',
            default => $action,
        };
    }

    public function ntfyActionLabel(string $action): string
    {
        return match ($action) {
            self::ACTION_ENABLE => 'Activar auto-corte',
            self::ACTION_DISABLE => 'Apagar auto-corte',
            default => 'Auto-corte',
        };
    }

    public function stateLabel(bool $enabled): string
    {
        return $enabled ? 'ON' : 'OFF';
    }

    private function signingKey(): string
    {
        $key = (string) (env('APP_KEY', '') ?: config('app.key', ''));
        if ($key === '') {
            Logger::warning('VideoTranscodeAutoKillToggleService: APP_KEY vacío');
            $key = 'multipanel-vtrans-autokill';
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
