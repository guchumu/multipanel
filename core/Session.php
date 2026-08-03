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
        $secure = config('session.secure', false);

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        $this->started = true;

        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
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
        return hash_equals($this->getCsrfToken(), $token ?? '');
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
