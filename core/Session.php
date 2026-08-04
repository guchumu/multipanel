<?php

declare(strict_types=1);

namespace Core;

/**
 * Session management with CSRF protection.
 */
final class Session
{
    private static ?self $instance = null;

    private bool $started = false;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $lifetime = config('session.lifetime', 120) * 60;
        $secure = $this->shouldUseSecureCookie();

        // Cookie de sesión (lifetime 0): se renueva mientras el navegador esté
        // abierto. Antes se usaba SESSION_LIFETIME como caducidad ABSOLUTA de la
        // cookie (p. ej. 2h desde el login), y al expirar el POST llegaba con
        // una sesión nueva → CSRF inválido aunque el meta token siguiera en la página.
        ini_set('session.gc_maxlifetime', (string) max(60, $lifetime));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        $this->started = true;

        if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token']) || $_SESSION['_csrf_token'] === '') {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    private function shouldUseSecureCookie(): bool
    {
        // Solo Secure si la petición es realmente HTTPS. Si .env fuerza
        // SESSION_SECURE=true pero entras por HTTP, la cookie no se envía y
        // cada POST genera sesión nueva → "Token CSRF inválido".
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public function getCsrfToken(): string
    {
        return $_SESSION['_csrf_token'] ?? '';
    }

    public function validateCsrf(?string $token): bool
    {
        $expected = $this->getCsrfToken();
        $provided = is_string($token) ? trim($token) : '';

        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Libera el lock del archivo de sesión sin terminar la petición. PHP bloquea
     * la sesión por archivo durante todo el request: cualquier endpoint largo
     * (SSE, long-poll, proxy de carátulas) que no lo suelte deja en cola al
     * resto de peticiones del mismo navegador, que parecen "no cargar nunca".
     * Tras llamar a close(), $_SESSION sigue siendo legible pero los cambios
     * ya no se persisten: úsalo solo en endpoints de lectura.
     */
    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->started = false;
    }

    public function destroy(): void
    {
        session_destroy();
        $this->started = false;
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }
}
